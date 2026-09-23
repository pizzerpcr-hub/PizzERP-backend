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

    public function test_user_can_be_deactivated_and_reactivated(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'nombre_usuario' => 'ESTADO.USUARIO',
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

        $user->refresh()->forceFill([
            'intentos_fallidos' => 5,
            'bloqueado_hasta' => now()->addMinutes(5),
        ])->save();

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
