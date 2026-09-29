<?php

namespace Tests\Feature;

use App\Events\UserChanged;
use App\Events\UserCreated;
use App\Events\UserStatus;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Models\Bitacora;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Pusher\Pusher;
use RuntimeException;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    /** Opt-in integration test with real sockets subscribed before the channel was retired. */
    #[TestWith(['TI', 'CAJA'])]
    #[TestWith(['TI', 'COCINA'])]
    #[TestWith(['ADMINISTRADOR', 'CAJA'])]
    #[TestWith(['ADMINISTRADOR', 'COCINA'])]
    #[TestWith(['TI', null])]
    #[TestWith(['ADMINISTRADOR', null])]
    public function test_existing_websockets_receive_no_user_events_after_channel_retirement(string $role, ?string $newRole): void
    {
        if (getenv('RUN_REVERB_DIAGNOSTIC') !== '1') {
            $this->markTestSkipped('Opt-in diagnostic requires isolated Reverb on 127.0.0.1:18080 with test credentials.');
        }
        $this->configureBroadcastAuth();
        config(['sanctum.stateful' => ['localhost']]);
        config([
            'broadcasting.connections.reverb.options' => [
                'host' => '127.0.0.1', 'port' => 18080, 'scheme' => 'http', 'useTLS' => false,
            ],
        ]);
        $pusher = new Pusher('test-key', 'test-secret', 'test-app', [
            'host' => '127.0.0.1', 'port' => 18080, 'scheme' => 'http', 'useTLS' => false,
        ]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $actor = User::factory()->administrator()->create();
        $target = User::factory()->create(['rol' => $role]);
        $cookieName = config('session.cookie');
        $sessions = [];
        foreach ([$actor, $target] as $user) {
            auth()->forgetGuards();
            $login = $this->withCookie($cookieName, '')->postJson('/api/login', [
                'username' => $user->nombre_usuario, 'password' => 'Password123',
            ])->assertOk();
            $sessions[] = $login->getCookie($cookieName)->getValue();
        }
        $this->assertNotSame($sessions[0], $sessions[1]);
        $clients = [];
        try {
            foreach ($sessions as $session) {
                $process = proc_open(['node', base_path('tests/Support/reverb-probe.mjs')], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, base_path());
                $this->assertIsResource($process);
                $clients[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 10);
                $connected = json_decode((string) fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('pusher:connection_established', $connected['event']);
                $socketId = json_decode($connected['data'], true)['socket_id'];
                auth()->forgetGuards();
                $this->withCookie($cookieName, $session)->postJson('/broadcasting/auth', [
                    'socket_id' => $socketId, 'channel_name' => 'private-usuarios',
                ])->assertForbidden();
                // Simulate a subscription signed by the previous deployment, not new authorization.
                $authorization = json_decode($pusher->authorizeChannel('private-usuarios', $socketId), true)['auth'];
                fwrite($pipes[0], json_encode(['event' => 'pusher:subscribe', 'data' => [
                    'channel' => 'private-usuarios', 'auth' => $authorization,
                ]])."\n");
                $subscribed = json_decode((string) fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('pusher_internal:subscription_succeeded', $subscribed['event']);
            }
            $payload = (new UserChanged($target->toArray()))->broadcastWith();
            $fields = array_keys($payload['usuario']);
            sort($fields);
            $this->assertSame(['estado', 'id_usuario', 'nombre_completo', 'nombre_usuario', 'rol'], $fields);
            $pusher->trigger('private-usuarios', 'probe.before', []);
            foreach ($clients as [, $pipes]) {
                $this->assertSame('probe.before', json_decode((string) fgets($pipes[1]), true)['event']);
            }
            auth()->forgetGuards();
            $this->withCookie($cookieName, $sessions[0]);
            if ($newRole === null) {
                $this->patchJson("/api/users/{$target->id_usuario}/estado", ['estado' => 'INACTIVO'])->assertOk();
            } else {
                $this->patchJson("/api/users/{$target->id_usuario}", [
                    'nombre_completo' => $target->nombre_completo,
                    'nombre_usuario' => $target->nombre_usuario, 'rol' => $newRole,
                ])->assertOk();
            }
            auth()->forgetGuards();
            $this->withCookie($cookieName, $sessions[1])->getJson('/api/users')->assertForbidden();
            auth()->forgetGuards();
            $this->postJson('/broadcasting/auth', [
                'socket_id' => $socketId, 'channel_name' => 'private-usuarios',
            ])->assertForbidden();
            auth()->forgetGuards();
            $this->withCookie($cookieName, $sessions[0]);
            $this->performBroadcastMutation('create', $actor);
            $other = User::factory()->create();
            $this->performBroadcastMutation('update', $other);
            $this->performBroadcastMutation('status', $other);
            $this->patchJson("/api/users/{$other->id_usuario}/estado", ['estado' => 'ACTIVO'])->assertOk();
            // Old serialized jobs must also have no destination under the updated code.
            foreach ([UserCreated::class, UserChanged::class, UserStatus::class] as $eventClass) {
                (new BroadcastEvent(new $eventClass($payload['usuario'])))
                    ->handle(app(Factory::class));
            }
            // Barrier on each still-open socket: any user event before this fails the assertion.
            $pusher->trigger('private-usuarios', 'probe.after', []);
            foreach ($clients as [, $pipes]) {
                $message = json_decode((string) fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('probe.after', $message['event']);
                $this->assertSame([], json_decode($message['data'], true));
            }
        } finally {
            foreach ($clients as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
        }
    }

    #[TestWith(['CAJA', 'COCINA', 403])]
    #[TestWith(['COCINA', 'TI', 200])]
    #[TestWith(['TI', 'CAJA', 403])]
    #[TestWith(['ADMINISTRADOR', 'COCINA', 403])]
    public function test_existing_session_uses_new_role_on_next_request(string $initialRole, string $newRole, int $status): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->configureBroadcastAuth();
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $actor = User::factory()->administrator()->create();
        $user = User::factory()->create(['rol' => $initialRole]);
        $cookieName = config('session.cookie');
        $login = $this->postJson('/api/login', [
            'username' => $user->nombre_usuario, 'password' => 'Password123',
        ])->assertOk();
        $sessionId = $login->getCookie($cookieName)->getValue();
        auth()->forgetGuards();
        $actorLogin = $this->withCookie($cookieName, '')->postJson('/api/login', [
            'username' => $actor->nombre_usuario, 'password' => 'Password123',
        ])->assertOk();
        $this->withCookie($cookieName, $actorLogin->getCookie($cookieName)->getValue())
            ->patchJson("/api/users/{$user->id_usuario}", [
                'nombre_completo' => $user->nombre_completo,
                'nombre_usuario' => $user->nombre_usuario, 'rol' => $newRole,
            ])->assertOk();
        auth()->forgetGuards();
        $this->withCookie($cookieName, $sessionId)->getJson('/api/user')
            ->assertOk()->assertJsonPath('usuario.rol', $newRole);
        auth()->forgetGuards();
        $this->getJson('/api/users')->assertStatus($status);
        auth()->forgetGuards();
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => 'private-usuarios',
        ])->assertForbidden();
    }

    #[TestWith(['TI'])]
    #[TestWith(['CAJA'])]
    #[TestWith(['COCINA'])]
    public function test_deactivation_allows_existing_session_but_blocks_its_next_protected_request(string $role): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $actor = User::factory()->administrator()->create();
        $user = User::factory()->create(['rol' => $role]);
        $cookieName = config('session.cookie');
        $login = $this->postJson('/api/login', [
            'username' => $user->nombre_usuario, 'password' => 'Password123',
        ])->assertOk();
        $sessionId = $login->getCookie($cookieName)->getValue();
        auth()->forgetGuards();
        $actorLogin = $this->withCookie($cookieName, '')->postJson('/api/login', [
            'username' => $actor->nombre_usuario, 'password' => 'Password123',
        ])->assertOk();
        $this->withCookie($cookieName, $actorLogin->getCookie($cookieName)->getValue())
            ->patchJson("/api/users/{$user->id_usuario}/estado", [
                'estado' => 'INACTIVO',
            ])->assertOk();
        foreach (['/api/user', '/api/users'] as $uri) {
            auth()->forgetGuards();
            $this->withCookie($cookieName, $sessionId)->getJson($uri)->assertForbidden()
                ->assertExactJson(['message' => 'El usuario se encuentra inactivo.']);
        }
        auth()->forgetGuards();
        $this->postJson('/api/logout')->assertOk();
    }

    public function test_logout_invalidates_cookie_and_direct_access_requires_login_again(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $user = User::factory()->create(['rol' => 'TI']);
        $cookieName = config('session.cookie');
        $login = $this->postJson('/api/login', [
            'username' => $user->nombre_usuario, 'password' => 'Password123', 'remember' => true,
        ])->assertOk();
        $sessionId = $login->getCookie($cookieName)->getValue();
        $rememberName = auth('web')->getRecallerName();
        $rememberCookie = $login->getCookie($rememberName)->getValue();
        auth()->forgetGuards();

        $this->withCookie($rememberName, $rememberCookie)
            ->withCookie($cookieName, $sessionId)->postJson('/api/logout')
            ->assertOk()->assertCookieExpired($rememberName)
            ->assertCookieExpired(EnsureSessionIsCurrent::GRANT_COOKIE)
            ->assertExactJson(['message' => 'Sesión cerrada correctamente.']);

        foreach (['/api/user', '/api/users'] as $uri) {
            auth()->forgetGuards();
            $this->withCookie($rememberName, '')->withCookie($cookieName, $sessionId)
                ->get($uri, ['Accept' => 'text/html'])
                ->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        }
    }

    public function test_login_without_remember_uses_a_session_cookie_and_expires_after_inactivity(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $user = User::factory()->create(['rol' => 'TI']);
        $cookieName = config('session.cookie');
        $rememberName = auth('web')->getRecallerName();

        $login = $this->postJson('/api/login', [
            'username' => $user->nombre_usuario, 'password' => 'Password123', 'remember' => false,
        ])->assertOk();

        $sessionId = $login->getCookie($cookieName)->getValue();
        $this->assertSame(0, $login->getCookie($cookieName)->getExpiresTime());
        $login->assertCookieExpired($rememberName)
            ->assertCookieExpired(EnsureSessionIsCurrent::GRANT_COOKIE);

        auth()->forgetGuards();
        app('session')->driver()->flush();
        $this->withCookie($cookieName, $sessionId)->getJson('/api/user')->assertOk();

        $this->travel(EnsureSessionIsCurrent::IDLE_MINUTES + 1)->minutes();
        auth()->forgetGuards();
        app('session')->driver()->flush();
        $this->withCookie($cookieName, $sessionId)->getJson('/api/users')
            ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_expired_session_can_still_log_out_and_cannot_access_protected_routes(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $user = User::factory()->create(['rol' => 'TI']);
        $cookieName = config('session.cookie');
        $login = $this->postJson('/api/login', [
            'username' => $user->nombre_usuario, 'password' => 'Password123', 'remember' => false,
        ])->assertOk();
        $sessionId = $login->getCookie($cookieName)->getValue();

        $this->travel(EnsureSessionIsCurrent::IDLE_MINUTES + 1)->minutes();
        auth()->forgetGuards();
        app('session')->driver()->flush();
        $this->withCookie($cookieName, $sessionId)->postJson('/api/logout')
            ->assertOk()->assertCookieExpired(EnsureSessionIsCurrent::GRANT_COOKIE);

        auth()->forgetGuards();
        app('session')->driver()->flush();
        $this->withCookie($cookieName, $sessionId)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_activity_extends_a_non_remembered_session_and_reload_does_not_log_out(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $user = User::factory()->create(['rol' => 'TI']);
        $cookieName = config('session.cookie');
        $login = $this->postJson('/api/login', [
            'username' => $user->nombre_usuario, 'password' => 'Password123', 'remember' => false,
        ])->assertOk();
        $sessionId = $login->getCookie($cookieName)->getValue();

        foreach ([20, 20] as $minutes) {
            $this->travel($minutes)->minutes();
            auth()->forgetGuards();
            app('session')->driver()->flush();
            $this->withCookie($cookieName, $sessionId)->getJson('/api/user')->assertOk();
        }
    }

    public function test_remembered_login_can_restore_an_expired_session_but_not_after_fourteen_days(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $user = User::factory()->create(['rol' => 'TI']);
        $cookieName = config('session.cookie');
        $rememberName = auth('web')->getRecallerName();
        $login = $this->postJson('/api/login', [
            'username' => $user->nombre_usuario, 'password' => 'Password123', 'remember' => true,
        ])->assertOk();
        $rememberCookie = $login->getCookie($rememberName)->getValue();
        $grantCookie = $login->getCookie(EnsureSessionIsCurrent::GRANT_COOKIE)->getValue();

        $this->assertSame(0, $login->getCookie($cookieName)->getExpiresTime());
        $this->assertGreaterThan(now()->addDays(13)->timestamp, $login->getCookie($rememberName)->getExpiresTime());
        $this->assertGreaterThan(now()->addDays(13)->timestamp, $login->getCookie(EnsureSessionIsCurrent::GRANT_COOKIE)->getExpiresTime());

        $this->travel(121)->minutes();
        auth()->forgetGuards();
        app('session')->driver()->flush();
        $this->withCookie($cookieName, '')
            ->withCookie($rememberName, $rememberCookie)
            ->withCookie(EnsureSessionIsCurrent::GRANT_COOKIE, $grantCookie)
            ->getJson('/api/user')->assertOk();

        $this->travel(EnsureSessionIsCurrent::REMEMBER_DAYS)->days();
        auth()->forgetGuards();
        app('session')->driver()->flush();
        $this->withCookie($cookieName, '')
            ->withCookie($rememberName, $rememberCookie)
            ->withCookie(EnsureSessionIsCurrent::GRANT_COOKIE, $grantCookie)
            ->getJson('/api/user')->assertUnauthorized();
    }

    public function test_remember_cookie_without_server_verifiable_grant_cannot_restore_session(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $user = User::factory()->create(['rol' => 'TI']);
        $cookieName = config('session.cookie');
        $rememberName = auth('web')->getRecallerName();
        $login = $this->postJson('/api/login', [
            'username' => $user->nombre_usuario, 'password' => 'Password123', 'remember' => true,
        ])->assertOk();
        $rememberCookie = $login->getCookie($rememberName)->getValue();

        $this->travel(121)->minutes();
        auth()->forgetGuards();
        app('session')->driver()->flush();
        $this->withCookie($cookieName, 'missing-session')
            ->withCookie($rememberName, $rememberCookie)
            ->withCookie(EnsureSessionIsCurrent::GRANT_COOKIE, 'invalid')
            ->getJson('/api/user')->assertUnauthorized();
    }

    public function test_private_channel_authorization_rejects_expired_session(): void
    {
        $this->configureBroadcastAuth();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $user = User::factory()->create(['rol' => 'TI']);
        $cookieName = config('session.cookie');
        $login = $this->postJson('/api/login', [
            'username' => $user->nombre_usuario, 'password' => 'Password123', 'remember' => false,
        ])->assertOk();
        $sessionId = $login->getCookie($cookieName)->getValue();

        auth()->forgetGuards();
        app('session')->driver()->flush();
        $this->withCookie($cookieName, $sessionId)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => 'private-usuario.'.$user->id_usuario,
        ])->assertOk();

        $this->travel(EnsureSessionIsCurrent::IDLE_MINUTES + 1)->minutes();
        auth()->forgetGuards();
        app('session')->driver()->flush();
        $this->withCookie($cookieName, $sessionId)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => 'private-usuario.'.$user->id_usuario,
        ])->assertUnauthorized();
    }

    public function test_login_without_remember_removes_previous_remember_cookies(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $user = User::factory()->create(['rol' => 'TI']);
        $cookieName = config('session.cookie');
        $rememberName = auth('web')->getRecallerName();
        $credentials = ['username' => $user->nombre_usuario, 'password' => 'Password123'];

        $remembered = $this->postJson('/api/login', $credentials + ['remember' => true])->assertOk();
        $this->assertNotNull($remembered->getCookie($rememberName));

        auth()->forgetGuards();
        app('session')->driver()->flush();
        $ordinary = $this->withCookie($cookieName, '')
            ->postJson('/api/login', $credentials + ['remember' => false])
            ->assertOk();

        $ordinary->assertCookieExpired($rememberName)
            ->assertCookieExpired(EnsureSessionIsCurrent::GRANT_COOKIE);
        $this->assertSame(0, $ordinary->getCookie($cookieName)->getExpiresTime());
    }

    public function test_registration_requires_all_four_fields_without_writing_an_audit(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'TI']), 'web');
        $this->postJson('/api/users', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre_completo', 'nombre_usuario', 'contrasena', 'rol']);
        $this->assertDatabaseCount('usuarios', 1);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_registration_rejects_normalized_duplicate_username(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'TI', 'nombre_usuario' => 'EXISTENTE']), 'web');
        $this->postJson('/api/users', [
            'nombre_completo' => 'Otra persona', 'nombre_usuario' => ' existente ',
            'contrasena' => 'Password123', 'rol' => 'CAJA',
        ])->assertUnprocessable()->assertJsonValidationErrors('nombre_usuario')
            ->assertJsonPath('errors.nombre_usuario.0', 'El nombre de usuario ya está registrado.');
        $this->assertDatabaseCount('usuarios', 1);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    #[TestWith(['Abc1234'])]
    #[TestWith(['abcdefgh'])]
    #[TestWith(['12345678'])]
    public function test_registration_rejects_invalid_password_without_creating_user(string $password): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'TI']), 'web');
        $this->postJson('/api/users', [
            'nombre_completo' => 'Nueva persona', 'nombre_usuario' => 'NUEVA',
            'contrasena' => $password, 'rol' => 'CAJA',
        ])->assertUnprocessable()->assertJsonValidationErrors('contrasena');
        $this->assertDatabaseCount('usuarios', 1);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    #[TestWith(['ADMINISTRADOR'])]
    #[TestWith(['TI'])]
    #[TestWith(['CAJA'])]
    #[TestWith(['COCINA'])]
    public function test_ti_registration_assigns_role_lists_user_and_records_timestamped_actor(string $role): void
    {
        $this->freezeTime();
        $actor = User::factory()->create(['rol' => 'TI']);
        $this->actingAs($actor, 'web');
        $response = $this->postJson('/api/users', [
            'nombre_completo' => 'Nueva persona', 'nombre_usuario' => 'NUEVA',
            'contrasena' => 'Password123', 'rol' => $role,
        ])->assertCreated()->assertJsonPath('usuario.rol', $role);
        $this->getJson('/api/users')->assertOk()->assertJsonFragment($response->json('usuario'));
        $created = User::findOrFail($response->json('usuario.id_usuario'));
        $this->assertTrue(Hash::check('Password123', $created->contrasena_hash));
        $audit = Bitacora::sole();
        $this->assertSame($actor->id_usuario, $audit->id_usuario);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $audit->fecha->format('Y-m-d H:i:s'));
        $this->assertSame('GESTION_USUARIOS', $audit->tipo_movimiento);
        $this->assertSame("Usuario NUEVA (ID {$created->id_usuario}) creado.", $audit->descripcion_movimiento);
        $this->assertStringNotContainsString('Password123', $audit->toJson());
        $this->assertStringNotContainsString($created->contrasena_hash, $audit->toJson());
    }

    #[TestWith(['ADMINISTRADOR'])]
    #[TestWith(['TI'])]
    public function test_logins_and_logout_preserve_other_browser_session_and_remember_cookie(string $role): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->configureBroadcastAuth();
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $user = User::factory()->create(['rol' => $role]);
        $credentials = ['username' => $user->nombre_usuario, 'password' => 'Password123', 'remember' => true];
        $cookieName = config('session.cookie');
        $first = $this->postJson('/api/login', $credentials)->assertOk()->assertJsonMissingPath('control');
        $firstSession = $first->getCookie($cookieName)->getValue();
        $rememberName = auth('web')->getRecallerName();
        $firstRemember = $first->getCookie($rememberName)->getValue();
        auth()->forgetGuards();
        $second = $this->postJson('/api/login', $credentials)->assertOk()->assertJsonMissingPath('control');
        $secondSession = $second->getCookie($cookieName)->getValue();
        $this->assertNotSame($firstSession, $secondSession);

        foreach ([$firstSession, $secondSession, $firstSession] as $sessionId) {
            auth()->forgetGuards();
            $this->withCookie($cookieName, $sessionId);
            $this->getJson('/api/user')->assertOk()->assertJsonPath('usuario.id_usuario', $user->getKey());
            $this->getJson('/api/users')->assertOk();
            $this->postJson('/broadcasting/auth', [
                'socket_id' => '123.456', 'channel_name' => 'private-usuarios',
            ])->assertForbidden();
        }

        auth()->forgetGuards();
        $this->withCookie($cookieName, $secondSession)->postJson('/api/logout')->assertOk();
        auth()->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        auth()->forgetGuards();
        $this->withCookie($cookieName, $firstSession)->getJson('/api/user')->assertOk();
        auth()->forgetGuards();
        $this->withCookie($cookieName, '')->withCookie($rememberName, $firstRemember);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('usuario.id_usuario', $user->getKey());
    }

    #[TestWith(['GET', '/api/users'])]
    #[TestWith(['GET', '/api/user'])]
    #[TestWith(['POST', '/api/users'])]
    #[TestWith(['PATCH', '/api/users/999'])]
    #[TestWith(['PATCH', '/api/users/999/estado'])]
    #[TestWith(['POST', '/api/logout'])]
    public function test_api_guest_receives_401_json_when_accepting_html(string $method, string $uri): void
    {
        $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'text/html'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[TestWith(['COCINA', 403])]
    #[TestWith(['ADMINISTRADOR', 200])]
    #[TestWith(['TI', 200])]
    public function test_html_accept_preserves_authenticated_user_permissions(string $role, int $status): void
    {
        $user = User::factory()->create(['rol' => $role]);

        $response = $this->actingAs($user, 'web')
            ->get('/api/users', ['Accept' => 'text/html'])
            ->assertStatus($status)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location');

        if ($status === 403) {
            $response->assertJsonPath('message', 'No tiene permiso para gestionar usuarios.');
        } else {
            $response->assertJsonPath('usuarios.0.id_usuario', $user->id_usuario);
        }
    }

    #[TestWith(['create'])]
    #[TestWith(['update'])]
    #[TestWith(['status'])]
    public function test_active_ti_can_mutate_users_and_is_the_audit_actor(string $action): void
    {
        $actor = User::factory()->create(['rol' => 'TI']);
        $user = User::factory()->create();
        Queue::fake([BroadcastEvent::class]);
        $this->actingAs($actor, 'web');

        $response = $this->performBroadcastMutation($action, $user);

        $this->assertDatabaseHas('usuarios', $response->json('usuario'));
        $this->assertDatabaseCount('bitacoras', 1);
        $this->assertSame($actor->id_usuario, Bitacora::sole()->id_usuario);
        $this->assertSame(
            ['id_usuario', 'nombre_completo', 'nombre_usuario', 'rol', 'estado'],
            array_keys($response->json('usuario'))
        );
        if ($action === 'status') {
            Queue::assertPushed(BroadcastEvent::class, 1);
        } else {
            Queue::assertNothingPushed();
        }
    }

    public function test_ti_session_can_be_restored_and_list_users(): void
    {
        $actor = User::factory()->create(['rol' => 'TI']);
        $this->actingAs($actor, 'web');

        $this->getJson('/api/user')->assertOk()->assertJsonPath('usuario.rol', 'TI');
        $this->getJson('/api/users')->assertOk()
            ->assertJsonPath('usuarios.0.id_usuario', $actor->id_usuario);
    }

    public function test_ti_can_reactivate_and_reset_lockout(): void
    {
        $actor = User::factory()->create(['rol' => 'TI']);
        $user = User::factory()->inactive()->create([
            'intentos_fallidos' => 5,
            'bloqueado_hasta' => now()->addMinutes(5),
        ]);
        $this->actingAs($actor, 'web');

        $this->patchJson("/api/users/{$user->id_usuario}/estado", ['estado' => 'ACTIVO'])
            ->assertOk()->assertJsonPath('usuario.estado', 'ACTIVO');

        $this->assertDatabaseHas('usuarios', [
            'id_usuario' => $user->id_usuario,
            'estado' => 'ACTIVO',
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => null,
        ]);
        $this->assertSame($actor->id_usuario, Bitacora::sole()->id_usuario);
    }

    #[TestWith([null, 'ACTIVO', 401])]
    #[TestWith(['CAJA', 'ACTIVO', 403])]
    #[TestWith(['COCINA', 'ACTIVO', 403])]
    #[TestWith(['ADMINISTRADOR', 'INACTIVO', 403])]
    #[TestWith(['TI', 'INACTIVO', 403])]
    public function test_user_management_denies_all_operations_without_permission(
        ?string $role,
        string $status,
        int $expectedStatus
    ): void {
        $user = User::factory()->create();
        $original = $user->fresh()->getAttributes();
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['rol' => $role, 'estado' => $status]), 'web');
        }
        Queue::fake([BroadcastEvent::class]);
        $data = [
            'nombre_completo' => 'Cambio prohibido',
            'nombre_usuario' => 'PROHIBIDO',
            'contrasena' => 'Password123',
            'rol' => 'CAJA',
        ];

        $this->getJson('/api/users')->assertStatus($expectedStatus);
        $this->postJson('/api/users', $data)->assertStatus($expectedStatus);
        $this->patchJson("/api/users/{$user->id_usuario}", $data)->assertStatus($expectedStatus);
        $this->patchJson("/api/users/{$user->id_usuario}/estado", ['estado' => 'INACTIVO'])
            ->assertStatus($expectedStatus);

        $this->assertSame($original, $user->fresh()->getAttributes());
        $this->assertDatabaseMissing('usuarios', ['nombre_usuario' => 'PROHIBIDO']);
        $this->assertDatabaseCount('bitacoras', 0);
        Queue::assertNothingPushed();
    }

    public function test_broadcast_auth_returns_401_without_session(): void
    {
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-usuarios',
        ])->assertUnauthorized();
    }

    #[TestWith(['ADMINISTRADOR', 'INACTIVO'])]
    #[TestWith(['CAJA', 'ACTIVO'])]
    #[TestWith(['COCINA', 'ACTIVO'])]
    #[TestWith(['TI', 'INACTIVO'])]
    public function test_broadcast_auth_returns_403_without_active_user_manager(
        string $role,
        string $status
    ): void {
        $this->configureBroadcastAuth();
        $user = User::factory()->create(['rol' => $role, 'estado' => $status]);

        $this->actingAs($user, 'web')->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-usuarios',
        ])->assertForbidden();
    }

    #[TestWith(['ADMINISTRADOR'])]
    #[TestWith(['TI'])]
    public function test_retired_private_channel_denies_even_active_user_managers(string $role): void
    {
        $this->configureBroadcastAuth();
        $user = User::factory()->create(['rol' => $role]);

        $this->actingAs($user, 'web')->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-usuarios',
        ])->assertForbidden();
    }

    private function configureBroadcastAuth(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
    }

    #[TestWith([UserCreated::class])]
    #[TestWith([UserChanged::class])]
    #[TestWith([UserStatus::class])]
    public function test_user_event_has_safe_payload_and_only_status_targets_the_user(string $eventClass): void
    {
        $public = [
            'id_usuario' => 10,
            'nombre_completo' => 'Usuario de prueba',
            'nombre_usuario' => 'PRUEBA',
            'rol' => 'CAJA',
            'estado' => 'ACTIVO',
        ];
        $event = new $eventClass($public + [
            'contrasena' => 'secret',
            'contrasena_hash' => 'hash',
            'remember_token' => 'token',
            'intentos_fallidos' => 5,
            'bloqueado_hasta' => '2026-01-01',
        ]);

        $this->assertSame(['usuario' => $public], $event->broadcastWith());
        $this->assertSame($public, $event->usuario);
        $this->assertSame(
            $eventClass === UserStatus::class ? ['private-usuario.10'] : [],
            array_map(fn ($channel) => $channel->name, $event->broadcastOn())
        );
    }

    #[TestWith(['create', UserCreated::class])]
    #[TestWith(['update', UserChanged::class])]
    #[TestWith(['status', UserStatus::class])]
    public function test_user_mutation_queues_only_status_event_after_outer_commit(
        string $action,
        string $eventClass
    ): void {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create();
        Queue::fake([BroadcastEvent::class]);
        Sanctum::actingAs($administrator);
        DB::beginTransaction();

        $response = $this->performBroadcastMutation($action, $user);
        Queue::assertNothingPushed();
        DB::commit();

        $this->assertSame(
            $action === 'status' ? ['private-usuario.'.$user->id_usuario] : [],
            array_map(fn ($channel) => $channel->name, (new $eventClass($response->json('usuario')))->broadcastOn())
        );
        if ($action === 'status') {
            Queue::assertPushed(BroadcastEvent::class, 1);
        } else {
            Queue::assertNothingPushed();
        }
        $this->assertDatabaseCount('bitacoras', 1);
    }

    #[TestWith(['create'])]
    #[TestWith(['update'])]
    #[TestWith(['status'])]
    public function test_rolled_back_user_mutation_does_not_queue_event(string $action): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $original = $user->fresh()->getAttributes();
        Queue::fake([BroadcastEvent::class]);
        Sanctum::actingAs($administrator);
        DB::beginTransaction();

        $this->performBroadcastMutation($action, $user);
        DB::rollBack();

        Queue::assertNothingPushed();
        $this->assertSame($original, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('usuarios', 2);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_reverb_failure_does_not_fail_confirmed_write(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create();
        Exceptions::fake();
        Broadcast::extend('failing', fn () => new class extends NullBroadcaster
        {
            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new RuntimeException('Simulated transport failure');
            }
        });
        config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
        Sanctum::actingAs($administrator);

        $this->performBroadcastMutation('update', $user);

        $this->assertDatabaseHas('usuarios', [
            'id_usuario' => $user->id_usuario,
            'nombre_completo' => 'Nombre confirmado',
        ]);
        $this->assertDatabaseCount('bitacoras', 1);
        Exceptions::assertNothingReported();
    }

    private function performBroadcastMutation(string $action, User $user): TestResponse
    {
        $data = [
            'nombre_completo' => 'Nombre confirmado',
            'nombre_usuario' => $user->nombre_usuario,
            'rol' => $user->rol,
        ];

        return match ($action) {
            'create' => $this->postJson('/api/users', [
                ...$data,
                'nombre_usuario' => 'NUEVO.BROADCAST',
                'contrasena' => 'Password123',
            ])->assertCreated(),
            'update' => $this->patchJson("/api/users/{$user->id_usuario}", $data)->assertOk(),
            'status' => $this->patchJson("/api/users/{$user->id_usuario}/estado", [
                'estado' => 'INACTIVO',
            ])->assertOk(),
        };
    }

    public function test_create_user_command_stores_uppercase_username(): void
    {
        $this->artisan('pizzerp:create-user')
            ->expectsQuestion('Nombre completo', 'Ana Pérez')
            ->expectsQuestion('Nombre de usuario', ' ana.perez ')
            ->expectsChoice('Rol', 'CAJA', User::ROLES)
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, letras y números)', 'Password123')
            ->expectsQuestion('Confirme la contraseña', 'Password123')
            ->expectsOutput('Usuario ANA.PEREZ creado correctamente.')
            ->assertSuccessful();

        $this->assertDatabaseHas('usuarios', [
            'nombre_usuario' => 'ANA.PEREZ',
            'rol' => 'CAJA',
            'estado' => 'ACTIVO',
        ]);
    }

    public function test_create_user_command_rejects_duplicate_username_in_any_case(): void
    {
        User::factory()->create(['nombre_usuario' => 'ANA.PEREZ']);

        $this->artisan('pizzerp:create-user')
            ->expectsQuestion('Nombre completo', 'Ana Pérez')
            ->expectsQuestion('Nombre de usuario', 'ana.perez')
            ->expectsChoice('Rol', 'TI', User::ROLES)
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, letras y números)', 'Password123')
            ->expectsQuestion('Confirme la contraseña', 'Password123')
            ->expectsOutput('El nombre de usuario ya está registrado.')
            ->assertFailed();

        $this->assertDatabaseCount('usuarios', 1);
    }

    private function assertLoginRateLimited(TestResponse $response): void
    {
        $response
            ->assertStatus(429)
            ->assertJsonPath('message', "Se alcanzó el limite de intentos.\n");

        $this->assertIsInt($response->json('retry_after'));
        $this->assertGreaterThan(0, $response->json('retry_after'));
    }

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

    public function test_five_failures_across_usernames_block_only_the_same_browser_before_user_lookup(): void
    {
        $this->withCredentials();
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

        $browserCookie = null;

        foreach (['NO.EXISTE', 'PRIMER.USUARIO', 'OTRO.INEXISTENTE', 'SEGUNDO.USUARIO'] as $username) {
            $response = $this->withCookie('pizzerp_login_browser', $browserCookie ?? '')
                ->postJson('/api/login', [
                    'username' => $username,
                    'password' => 'WrongPassword456',
                ])
                ->assertUnauthorized()
                ->assertExactJson([
                    'message' => "No fue posible iniciar sesión.\nVerifica tus credenciales.",
                ]);
            $browserCookie = $response->getCookie('pizzerp_login_browser')->getValue();
        }

        $this->assertLoginRateLimited(
            $this->withCookie('pizzerp_login_browser', $browserCookie)
                ->postJson('/api/login', [
                    'username' => 'TERCERO.INEXISTENTE',
                    'password' => 'WrongPassword456',
                ])
        );

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            $blockedResponse = $this->withCookie('pizzerp_login_browser', $browserCookie)
                ->postJson('/api/login', [
                    'username' => 'PRIMER.USUARIO',
                    'password' => 'Password123',
                ]);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $this->assertLoginRateLimited($blockedResponse);
        $this->assertCount(0, $queries);
        $this->assertDatabaseCount('bitacoras', 2);

        $this->withCookie('pizzerp_login_browser', '')
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

    public function test_browser_limit_expires_five_minutes_after_fifth_failure_and_valid_login_succeeds(): void
    {
        $this->withCredentials();
        $this->freezeTime();

        $user = User::factory()->create([
            'nombre_usuario' => 'DESPUES.DEL.PLAZO',
        ]);

        config()->set('sanctum.stateful', ['localhost']);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ]);

        $browserCookie = null;

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $response = $this->withCookie('pizzerp_login_browser', $browserCookie ?? '')->postJson('/api/login', [
                'username' => 'NO.EXISTE',
                'password' => 'Password123',
            ])->assertUnauthorized();
            $browserCookie = $response->getCookie('pizzerp_login_browser')->getValue();
            $this->travel(6)->minutes();
        }

        $fifthResponse = $this->withCookie('pizzerp_login_browser', $browserCookie)->postJson('/api/login', [
            'username' => 'NO.EXISTE',
            'password' => 'Password123',
        ]);
        $this->assertLoginRateLimited($fifthResponse);
        $fifthResponse->assertJsonPath('retry_after', 300);

        $this->travel(4)->minutes();
        $blockedResponse = $this->withCookie('pizzerp_login_browser', $browserCookie)->postJson('/api/login', [
            'username' => 'DESPUES.DEL.PLAZO',
            'password' => 'Password123',
        ]);
        $this->assertLoginRateLimited($blockedResponse);
        $blockedResponse->assertJsonPath('retry_after', 60);

        $this->assertDatabaseCount('bitacoras', 0);

        $this->travel(61)->seconds();

        $this->withCookie('pizzerp_login_browser', $browserCookie)->postJson('/api/login', [
            'username' => 'DESPUES.DEL.PLAZO',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.id_usuario', $user->id_usuario);

        $this->assertDatabaseCount('bitacoras', 1);
    }

    public function test_same_ip_does_not_share_browser_limit(): void
    {
        $this->withCredentials();
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.CABECERAS',
        ]);

        config()->set('sanctum.stateful', ['localhost']);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ]);

        $browserCookie = null;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->withCookie('pizzerp_login_browser', $browserCookie ?? '')
                ->withServerVariables(['REMOTE_ADDR' => '192.0.2.40'])
                ->postJson('/api/login', [
                    'username' => 'NO.EXISTE',
                    'password' => 'Password123',
                ]);

            $attempt < 5
                ? $response->assertUnauthorized()
                : $this->assertLoginRateLimited($response);
            $browserCookie = $response->getCookie('pizzerp_login_browser')->getValue();
        }

        $this->assertLoginRateLimited(
            $this->withCookie('pizzerp_login_browser', $browserCookie)
                ->withServerVariables(['REMOTE_ADDR' => '192.0.2.40'])
                ->postJson('/api/login', [
                    'username' => 'USUARIO.CABECERAS',
                    'password' => 'Password123',
                ])
        );

        $this->withCookie('pizzerp_login_browser', '')
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.40'])
            ->postJson('/api/login', [
                'username' => 'USUARIO.CABECERAS',
                'password' => 'Password123',
            ])
            ->assertOk()
            ->assertJsonPath('usuario.id_usuario', $user->id_usuario);
    }

    public function test_browser_limit_follows_cookie_when_ip_changes(): void
    {
        $this->withCredentials();
        $user = User::factory()->create([
            'nombre_usuario' => 'USUARIO.PROXY',
        ]);

        config()->set('sanctum.stateful', ['localhost']);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ]);

        $browserCookie = null;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->withCookie('pizzerp_login_browser', $browserCookie ?? '')
                ->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$attempt}"])
                ->postJson('/api/login', [
                    'username' => 'NO.EXISTE',
                    'password' => 'Password123',
                ]);

            $attempt < 5
                ? $response->assertUnauthorized()
                : $this->assertLoginRateLimited($response);
            $browserCookie = $response->getCookie('pizzerp_login_browser')->getValue();
        }

        $this->assertLoginRateLimited(
            $this->withCookie('pizzerp_login_browser', $browserCookie)
                ->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
                ->postJson('/api/login', [
                    'username' => 'USUARIO.PROXY',
                    'password' => 'Password123',
                ])
        );

        $this->withCookie('pizzerp_login_browser', '')
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/login', [
                'username' => 'USUARIO.PROXY',
                'password' => 'Password123',
            ])
            ->assertOk()
            ->assertJsonPath('usuario.id_usuario', $user->id_usuario);
    }

    public function test_successful_login_clears_only_its_browser_failure_count(): void
    {
        $this->withCredentials();
        config()->set('sanctum.stateful', ['localhost']);
        $this->withHeader('Origin', 'http://localhost');
        $user = User::factory()->create();
        $browserCookie = '';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $this->withCookie('pizzerp_login_browser', $browserCookie)
                ->postJson('/api/login', [
                    'username' => 'NO.EXISTE',
                    'password' => 'Password123',
                ])->assertUnauthorized();
            $browserCookie = $response->getCookie('pizzerp_login_browser')->getValue();
        }

        $this->withCookie('pizzerp_login_browser', $browserCookie)
            ->postJson('/api/login', [
                'username' => $user->nombre_usuario,
                'password' => 'Password123',
            ])->assertOk();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->withCookie('pizzerp_login_browser', $browserCookie)
                ->postJson('/api/login', [
                    'username' => 'NO.EXISTE',
                    'password' => 'Password123',
                ])->assertUnauthorized();
        }

        $this->assertLoginRateLimited($this->withCookie('pizzerp_login_browser', $browserCookie)
            ->postJson('/api/login', [
                'username' => 'NO.EXISTE',
                'password' => 'Password123',
            ]));
        $this->assertSame(0, $user->fresh()->intentos_fallidos);
    }

    public function test_deleting_browser_cookie_does_not_clear_account_lock(): void
    {
        $this->withCredentials();
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withCookie('pizzerp_login_browser', '')
                ->postJson('/api/login', [
                    'username' => $user->nombre_usuario,
                    'password' => 'WrongPassword456',
                ])->assertUnauthorized();
        }

        $this->assertSame(5, $user->fresh()->intentos_fallidos);
        $this->assertTrue($user->fresh()->bloqueado_hasta->isFuture());
        $this->withCookie('pizzerp_login_browser', '')
            ->postJson('/api/login', [
                'username' => $user->nombre_usuario,
                'password' => 'Password123',
            ])->assertUnauthorized()->assertExactJson([
                'message' => "No fue posible iniciar sesión.\nVerifica tus credenciales.",
            ]);
    }

    public function test_forged_browser_cookie_gets_new_identity_without_clearing_original_limit(): void
    {
        $this->withCredentials();
        config()->set('sanctum.stateful', ['localhost']);
        $this->withHeader('Origin', 'http://localhost');
        $user = User::factory()->create();
        $browserCookie = '';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->withCookie('pizzerp_login_browser', $browserCookie)
                ->postJson('/api/login', [
                    'username' => 'NO.EXISTE',
                    'password' => 'Password123',
                ]);
            $browserCookie = $response->getCookie('pizzerp_login_browser')->getValue();
        }
        $this->assertLoginRateLimited($response);

        $forged = $this->withCookie('pizzerp_login_browser', str_repeat('a', 64).'.'.str_repeat('b', 64))
            ->postJson('/api/login', [
                'username' => $user->nombre_usuario,
                'password' => 'Password123',
            ])->assertOk();
        $this->assertNotSame($browserCookie, $forged->getCookie('pizzerp_login_browser')->getValue());
        $this->assertLoginRateLimited($this->withCookie('pizzerp_login_browser', $browserCookie)
            ->postJson('/api/login', [
                'username' => 'NO.EXISTE',
                'password' => 'Password123',
            ]));
    }

    public function test_missing_wrong_inactive_and_blocked_logins_all_count_on_one_browser(): void
    {
        $this->withCredentials();
        config()->set('sanctum.stateful', ['localhost']);
        $this->withHeader('Origin', 'http://localhost');
        $wrongPasswordUser = User::factory()->create();
        $inactiveUser = User::factory()->inactive()->create();
        $blockedUser = User::factory()->create([
            'intentos_fallidos' => 5,
            'bloqueado_hasta' => now()->addMinutes(5),
        ]);
        $browserCookie = '';
        $attempts = [
            ['NO.EXISTE', 'Password123'],
            [$wrongPasswordUser->nombre_usuario, 'WrongPassword456'],
            [$inactiveUser->nombre_usuario, 'Password123'],
            [$blockedUser->nombre_usuario, 'Password123'],
            ['OTRO.INEXISTENTE', 'Password123'],
        ];

        foreach ($attempts as $index => [$username, $password]) {
            $response = $this->withCookie('pizzerp_login_browser', $browserCookie)
                ->postJson('/api/login', compact('username', 'password'));
            $index === 4
                ? $this->assertLoginRateLimited($response)
                : $response->assertUnauthorized()->assertExactJson([
                    'message' => "No fue posible iniciar sesión.\nVerifica tus credenciales.",
                ]);
            $browserCookie = $response->getCookie('pizzerp_login_browser')->getValue();
        }

        $this->assertSame(1, $wrongPasswordUser->fresh()->intentos_fallidos);
        $this->assertSame(0, $inactiveUser->fresh()->intentos_fallidos);
        $this->assertSame(5, $blockedUser->fresh()->intentos_fallidos);
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

    #[TestWith(['ADMINISTRADOR'])]
    #[TestWith(['TI'])]
    public function test_user_manager_cannot_deactivate_itself(string $role): void
    {
        $administrator = User::factory()->create(['rol' => $role]);
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
        $actor = User::factory()->create(['rol' => 'TI']);
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
        $actor = User::factory()->create(['rol' => 'TI']);
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
