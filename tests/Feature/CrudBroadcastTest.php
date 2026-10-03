<?php

namespace Tests\Feature;

use App\Events\ModuleDataChanged;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Ingrediente;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Pusher\Pusher;
use Tests\Support\BuildsManagementSchema;
use Tests\TestCase;

class CrudBroadcastTest extends TestCase
{
    use BuildsManagementSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildManagementSchema();
        Queue::fake();
    }

    #[TestWith(['usuarios'])]
    #[TestWith(['roles'])]
    #[TestWith(['categorias'])]
    #[TestWith(['productos'])]
    #[TestWith(['ingredientes'])]
    #[TestWith(['combos'])]
    public function test_publication_waits_for_outer_commit_and_is_discarded_on_rollback(string $module): void
    {
        $levels = [];
        Event::listen(ModuleDataChanged::class, function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        DB::beginTransaction();
        DB::transaction(fn () => ModuleDataChanged::dispatch($module, 'updated'));
        Queue::assertNothingPushed();
        $this->assertSame([], $levels);
        DB::commit();
        $this->assertSame([0], $levels);
        Queue::assertPushed(BroadcastEvent::class, 1);
        DB::beginTransaction();
        ModuleDataChanged::dispatch($module, 'deleted');
        DB::rollBack();
        $this->assertSame([0], $levels);
        Queue::assertPushed(BroadcastEvent::class, 1);
    }

    public function test_payload_contains_only_module_and_action_and_excludes_the_saving_socket(): void
    {
        request()->headers->set('X-Socket-ID', '123.456');
        $event = new ModuleDataChanged('usuarios', 'updated');
        $this->assertSame(['modulo' => 'usuarios', 'accion' => 'updated'], $event->broadcastWith());
        $this->assertSame('123.456', $event->socket);
        $this->assertSame('deferred', $event->connection);
        $this->assertInstanceOf(ShouldRescue::class, $event);
    }

    public function test_recipients_are_recomputed_at_delivery_and_revoked_sockets_are_excluded(): void
    {
        $role = Rol::factory()->create(['permisos' => ['usuarios' => ['ver' => true]]]);
        $user = User::factory()->create(['rol' => $role->nombre]);
        $event = new ModuleDataChanged('usuarios', 'updated');
        $expected = 'private-crud.usuario.'.$user->getKey().'.usuarios';
        $names = fn (): array => array_map(fn ($channel): string => $channel->name, $event->broadcastOn());
        $this->assertContains($expected, $names());
        $role->update(['permisos' => Rol::emptyPermissions()]);
        $this->assertNotContains($expected, $names());
        $role->update(['permisos' => ['usuarios' => ['ver' => true]], 'estado' => 'INACTIVO']);
        $this->assertNotContains($expected, $names());
        $role->update(['estado' => 'ACTIVO']);
        $user->update(['estado' => 'INACTIVO']);
        $this->assertNotContains($expected, $names());
    }

    public function test_related_module_viewers_receive_invalidations_without_source_module_access(): void
    {
        $role = Rol::factory()->create(['permisos' => ['combos' => ['ver' => true]]]);
        $user = User::factory()->create(['rol' => $role->nombre]);
        $channels = array_map(fn ($channel): string => $channel->name, (new ModuleDataChanged('productos', 'status'))->broadcastOn());
        $this->assertContains('private-crud.usuario.'.$user->getKey().'.combos', $channels);
        $this->assertNotContains('private-crud.usuario.'.$user->getKey().'.productos', $channels);
    }

    #[TestWith(['usuarios'])]
    #[TestWith(['roles'])]
    #[TestWith(['categorias'])]
    #[TestWith(['productos'])]
    #[TestWith(['ingredientes'])]
    #[TestWith(['combos'])]
    public function test_channels_require_own_active_account_and_current_module_view_permission(string $module): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        $this->withoutMiddleware(EnsureSessionIsCurrent::class);
        $role = Rol::factory()->create(['permisos' => [$module => ['ver' => true]]]);
        $user = User::factory()->create(['rol' => $role->nombre]);
        $data = ['socket_id' => '123.456', 'channel_name' => 'private-crud.usuario.'.$user->getKey().'.'.$module];
        $this->postJson('/broadcasting/auth', $data)->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->postJson('/broadcasting/auth', $data)->assertOk();
        $this->postJson('/broadcasting/auth', [...$data, 'channel_name' => 'private-crud.usuario.999.'.$module])->assertForbidden();
        $role->update(['permisos' => Rol::emptyPermissions()]);
        Sanctum::actingAs($user->fresh());
        $this->postJson('/broadcasting/auth', $data)->assertForbidden();
        $role->update(['permisos' => [$module => ['ver' => true]], 'estado' => 'INACTIVO']);
        Sanctum::actingAs($user->fresh());
        $this->postJson('/broadcasting/auth', $data)->assertForbidden();
        $role->update(['estado' => 'ACTIVO']);
        $user->update(['estado' => 'INACTIVO']);
        Sanctum::actingAs($user->fresh());
        $this->postJson('/broadcasting/auth', $data)->assertForbidden();
    }

    public function test_outer_rollback_discards_notice_and_actual_controller_mutation(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        DB::beginTransaction();
        $this->postJson('/api/categories', ['nombre' => 'No confirmar', 'descripcion' => 'Prueba'])->assertCreated();
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('categorias', ['nombre' => 'No confirmar']);
    }

    public function test_existing_mutations_publish_creation_update_status_and_only_existing_deletion(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $published = [];
        Event::listen(ModuleDataChanged::class, function (ModuleDataChanged $event) use (&$published): void {
            $this->assertSame(0, DB::transactionLevel());
            $published[] = [$event->module, $event->action];
        });
        $user = User::factory()->create();
        $category = Categoria::factory()->create();
        $product = Producto::factory()->create(['id_categoria' => $category->getKey()]);
        $otherProduct = Producto::factory()->create(['id_categoria' => $category->getKey()]);
        $ingredient = Ingrediente::factory()->create();
        $role = Rol::factory()->create();
        $combo = Combo::factory()->create();
        $this->postJson('/api/users', ['nombre_completo' => 'Nuevo', 'nombre_usuario' => 'NUEVO',
            'rol' => 'CAJA', 'contrasena' => 'Password123', 'estado' => 'ACTIVO'])->assertCreated();
        $this->postJson('/api/roles', ['nombre' => 'NUEVO', 'permisos' => Rol::emptyPermissions()])->assertCreated();
        $this->postJson('/api/categories', ['nombre' => 'Nueva', 'descripcion' => 'Descripción'])->assertCreated();
        $this->postJson('/api/products', ['id_categoria' => $category->getKey(), 'codigo_producto' => 'NUEVO',
            'nombre' => 'Nuevo', 'descripcion' => 'Descripción', 'precio' => 10])->assertCreated();
        $this->postJson('/api/ingredients', ['nombre' => 'Nuevo', 'unidad_medida' => 'kg'])->assertCreated();
        $this->postJson('/api/combos', ['codigo_combo' => 'NUEVO', 'nombre' => 'Nuevo', 'precio' => 20,
            'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-11-01', 'productos' => [
                ['id_producto' => $product->getKey(), 'cantidad' => 1], ['id_producto' => $otherProduct->getKey(), 'cantidad' => 1],
            ]])->assertCreated();
        foreach (ModuleDataChanged::MODULES as $module) {
            $this->assertContains([$module, 'created'], $published);
        }
        $this->patchJson('/api/users/'.$user->getKey(), ['nombre_completo' => 'Cambio',
            'nombre_usuario' => $user->nombre_usuario, 'rol' => $user->rol])->assertOk();
        $this->patchJson('/api/roles/'.$role->getKey(), ['nombre' => 'RENOMBRADO'])->assertOk();
        $this->patchJson('/api/categories/'.$category->getKey(), ['descripcion' => 'Cambio'])->assertOk();
        $this->patchJson('/api/products/'.$product->getKey(), ['nombre' => 'Cambio'])->assertOk();
        $this->patchJson('/api/ingredients/'.$ingredient->getKey(), ['nombre' => 'Cambio', 'motivo' => 'Corrección'])->assertOk();
        $this->patchJson('/api/combos/'.$combo->getKey(), ['nombre' => 'Cambio'])->assertOk();
        foreach (ModuleDataChanged::MODULES as $module) {
            $this->assertContains([$module, 'updated'], $published);
        }
        foreach ([['users', $user, 'usuarios'], ['roles', $role, 'roles'], ['products', $product, 'productos'],
            ['ingredients', $ingredient, 'ingredientes'], ['combos', $combo, 'combos']] as [$path, $model, $module]) {
            $this->patchJson('/api/'.$path.'/'.$model->getKey().'/estado', ['estado' => 'INACTIVO'])->assertOk();
            $this->assertContains([$module, 'status'], $published);
        }
        $this->patchJson('/api/categories/'.$category->getKey(), ['estado' => 'INACTIVO'])->assertOk();
        $this->assertContains(['categorias', 'status'], $published);
        $this->deleteJson('/api/ingredients/'.$ingredient->getKey())->assertNoContent();
        $this->assertContains(['ingredientes', 'deleted'], $published);
    }

    public function test_reverb_failure_does_not_change_confirmed_http_response(): void
    {
        Exceptions::fake();
        Broadcast::extend('failing-crud', fn () => new class extends NullBroadcaster
        {
            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new \RuntimeException('Simulated CRUD transport failure');
            }
        });
        config(['broadcasting.default' => 'failing-crud', 'broadcasting.connections.failing-crud' => ['driver' => 'failing-crud']]);
        Queue::swap(Queue::getFacadeRoot()->queue);
        Sanctum::actingAs(User::factory()->administrator()->create());
        $this->postJson('/api/categories', ['nombre' => 'Confirmada', 'descripcion' => 'Prueba'])->assertCreated();
        $this->app->make(DeferredCallbackCollection::class)->invoke();
        $this->assertDatabaseHas('categorias', ['nombre' => 'Confirmada']);
        $this->assertDatabaseCount('bitacoras', 1);
        Exceptions::assertReported(\RuntimeException::class);
    }

    #[TestWith(['permissions'])]
    #[TestWith(['inactive'])]
    public function test_two_real_websockets_receive_notice_but_revoked_connection_receives_no_later_notice(string $revocation): void
    {
        if (getenv('RUN_REVERB_DIAGNOSTIC') !== '1') {
            $this->markTestSkipped('Requires isolated Reverb on 127.0.0.1:18080 with test credentials.');
        }
        config(['sanctum.stateful' => ['localhost'], 'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 18080, 'scheme' => 'http', 'useTLS' => false]]);
        require base_path('routes/channels.php');
        $actor = User::factory()->administrator()->create();
        $role = Rol::factory()->create(['permisos' => ['categorias' => ['ver' => true]]]);
        $viewer = User::factory()->create(['rol' => $role->nombre]);
        $this->withHeader('Origin', 'http://localhost')->withCredentials();
        $cookie = config('session.cookie');
        $sessions = [];
        foreach ([$actor, $viewer] as $user) {
            auth()->forgetGuards();
            $response = $this->withCookie($cookie, '')->postJson('/api/login', [
                'username' => $user->nombre_usuario, 'password' => 'Password123',
            ])->assertOk();
            $sessions[] = $response->getCookie($cookie)->getValue();
        }
        $this->assertNotSame($sessions[0], $sessions[1]);
        $clients = [];
        $sockets = [];
        $pusher = new Pusher('test-key', 'test-secret', 'test-app', [
            'host' => '127.0.0.1', 'port' => 18080, 'scheme' => 'http', 'useTLS' => false,
        ]);
        try {
            foreach ([$actor, $viewer] as $index => $user) {
                $process = proc_open(['node', base_path('tests/Support/reverb-probe.mjs')], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, base_path());
                $this->assertIsResource($process);
                $clients[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 8);
                $connected = json_decode((string) fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
                $socket = json_decode($connected['data'], true)['socket_id'];
                $sockets[] = $socket;
                $channel = 'private-crud.usuario.'.$user->getKey().'.categorias';
                auth()->forgetGuards();
                $authorization = $this->withCookie($cookie, $sessions[$index])->postJson('/broadcasting/auth', [
                    'socket_id' => $socket, 'channel_name' => $channel,
                ])->assertOk()->json('auth');
                fwrite($pipes[0], json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $authorization]])."\n");
                $subscribed = json_decode((string) fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('pusher_internal:subscription_succeeded', $subscribed['event']);
            }
            auth()->forgetGuards();
            $this->withCookie($cookie, $sessions[0])->postJson('/api/categories', [
                'nombre' => 'Socket', 'descripcion' => 'Prueba aislada',
            ])->assertCreated();
            $job = Queue::pushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof ModuleDataChanged)->last();
            $job->handle(app(Factory::class));
            foreach ($clients as [, $pipes]) {
                $message = json_decode((string) fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('crud.changed', $message['event']);
                $this->assertSame(['modulo' => 'categorias', 'accion' => 'created'], json_decode($message['data'], true));
            }
            auth()->forgetGuards();
            $this->withCookie($cookie, $sessions[0])->withHeader('X-Socket-ID', $sockets[0]);
            $this->postJson('/api/categories', ['nombre' => 'Otra sesión', 'descripcion' => 'Sin eco propio'])->assertCreated();
            $job = Queue::pushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof ModuleDataChanged)->last();
            $job->handle(app(Factory::class));
            $message = json_decode((string) fgets($clients[1][1][1]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('crud.changed', $message['event']);
            $pusher->trigger('private-crud.usuario.'.$actor->getKey().'.categorias', 'probe.own-socket', []);
            $message = json_decode((string) fgets($clients[0][1][1]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('probe.own-socket', $message['event']);
            $this->withoutHeader('X-Socket-ID');
            auth()->forgetGuards();
            $this->withCookie($cookie, $sessions[0]);
            if ($revocation === 'inactive') {
                $this->patchJson('/api/users/'.$viewer->getKey().'/estado', ['estado' => 'INACTIVO'])->assertOk();
            } else {
                $this->patchJson('/api/roles/'.$role->getKey(), ['permisos' => Rol::emptyPermissions()])->assertOk();
            }
            $this->postJson('/api/categories', ['nombre' => 'Posterior', 'descripcion' => 'Tras revocación'])->assertCreated();
            $job = Queue::pushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof ModuleDataChanged && $job->event->module === 'categorias')->last();
            $job->handle(app(Factory::class));
            $message = json_decode((string) fgets($clients[0][1][1]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('crud.changed', $message['event']);
            // The revoked socket stays subscribed. A barrier proves no notice preceded it.
            $pusher->trigger('private-crud.usuario.'.$viewer->getKey().'.categorias', 'probe.barrier', []);
            $message = json_decode((string) fgets($clients[1][1][1]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('probe.barrier', $message['event']);
            auth()->forgetGuards();
            $this->withCookie($cookie, $sessions[1])->getJson('/api/categories')->assertForbidden();
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
}
