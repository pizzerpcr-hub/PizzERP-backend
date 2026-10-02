<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsManagementSchema;
use Tests\TestCase;

class DynamicRoleAccessTest extends TestCase
{
    use BuildsManagementSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildManagementSchema();
    }

    #[TestWith(['ADMINISTRADOR'])]
    #[TestWith(['TI'])]
    #[TestWith(['CAJA'])]
    #[TestWith(['COCINA'])]
    public function test_names_and_system_flag_grant_no_automatic_permissions(string $name): void
    {
        Rol::where('nombre', $name)->update(['permisos' => Rol::emptyPermissions()]);
        Sanctum::actingAs(User::factory()->create(['rol' => $name]));

        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
        $this->postJson('/api/users', [])->assertForbidden();
        $this->patchJson('/api/roles/1', [])->assertForbidden();
        $this->getJson('/api/permissions')->assertOk()->assertJsonPath('permisos.usuarios.ver', false);
    }

    #[TestWith(['CAJA'])]
    #[TestWith(['COCINA'])]
    public function test_saved_permissions_allow_management_for_any_role_name(string $name): void
    {
        Rol::where('nombre', $name)->update(['permisos' => Rol::systemPermissions('ADMINISTRADOR')]);
        Sanctum::actingAs(User::factory()->create(['rol' => $name]));
        $this->getJson('/api/users')->assertOk();
        $this->postJson('/api/roles', ['nombre' => 'NUEVO', 'permisos' => Rol::emptyPermissions()])->assertCreated();
        $ti = Rol::where('nombre', 'TI')->sole();
        $this->patchJson('/api/roles/'.$ti->id_rol, ['nombre' => 'SOPORTE'])->assertOk();
    }

    #[TestWith(['usuarios', 'crear'])]
    #[TestWith(['usuarios', 'ver'])]
    #[TestWith(['usuarios', 'editar'])]
    #[TestWith(['usuarios', 'eliminar'])]
    #[TestWith(['roles', 'crear'])]
    #[TestWith(['roles', 'ver'])]
    #[TestWith(['roles', 'editar'])]
    #[TestWith(['roles', 'eliminar'])]
    public function test_last_manager_cannot_lose_any_essential_permission(string $module, string $action): void
    {
        $role = Rol::factory()->create(['permisos' => $this->managementPermissions()]);
        $actor = User::factory()->create(['rol' => $role->nombre]);
        Sanctum::actingAs($actor);
        $permissions = $role->permisos;
        $permissions[$module][$action] = false;

        $this->patchJson('/api/roles/'.$role->id_rol, ['permisos' => $permissions])
            ->assertUnprocessable()->assertJsonValidationErrors('permisos');

        $this->assertTrue($role->fresh()->permisos[$module][$action]);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_inactive_accounts_or_roles_do_not_count_as_backup_managers(): void
    {
        $role = Rol::factory()->create(['permisos' => $this->managementPermissions()]);
        $actor = User::factory()->create(['rol' => $role->nombre]);
        User::factory()->administrator()->inactive()->create();
        $backup = Rol::factory()->create(['estado' => 'INACTIVO', 'permisos' => $this->managementPermissions()]);
        User::factory()->create(['rol' => $backup->nombre]);
        Sanctum::actingAs($actor);

        $this->patchJson('/api/roles/'.$role->id_rol.'/estado', ['estado' => 'INACTIVO'])
            ->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->assertSame('ACTIVO', $role->fresh()->estado);
        $this->patchJson('/api/users/'.$actor->id_usuario, $this->userData($actor, 'CAJA'))
            ->assertForbidden();
        $empty = Rol::factory()->create();
        $this->patchJson('/api/users/'.$actor->id_usuario, $this->userData($actor, $empty->nombre))
            ->assertUnprocessable()->assertJsonValidationErrors('rol');
        $this->assertSame($role->nombre, $actor->fresh()->rol);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_two_custom_managers_allow_one_removal_but_not_both(): void
    {
        $first = Rol::factory()->create(['permisos' => $this->managementPermissions()]);
        $second = Rol::factory()->create(['permisos' => $this->managementPermissions()]);
        $actor = User::factory()->create(['rol' => $first->nombre]);
        $target = User::factory()->create(['rol' => $second->nombre]);
        Sanctum::actingAs($actor);
        $this->patchJson('/api/users/'.$target->id_usuario.'/estado', ['estado' => 'INACTIVO'])
            ->assertOk()->assertJsonPath('usuario.estado', 'INACTIVO');
        $this->patchJson('/api/roles/'.$first->id_rol.'/estado', ['estado' => 'INACTIVO'])
            ->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->assertSame('ACTIVO', $first->fresh()->estado);
        $this->assertSame($actor->id_usuario, Bitacora::sole()->id_usuario);
    }

    public function test_deactivated_role_removes_access_and_reactivation_restores_it_in_existing_browser_session(): void
    {
        $admin = User::factory()->administrator()->create();
        $role = Rol::where('nombre', 'TI')->sole();
        $target = User::factory()->create(['rol' => $role->nombre]);
        $cookie = $this->loginCookie($target);
        $adminCookie = $this->loginCookie($admin);
        $this->asBrowser($adminCookie);
        $this->patchJson('/api/roles/'.$role->id_rol.'/estado', ['estado' => 'INACTIVO'])->assertOk();
        $this->asBrowser($cookie);
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/permissions')->assertOk()->assertJsonPath('permisos.usuarios.ver', false);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('usuario.rol', 'TI');
        $this->asBrowser($adminCookie);
        $this->patchJson('/api/roles/'.$role->id_rol.'/estado', ['estado' => 'ACTIVO'])->assertOk();
        $this->asBrowser($cookie);
        $this->getJson('/api/users')->assertOk();
        $this->assertSame('TI', $target->fresh()->rol);
    }

    public function test_rename_preserves_two_browser_sessions_assignments_and_permissions(): void
    {
        $target = User::factory()->create(['rol' => 'TI']);
        $first = $this->loginCookie($target);
        $second = $this->loginCookie($target);
        $this->assertNotSame($first, $second);
        $admin = User::factory()->administrator()->create();
        $this->asBrowser($this->loginCookie($admin));
        $role = Rol::where('nombre', 'TI')->sole();
        $this->patchJson('/api/roles/'.$role->id_rol, ['nombre' => ' soporte '])
            ->assertOk()->assertJsonPath('rol.nombre', 'SOPORTE')->assertJsonPath('rol.usuarios_count', 1);

        foreach ([$first, $second] as $cookie) {
            $this->asBrowser($cookie);
            $this->getJson('/api/user')->assertOk()->assertJsonPath('usuario.rol', 'SOPORTE');
            $this->getJson('/api/permissions')->assertOk()->assertJsonPath('permisos.usuarios.editar', true);
            $this->getJson('/api/users')->assertOk();
        }
        $this->assertSame('SOPORTE', $target->fresh()->rol);
        $this->assertSame($admin->id_usuario, Bitacora::where('tipo_movimiento', 'GESTION_ROLES')->sole()->id_usuario);
    }

    public function test_rename_and_cascade_roll_back_when_audit_fails(): void
    {
        $actor = User::factory()->administrator()->create();
        $target = User::factory()->create(['rol' => 'TI']);
        Sanctum::actingAs($actor);
        Event::listen('eloquent.creating: '.Bitacora::class, function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $role = Rol::where('nombre', 'TI')->sole();
        $this->patchJson('/api/roles/'.$role->id_rol, ['nombre' => 'SOPORTE'])->assertStatus(500);
        $this->assertSame('TI', $target->fresh()->rol);
        $this->assertSame('TI', $role->fresh()->nombre);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_administrator_can_remove_its_essential_permissions_when_another_manager_exists(): void
    {
        $actor = User::factory()->administrator()->create();
        User::factory()->create(['rol' => 'TI']);
        $cookie = $this->loginCookie($actor);
        $this->asBrowser($cookie);
        $role = Rol::where('nombre', 'ADMINISTRADOR')->sole();
        $permissions = $role->permisos;
        $permissions['usuarios'] = array_fill_keys(Rol::ACTIONS, false);
        $this->patchJson('/api/roles/'.$role->id_rol, ['permisos' => $permissions])
            ->assertOk()->assertJsonPath('rol.permisos.usuarios.ver', false);

        $this->asBrowser($cookie);
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/permissions')->assertOk()->assertJsonPath('permisos.usuarios.ver', false);
        $this->getJson('/api/roles')->assertOk();
        $this->assertFalse($role->fresh()->permisos['usuarios']['ver']);
    }

    public function test_seeding_again_does_not_restore_renamed_roles_or_overwrite_permissions(): void
    {
        $role = Rol::where('nombre', 'TI')->sole();
        $role->update(['nombre' => 'SOPORTE', 'permisos' => Rol::emptyPermissions()]);
        $this->seed(RolSeeder::class);
        $this->assertDatabaseMissing('roles', ['nombre' => 'TI']);
        $this->assertSame(Rol::emptyPermissions(), $role->fresh()->permisos);
        $this->assertDatabaseCount('roles', 4);
    }

    public function test_console_can_assign_a_renamed_role_without_old_fixed_choices(): void
    {
        Rol::where('nombre', 'TI')->update(['nombre' => 'SOPORTE']);
        $choices = Rol::where('estado', 'ACTIVO')->orderBy('nombre')->pluck('nombre')->all();
        $this->artisan('pizzerp:create-user')
            ->expectsQuestion('Nombre completo', 'Cuenta nueva')
            ->expectsQuestion('Nombre de usuario', 'cuenta.nueva')
            ->expectsChoice('Rol', 'SOPORTE', $choices)
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, letras y números)', 'Password123')
            ->expectsQuestion('Confirme la contraseña', 'Password123')
            ->expectsOutput('Usuario CUENTA.NUEVA creado correctamente.')
            ->assertSuccessful();
        $this->assertDatabaseHas('usuarios', ['nombre_usuario' => 'CUENTA.NUEVA', 'rol' => 'SOPORTE']);
    }

    public function test_role_locks_precede_user_lock_and_unique_is_omitted_for_unchanged_username(): void
    {
        $actor = User::factory()->administrator()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($actor);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $this->patchJson('/api/users/'.$target->id_usuario, [
                ...$this->userData($target, $target->rol), 'nombre_completo' => 'Nombre actualizado',
            ])->assertOk();
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
        }
        $sql = array_column($queries, 'query');
        $roleLock = array_find_key($sql, fn (string $query): bool => str_contains($query, 'from "roles" order by "id_rol"'));
        $userLock = array_find_key($sql, fn (string $query): bool => str_contains($query, 'where "usuarios"."id_usuario"'));
        $this->assertIsInt($roleLock);
        $this->assertIsInt($userLock);
        $this->assertLessThan($userLock, $roleLock);
        $this->assertCount(0, array_filter($sql, fn (string $query): bool => str_contains($query, 'count(')));
    }

    private function managementPermissions(): array
    {
        $permissions = Rol::emptyPermissions();
        foreach (['usuarios', 'roles'] as $module) {
            $permissions[$module] = array_fill_keys(Rol::ACTIONS, true);
        }

        return $permissions;
    }

    private function userData(User $user, string $role): array
    {
        return ['nombre_completo' => $user->nombre_completo, 'nombre_usuario' => $user->nombre_usuario, 'rol' => $role];
    }

    private function loginCookie(User $user): string
    {
        config(['sanctum.stateful' => ['localhost']]);
        auth()->forgetGuards();
        $login = $this->withHeader('Origin', 'http://localhost')->withCredentials()
            ->withCookie(config('session.cookie'), '')->postJson('/api/login', [
                'username' => $user->nombre_usuario, 'password' => 'Password123',
            ])->assertOk();

        return $login->getCookie(config('session.cookie'))->getValue();
    }

    private function asBrowser(string $cookie): void
    {
        auth()->forgetGuards();
        config(['auth.defaults.guard' => 'web']);
        $this->withCookie(config('session.cookie'), $cookie);
    }
}
