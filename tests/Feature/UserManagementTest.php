<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Bitacora;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();

        if (
            $connection->getDriverName() !== 'sqlite'
            || $connection->getDatabaseName() !== ':memory:'
        ) {
            throw new RuntimeException(
                'Las pruebas de usuarios solo pueden ejecutarse con SQLite en memoria.'
            );
        }

        Schema::dropIfExists('bitacoras');
        Schema::dropIfExists('usuarios');

        Schema::create('usuarios', function (Blueprint $table): void {
            $table->id('id_usuario');
            $table->string('nombre_completo', 100);
            $table->string('nombre_usuario', 50)->unique();
            $table->string('contrasena_hash');
            $table->string('rol', 30);
            $table->string('estado', 20)->default('ACTIVO');
            $table->unsignedSmallInteger('intentos_fallidos')->default(0);
            $table->timestamp('bloqueado_hasta')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('bitacoras', function (Blueprint $table): void {
            $table->id('id_bitacora');
            $table->foreignId('id_usuario')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('descripcion_movimiento', 255);
            $table->string('tipo_movimiento', 50);
            $table->string('motivo', 255);
            $table->timestamp('fecha')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bitacoras');
        Schema::dropIfExists('usuarios');

        parent::tearDown();
    }

    public function test_login_failures_share_status_and_body_without_losing_internal_audits(): void
    {
        $wrongPasswordUser = User::factory()->create([
            'nombre_usuario' => 'CLAVE.INCORRECTA',
        ]);
        $inactiveUser = User::factory()->inactive()->create([
            'nombre_usuario' => 'CUENTA.INACTIVA',
        ]);
        $blockedUser = User::factory()->create([
            'nombre_usuario' => 'CUENTA.BLOQUEADA',
            'intentos_fallidos' => 5,
            'bloqueado_hasta' => now()->addMinutes(5),
        ]);

        $missingResponse = $this->postJson('/api/login', [
            'username' => 'NO.EXISTE',
            'password' => 'Password123',
        ]);
        $wrongPasswordResponse = $this->postJson('/api/login', [
            'username' => 'CLAVE.INCORRECTA',
            'password' => 'WrongPassword456',
        ]);
        $inactiveResponse = $this->postJson('/api/login', [
            'username' => 'CUENTA.INACTIVA',
            'password' => 'Password123',
        ]);
        $blockedResponse = $this->postJson('/api/login', [
            'username' => 'CUENTA.BLOQUEADA',
            'password' => 'Password123',
        ]);

        foreach ([$missingResponse, $wrongPasswordResponse, $inactiveResponse, $blockedResponse] as $response) {
            $response
                ->assertUnauthorized()
                ->assertExactJson([
                    'message' => "No fue posible iniciar sesión.\nVerifica tus credenciales.",
                ]);

            $this->assertSame($missingResponse->status(), $response->status());
            $this->assertSame($missingResponse->json(), $response->json());
        }

        $this->assertSame(1, $wrongPasswordUser->fresh()->intentos_fallidos);
        $this->assertSame(0, $inactiveUser->fresh()->intentos_fallidos);
        $this->assertSame(5, $blockedUser->fresh()->intentos_fallidos);
        $this->assertDatabaseCount('bitacoras', 3);
        $this->assertDatabaseHas('bitacoras', [
            'id_usuario' => $wrongPasswordUser->id_usuario,
            'descripcion_movimiento' => 'Intento de inicio de sesión fallido.',
        ]);
        $this->assertDatabaseHas('bitacoras', [
            'id_usuario' => $inactiveUser->id_usuario,
            'descripcion_movimiento' => 'Intento de acceso de un usuario inactivo.',
        ]);
        $this->assertDatabaseHas('bitacoras', [
            'id_usuario' => $blockedUser->id_usuario,
            'descripcion_movimiento' => 'Intento de acceso mientras la cuenta estaba bloqueada.',
        ]);
    }

    public function test_login_still_blocks_after_five_failures_without_exposing_lock_details(): void
    {
        $user = User::factory()->create([
            'nombre_usuario' => 'QUINTO.INTENTO',
            'intentos_fallidos' => 4,
        ]);

        $referenceResponse = $this->postJson('/api/login', [
            'username' => 'NO.EXISTE',
            'password' => 'Password123',
        ]);
        $fifthAttemptResponse = $this->postJson('/api/login', [
            'username' => 'QUINTO.INTENTO',
            'password' => 'WrongPassword456',
        ]);
        $blockedResponse = $this->postJson('/api/login', [
            'username' => 'QUINTO.INTENTO',
            'password' => 'Password123',
        ]);

        foreach ([$fifthAttemptResponse, $blockedResponse] as $response) {
            $response->assertUnauthorized();
            $this->assertSame($referenceResponse->status(), $response->status());
            $this->assertSame($referenceResponse->json(), $response->json());
        }

        $user->refresh();
        $this->assertSame(5, $user->intentos_fallidos);
        $this->assertTrue($user->bloqueado_hasta->isFuture());
        $this->assertDatabaseCount('bitacoras', 2);
        $this->assertDatabaseHas('bitacoras', [
            'id_usuario' => $user->id_usuario,
            'descripcion_movimiento' => 'Cuenta bloqueada por intentos fallidos.',
        ]);
    }

    public function test_successful_login_keeps_session_response_and_resets_failed_attempts(): void
    {
        $user = User::factory()->create([
            'nombre_usuario' => 'INGRESO.CORRECTO',
            'intentos_fallidos' => 2,
        ]);

        config()->set('sanctum.stateful', ['localhost']);

        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ])->postJson('/api/login', [
            'username' => 'ingreso.correcto',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.id_usuario', $user->id_usuario)
            ->assertJsonPath('usuario.nombre_usuario', 'INGRESO.CORRECTO');

        $user->refresh();
        $this->assertSame(0, $user->intentos_fallidos);
        $this->assertNull($user->bloqueado_hasta);
        $this->assertDatabaseHas('bitacoras', [
            'id_usuario' => $user->id_usuario,
            'descripcion_movimiento' => 'Inicio de sesión exitoso.',
        ]);
    }

    public function test_five_failures_across_usernames_block_only_the_same_ip_before_user_lookup(): void
    {
        $firstUser = User::factory()->create([
            'nombre_usuario' => 'PRIMER.USUARIO',
        ]);
        User::factory()->create([
            'nombre_usuario' => 'SEGUNDO.USUARIO',
        ]);

        config()->set('sanctum.stateful', ['localhost']);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ]);

        foreach (['NO.EXISTE', 'PRIMER.USUARIO', 'OTRO.INEXISTENTE', 'SEGUNDO.USUARIO', 'TERCERO.INEXISTENTE'] as $username) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
                ->postJson('/api/login', [
                    'username' => $username,
                    'password' => 'WrongPassword456',
                ])
                ->assertUnauthorized()
                ->assertExactJson([
                    'message' => "No fue posible iniciar sesión.\nVerifica tus credenciales.",
                ]);
        }

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            $blockedResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
                ->postJson('/api/login', [
                    'username' => 'PRIMER.USUARIO',
                    'password' => 'Password123',
                ]);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $blockedResponse
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => "No fue posible iniciar sesión.\nVerifica tus credenciales.",
            ]);
        $this->assertCount(0, $queries);
        $this->assertDatabaseCount('bitacoras', 2);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->postJson('/api/login', [
                'username' => 'PRIMER.USUARIO',
                'password' => 'Password123',
            ])
            ->assertOk()
            ->assertJsonPath('usuario.id_usuario', $firstUser->id_usuario);

        $this->assertDatabaseCount('bitacoras', 3);
    }

    public function test_account_failed_attempts_persist_when_request_ip_changes(): void
    {
        $user = User::factory()->create([
            'nombre_usuario' => 'CUENTA.COMPARTIDA',
        ]);

        foreach (['192.0.2.20', '192.0.2.21', '192.0.2.20', '192.0.2.21', '192.0.2.22'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/login', [
                    'username' => 'CUENTA.COMPARTIDA',
                    'password' => 'WrongPassword456',
                ])
                ->assertUnauthorized();
        }

        $user->refresh();
        $this->assertSame(5, $user->intentos_fallidos);
        $this->assertTrue($user->bloqueado_hasta->isFuture());
        $this->assertDatabaseCount('bitacoras', 5);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.23'])
            ->postJson('/api/login', [
                'username' => 'CUENTA.COMPARTIDA',
                'password' => 'Password123',
            ])
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => "No fue posible iniciar sesión.\nVerifica tus credenciales.",
            ]);

        $this->assertSame(5, $user->fresh()->intentos_fallidos);
        $this->assertDatabaseCount('bitacoras', 6);
    }

    public function test_ip_limit_expires_after_five_minutes_and_valid_login_succeeds(): void
    {
        $this->freezeTime();

        $user = User::factory()->create([
            'nombre_usuario' => 'DESPUES.DEL.PLAZO',
        ]);

        config()->set('sanctum.stateful', ['localhost']);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ])->withServerVariables(['REMOTE_ADDR' => '192.0.2.30']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/login', [
                'username' => 'NO.EXISTE',
                'password' => 'Password123',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'username' => 'DESPUES.DEL.PLAZO',
            'password' => 'Password123',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('bitacoras', 0);

        $this->travel(301)->seconds();

        $this->postJson('/api/login', [
            'username' => 'DESPUES.DEL.PLAZO',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.id_usuario', $user->id_usuario);

        $this->assertDatabaseCount('bitacoras', 1);
    }

    public function test_forwarded_for_header_cannot_spoof_untrusted_ip_limit(): void
    {
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.CABECERAS',
        ]);

        config()->set('sanctum.stateful', ['localhost']);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeaders(['X-Forwarded-For' => "198.51.100.{$attempt}"])
                ->withServerVariables(['REMOTE_ADDR' => '192.0.2.40'])
                ->postJson('/api/login', [
                    'username' => 'NO.EXISTE',
                    'password' => 'Password123',
                ])
                ->assertUnauthorized();
        }

        $this->withHeaders(['X-Forwarded-For' => '198.51.100.6'])
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.40'])
            ->postJson('/api/login', [
                'username' => 'USUARIO.CABECERAS',
                'password' => 'Password123',
            ])
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => "No fue posible iniciar sesión.\nVerifica tus credenciales.",
            ]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.41'])
            ->postJson('/api/login', [
                'username' => 'USUARIO.CABECERAS',
                'password' => 'Password123',
            ])
            ->assertOk()
            ->assertJsonPath('usuario.id_usuario', $user->id_usuario);
    }

    public function test_only_configured_proxy_ip_can_supply_client_ip_for_login_limit(): void
    {
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.PROXY',
        ]);

        config()->set('trustedproxy.proxies', ['192.0.2.50']);
        config()->set('sanctum.stateful', ['localhost']);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ])->withServerVariables(['REMOTE_ADDR' => '192.0.2.50']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeader('X-Forwarded-For', '198.51.100.50')
                ->postJson('/api/login', [
                    'username' => 'NO.EXISTE',
                    'password' => 'Password123',
                ])
                ->assertUnauthorized();
        }

        $this->withHeader('X-Forwarded-For', '198.51.100.50')
            ->postJson('/api/login', [
                'username' => 'USUARIO.PROXY',
                'password' => 'Password123',
            ])
            ->assertUnauthorized();

        $this->withHeader('X-Forwarded-For', '198.51.100.51')
            ->postJson('/api/login', [
                'username' => 'USUARIO.PROXY',
                'password' => 'Password123',
            ])
            ->assertOk()
            ->assertJsonPath('usuario.id_usuario', $user->id_usuario);
    }

    public function test_inactive_user_cannot_retrieve_authenticated_user(): void
    {
        $inactiveUser = User::factory()->inactive()->create();

        $this->actingAs($inactiveUser);

        $this->getJson('/api/user')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'El usuario se encuentra inactivo.',
            ]);
    }

    public function test_inactive_administrator_cannot_list_users(): void
    {
        $inactiveAdministrator = User::factory()
            ->administrator()
            ->inactive()
            ->create();

        $this->actingAs($inactiveAdministrator);

        $this->getJson('/api/users')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'El usuario se encuentra inactivo.',
            ]);
    }

    public function test_inactive_user_can_log_out(): void
    {
        $inactiveUser = User::factory()->inactive()->create();

        config()->set('sanctum.stateful', ['localhost']);
        $this->actingAs($inactiveUser);

        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ])
            ->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson([
                'message' => 'Sesión cerrada correctamente.',
            ]);
    }

    public function test_administrator_can_update_user_and_values_are_normalized(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.EDITABLE',
        ]);

        Sanctum::actingAs($administrator);

        $response = $this->patchJson("/api/users/{$user->id_usuario}", [
            'nombre_completo' => '  Usuario Editado  ',
            'nombre_usuario' => '  usuario.editado  ',
            'rol' => '  cocina  ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('usuario.nombre_completo', 'Usuario Editado')
            ->assertJsonPath('usuario.nombre_usuario', 'USUARIO.EDITADO')
            ->assertJsonPath('usuario.rol', 'COCINA');

        $this->assertSafeUserPayload($response->json('usuario'));
        $this->assertDatabaseHas('usuarios', [
            'id_usuario' => $user->id_usuario,
            'nombre_completo' => 'Usuario Editado',
            'nombre_usuario' => 'USUARIO.EDITADO',
            'rol' => 'COCINA',
        ]);

        $audit = Bitacora::query()->latest('id_bitacora')->firstOrFail();

        $this->assertSame($administrator->id_usuario, $audit->id_usuario);
        $this->assertStringContainsString(
            (string) $user->id_usuario,
            $audit->descripcion_movimiento
        );
        $this->assertStringContainsString(
            'nombre_completo',
            $audit->motivo
        );
        $this->assertStringContainsString(
            'nombre_usuario',
            $audit->motivo
        );
        $this->assertStringContainsString('rol', $audit->motivo);
    }

    public function test_update_returns_404_when_user_does_not_exist(): void
    {
        $administrator = User::factory()->administrator()->create();

        Sanctum::actingAs($administrator);

        $this->patchJson('/api/users/999999', [
            'nombre_completo' => 'Usuario Inexistente',
            'nombre_usuario' => 'USUARIO.INEXISTENTE',
            'rol' => 'CAJA',
        ])->assertNotFound();

        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_missing_user_returns_404_before_update_validation(): void
    {
        $administrator = User::factory()->administrator()->create();

        Sanctum::actingAs($administrator);

        $this->patchJson('/api/users/999999', [
            'rol' => 'DESCONOCIDO',
        ])->assertNotFound();

        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_update_skips_unique_query_when_normalized_username_is_unchanged(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.EDITABLE',
        ]);

        Sanctum::actingAs($administrator);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            $response = $this->patchJson("/api/users/{$user->id_usuario}", [
                'nombre_completo' => 'Nombre actualizado',
                'nombre_usuario' => '  usuario.editable  ',
                'rol' => $user->rol,
            ]);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $response
            ->assertOk()
            ->assertJsonPath('usuario.nombre_usuario', 'USUARIO.EDITABLE')
            ->assertJsonPath('usuario.nombre_completo', 'Nombre actualizado');

        $this->assertCount(4, $queries);
        $this->assertCount(0, array_filter(
            $queries,
            fn (array $query): bool => str_contains(strtolower($query['query']), 'count(')
        ));
        $this->assertDatabaseHas('usuarios', [
            'id_usuario' => $user->id_usuario,
            'nombre_usuario' => 'USUARIO.EDITABLE',
            'nombre_completo' => 'Nombre actualizado',
        ]);
        $this->assertDatabaseCount('bitacoras', 1);
    }

    public function test_update_checks_unique_query_when_normalized_username_changes(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.EDITABLE',
        ]);

        Sanctum::actingAs($administrator);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            $response = $this->patchJson("/api/users/{$user->id_usuario}", [
                'nombre_completo' => 'Nombre actualizado',
                'nombre_usuario' => '  usuario.nuevo  ',
                'rol' => $user->rol,
            ]);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $response
            ->assertOk()
            ->assertJsonPath('usuario.nombre_usuario', 'USUARIO.NUEVO')
            ->assertJsonPath('usuario.nombre_completo', 'Nombre actualizado');

        $this->assertCount(5, $queries);
        $this->assertCount(1, array_filter(
            $queries,
            fn (array $query): bool => str_contains(strtolower($query['query']), 'count(')
        ));
        $this->assertDatabaseHas('usuarios', [
            'id_usuario' => $user->id_usuario,
            'nombre_usuario' => 'USUARIO.NUEVO',
            'nombre_completo' => 'Nombre actualizado',
        ]);
        $this->assertDatabaseCount('bitacoras', 1);
    }

    public function test_update_rejects_username_used_by_another_user(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.EDITABLE',
        ]);
        User::factory()->create([
            'nombre_usuario' => 'USUARIO.OCUPADO',
        ]);

        Sanctum::actingAs($administrator);

        $this->patchJson("/api/users/{$user->id_usuario}", [
            'nombre_completo' => $user->nombre_completo,
            'nombre_usuario' => 'usuario.ocupado',
            'rol' => $user->rol,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nombre_usuario')
            ->assertJsonPath('errors.nombre_usuario.0', 'El nombre de usuario ya está registrado.');

        $this->assertSame('USUARIO.EDITABLE', $user->fresh()->nombre_usuario);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_update_rejects_overlong_username_without_unique_query(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.EDITABLE',
        ]);

        Sanctum::actingAs($administrator);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            $response = $this->patchJson("/api/users/{$user->id_usuario}", [
                'nombre_completo' => 'Nombre actualizado',
                'nombre_usuario' => str_repeat('A', 51),
                'rol' => $user->rol,
            ]);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $response
            ->assertUnprocessable()
            ->assertJsonPath('errors.nombre_usuario.0', 'El nombre de usuario no puede superar 50 caracteres.');

        $this->assertNotEmpty($queries);
        $this->assertCount(0, array_filter(
            $queries,
            fn (array $query): bool => str_contains(strtolower($query['query']), 'count(')
        ));
        $this->assertSame('USUARIO.EDITABLE', $user->fresh()->nombre_usuario);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_update_rejects_invalid_role_without_locking_administrators(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.EDITABLE',
        ]);

        Sanctum::actingAs($administrator);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            $response = $this->patchJson("/api/users/{$user->id_usuario}", [
                'nombre_completo' => $user->nombre_completo,
                'nombre_usuario' => $user->nombre_usuario,
                'rol' => 'DESCONOCIDO',
            ]);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $response
            ->assertUnprocessable()
            ->assertJsonPath('errors.rol.0', 'El rol seleccionado no es válido.');

        $this->assertCount(1, array_filter(
            $queries,
            fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select ')
        ));
        $this->assertSame('CAJA', $user->fresh()->rol);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_non_administrator_cannot_update_user(): void
    {
        $cashier = User::factory()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.PROTEGIDO',
        ]);

        Sanctum::actingAs($cashier);

        $this->patchJson("/api/users/{$user->id_usuario}", [
            'nombre_completo' => 'Intento no autorizado',
            'nombre_usuario' => 'INTENTO.NO.AUTORIZADO',
            'rol' => 'TI',
        ])->assertForbidden();

        $this->assertDatabaseHas('usuarios', [
            'id_usuario' => $user->id_usuario,
            'nombre_usuario' => 'USUARIO.PROTEGIDO',
        ]);
    }

    public function test_inactive_administrator_cannot_update_user(): void
    {
        $administrator = User::factory()->administrator()->inactive()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.PROTEGIDO',
        ]);

        Sanctum::actingAs($administrator);

        $this->patchJson("/api/users/{$user->id_usuario}", [
            'nombre_completo' => 'Intento no autorizado',
            'nombre_usuario' => 'INTENTO.NO.AUTORIZADO',
            'rol' => 'TI',
        ])
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'El usuario se encuentra inactivo.',
            ]);

        $this->assertSame('USUARIO.PROTEGIDO', $user->fresh()->nombre_usuario);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_empty_password_preserves_current_hash(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'HASH.CONSERVADO',
            'contrasena_hash' => 'CurrentPassword123',
        ]);
        $originalHash = $user->contrasena_hash;

        Sanctum::actingAs($administrator);

        $this->patchJson("/api/users/{$user->id_usuario}", [
            'nombre_completo' => $user->nombre_completo,
            'nombre_usuario' => $user->nombre_usuario,
            'rol' => $user->rol,
            'contrasena' => '',
        ])->assertOk();

        $this->assertSame(
            $originalHash,
            $user->fresh()->contrasena_hash
        );
    }

    public function test_missing_password_preserves_current_hash(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'HASH.AUSENTE',
            'contrasena_hash' => 'CurrentPassword123',
        ]);
        $originalHash = $user->contrasena_hash;

        Sanctum::actingAs($administrator);

        $this->patchJson("/api/users/{$user->id_usuario}", [
            'nombre_completo' => $user->nombre_completo,
            'nombre_usuario' => $user->nombre_usuario,
            'rol' => $user->rol,
        ])->assertOk();

        $this->assertSame(
            $originalHash,
            $user->fresh()->contrasena_hash
        );
    }

    public function test_null_password_preserves_current_hash(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'HASH.NULO',
            'contrasena_hash' => 'CurrentPassword123',
        ]);
        $originalHash = $user->contrasena_hash;

        Sanctum::actingAs($administrator);

        $this->patchJson("/api/users/{$user->id_usuario}", [
            'nombre_completo' => $user->nombre_completo,
            'nombre_usuario' => $user->nombre_usuario,
            'rol' => $user->rol,
            'contrasena' => null,
        ])->assertOk();

        $this->assertSame(
            $originalHash,
            $user->fresh()->contrasena_hash
        );
    }

    public function test_update_rejects_status_field(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'ESTADO.PROHIBIDO',
        ]);

        Sanctum::actingAs($administrator);

        $this->patchJson("/api/users/{$user->id_usuario}", [
            'nombre_completo' => $user->nombre_completo,
            'nombre_usuario' => $user->nombre_usuario,
            'rol' => $user->rol,
            'estado' => 'INACTIVO',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');

        $this->assertSame('ACTIVO', $user->fresh()->estado);
    }

    public function test_new_password_is_hashed_and_can_be_verified(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'HASH.ACTUALIZADO',
            'contrasena_hash' => 'OldPassword123',
        ]);
        $originalHash = $user->contrasena_hash;
        $newPassword = 'NewPassword456';

        Sanctum::actingAs($administrator);

        $response = $this->patchJson(
            "/api/users/{$user->id_usuario}",
            [
                'nombre_completo' => $user->nombre_completo,
                'nombre_usuario' => $user->nombre_usuario,
                'rol' => $user->rol,
                'contrasena' => $newPassword,
            ]
        );

        $response->assertOk();
        $this->assertSafeUserPayload($response->json('usuario'));

        $updatedHash = $user->fresh()->contrasena_hash;

        $this->assertNotSame($originalHash, $updatedHash);
        $this->assertTrue(Hash::check($newPassword, $updatedHash));

        $audit = Bitacora::query()->latest('id_bitacora')->firstOrFail();

        $this->assertStringContainsString('contrasena', $audit->motivo);
        $this->assertStringNotContainsString($newPassword, $audit->motivo);
        $this->assertStringNotContainsString($updatedHash, $audit->motivo);
    }

    public function test_status_update_returns_404_when_user_does_not_exist(): void
    {
        $administrator = User::factory()->administrator()->create();

        Sanctum::actingAs($administrator);

        $this->patchJson('/api/users/999999/estado', [
            'estado' => 'INACTIVO',
        ])->assertNotFound();

        $this->assertDatabaseCount('bitacoras', 0);
        $this->assertSame('ACTIVO', $administrator->fresh()->estado);
    }

    public function test_missing_user_returns_404_before_status_validation(): void
    {
        $administrator = User::factory()->administrator()->create();

        Sanctum::actingAs($administrator);

        $this->patchJson('/api/users/999999/estado', [
            'estado' => 'DESCONOCIDO',
        ])->assertNotFound();

        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_user_can_be_deactivated_and_reactivated(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'ESTADO.USUARIO',
            'intentos_fallidos' => 5,
            'bloqueado_hasta' => now()->addMinutes(5),
        ]);

        Sanctum::actingAs($administrator);

        $deactivateResponse = $this->patchJson(
            "/api/users/{$user->id_usuario}/estado",
            ['estado' => 'inactivo']
        );

        $deactivateResponse
            ->assertOk()
            ->assertJsonPath('usuario.estado', 'INACTIVO');
        $this->assertSafeUserPayload(
            $deactivateResponse->json('usuario')
        );

        $user->refresh();
        $this->assertSame(5, $user->intentos_fallidos);
        $this->assertNotNull($user->bloqueado_hasta);

        $reactivateResponse = $this->patchJson(
            "/api/users/{$user->id_usuario}/estado",
            ['estado' => 'activo']
        );

        $reactivateResponse
            ->assertOk()
            ->assertJsonPath('usuario.estado', 'ACTIVO');
        $this->assertSafeUserPayload(
            $reactivateResponse->json('usuario')
        );

        $user->refresh();

        $this->assertSame(0, $user->intentos_fallidos);
        $this->assertNull($user->bloqueado_hasta);
        $this->assertDatabaseCount('bitacoras', 2);
        $this->assertSame(
            2,
            Bitacora::query()
                ->where('id_usuario', $administrator->id_usuario)
                ->count()
        );
    }

    public function test_administrator_cannot_deactivate_itself(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->administrator()->create();

        Sanctum::actingAs($administrator);

        $this->patchJson(
            "/api/users/{$administrator->id_usuario}/estado",
            ['estado' => 'INACTIVO']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');

        $this->assertSame('ACTIVO', $administrator->fresh()->estado);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_last_active_administrator_cannot_be_deactivated(): void
    {
        $this->withoutMiddleware(EnsureUserIsActive::class);

        $actor = User::factory()
            ->administrator()
            ->inactive()
            ->create();
        $lastActiveAdministrator = User::factory()
            ->administrator()
            ->create();

        Sanctum::actingAs($actor);

        $this->patchJson(
            "/api/users/{$lastActiveAdministrator->id_usuario}/estado",
            ['estado' => 'INACTIVO']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');

        $this->assertSame(
            'ACTIVO',
            $lastActiveAdministrator->fresh()->estado
        );
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_last_active_administrator_cannot_change_role(): void
    {
        $this->withoutMiddleware(EnsureUserIsActive::class);

        $actor = User::factory()
            ->administrator()
            ->inactive()
            ->create();
        $lastActiveAdministrator = User::factory()
            ->administrator()
            ->create([
                'nombre_usuario' => 'ULTIMO.ADMINISTRADOR',
            ]);

        Sanctum::actingAs($actor);

        $this->patchJson(
            "/api/users/{$lastActiveAdministrator->id_usuario}",
            [
                'nombre_completo' => $lastActiveAdministrator->nombre_completo,
                'nombre_usuario' => $lastActiveAdministrator->nombre_usuario,
                'rol' => 'CAJA',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rol');

        $this->assertSame(
            'ADMINISTRADOR',
            $lastActiveAdministrator->fresh()->rol
        );
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_creation_records_actor_and_affected_user_in_audit(): void
    {
        $administrator = User::factory()->administrator()->create();

        Sanctum::actingAs($administrator);

        $response = $this->postJson('/api/users', [
            'nombre_completo' => '  Nueva Persona  ',
            'nombre_usuario' => '  nueva.persona  ',
            'contrasena' => 'Password123',
            'rol' => 'ti',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('usuario.nombre_completo', 'Nueva Persona')
            ->assertJsonPath('usuario.nombre_usuario', 'NUEVA.PERSONA')
            ->assertJsonPath('usuario.rol', 'TI')
            ->assertJsonPath('usuario.estado', 'ACTIVO');
        $this->assertSafeUserPayload($response->json('usuario'));

        $createdUserId = $response->json('usuario.id_usuario');
        $audit = Bitacora::query()->latest('id_bitacora')->firstOrFail();

        $this->assertSame($administrator->id_usuario, $audit->id_usuario);
        $this->assertStringContainsString(
            'NUEVA.PERSONA',
            $audit->descripcion_movimiento
        );
        $this->assertStringContainsString(
            (string) $createdUserId,
            $audit->descripcion_movimiento
        );
        $this->assertStringNotContainsString(
            'Password123',
            $audit->descripcion_movimiento.$audit->motivo
        );
    }

    public function test_creation_rejects_roles_outside_allowed_values(): void
    {
        $administrator = User::factory()->administrator()->create();

        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [
            'nombre_completo' => 'Rol Inválido',
            'nombre_usuario' => 'rol.invalido',
            'contrasena' => 'Password123',
            'rol' => 'ENCARGADO TI',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rol');

        $this->assertDatabaseMissing('usuarios', [
            'nombre_usuario' => 'ROL.INVALIDO',
        ]);
    }

    public function test_creation_rejects_overlong_username_without_unique_query(): void
    {
        $administrator = User::factory()->administrator()->create();

        Sanctum::actingAs($administrator);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            $response = $this->postJson('/api/users', [
                'nombre_completo' => 'Nuevo Usuario',
                'nombre_usuario' => str_repeat('A', 51),
                'contrasena' => 'Password123',
                'rol' => 'CAJA',
            ]);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $response
            ->assertUnprocessable()
            ->assertJsonPath('errors.nombre_usuario.0', 'El nombre de usuario no puede superar 50 caracteres.');

        $this->assertCount(0, array_filter(
            $queries,
            fn (array $query): bool => str_contains(strtolower($query['query']), 'count(')
        ));
        $this->assertDatabaseCount('usuarios', 1);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_listing_is_ordered_and_contains_only_public_columns(): void
    {
        $administrator = User::factory()->administrator()->create([
            'nombre_completo' => 'Zeta Administrador',
        ]);
        User::factory()->create([
            'nombre_completo' => 'Alfa Usuario',
            'nombre_usuario' => 'ALFA.USUARIO',
        ]);

        Sanctum::actingAs($administrator);

        $response = $this->getJson('/api/users')->assertOk();
        $users = $response->json('usuarios');

        $this->assertSame('Alfa Usuario', $users[0]['nombre_completo']);
        $this->assertSame(
            'Zeta Administrador',
            $users[1]['nombre_completo']
        );

        foreach ($users as $payload) {
            $this->assertSafeUserPayload($payload);
        }
    }

    public function test_user_responses_never_expose_password_hash(): void
    {
        $administrator = User::factory()->administrator()->create([
            'nombre_completo' => 'Administrador Principal',
        ]);
        $user = User::factory()->create([
            'nombre_completo' => 'Usuario Seguro',
            'nombre_usuario' => 'USUARIO.SEGURO',
        ]);

        Sanctum::actingAs($administrator);

        $listResponse = $this->getJson('/api/users')->assertOk();

        foreach ($listResponse->json('usuarios') as $payload) {
            $this->assertSafeUserPayload($payload);
        }

        $updateResponse = $this->patchJson(
            "/api/users/{$user->id_usuario}",
            [
                'nombre_completo' => 'Usuario Seguro Editado',
                'nombre_usuario' => 'usuario.seguro',
                'rol' => 'caja',
            ]
        )->assertOk();

        $this->assertSafeUserPayload($updateResponse->json('usuario'));

        $statusResponse = $this->patchJson(
            "/api/users/{$user->id_usuario}/estado",
            ['estado' => 'INACTIVO']
        )->assertOk();

        $this->assertSafeUserPayload($statusResponse->json('usuario'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSafeUserPayload(array $payload): void
    {
        $this->assertSame(
            [
                'id_usuario',
                'nombre_completo',
                'nombre_usuario',
                'rol',
                'estado',
            ],
            array_keys($payload)
        );
        $this->assertArrayNotHasKey('contrasena_hash', $payload);
    }
}
