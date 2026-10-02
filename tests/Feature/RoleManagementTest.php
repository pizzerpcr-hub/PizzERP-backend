<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsManagementSchema;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use BuildsManagementSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildManagementSchema();
    }

    public function test_admin_creates_lists_reads_edits_and_changes_role_status_with_audit(): void
    {
        $actor = User::factory()->administrator()->create();
        Sanctum::actingAs($actor);
        $permissions = $this->permissions('productos', ['ver']);
        $response = $this->postJson('/api/roles', ['nombre' => ' supervisor ', 'permisos' => $permissions])
            ->assertCreated()->assertJsonPath('rol.nombre', 'SUPERVISOR')
            ->assertJsonPath('rol.es_sistema', false)->assertJsonPath('rol.estado', 'ACTIVO')
            ->assertJsonPath('rol.permisos.productos.ver', true)->assertJsonPath('rol.usuarios_count', 0);
        $id = $response->json('rol.id_rol');
        $this->getJson('/api/roles')->assertOk()->assertJsonCount(5, 'roles');
        $this->getJson('/api/roles/'.$id)->assertOk()->assertJsonPath('rol.nombre', 'SUPERVISOR');
        $this->patchJson('/api/roles/'.$id, ['nombre' => 'auditor', 'permisos' => $this->permissions('productos', ['ver', 'editar'])])
            ->assertOk()->assertJsonPath('rol.nombre', 'AUDITOR')->assertJsonPath('rol.permisos.productos.editar', true);
        foreach (['inactivo' => 'INACTIVO', 'activo' => 'ACTIVO'] as $input => $expected) {
            $this->patchJson('/api/roles/'.$id.'/estado', ['estado' => $input])->assertOk()->assertJsonPath('rol.estado', $expected);
        }
        $this->assertDatabaseCount('roles', 5);
        $this->assertSame(4, Bitacora::where('tipo_movimiento', 'GESTION_ROLES')->count());
        foreach (Bitacora::all() as $audit) {
            $this->assertSame($actor->id_usuario, $audit->id_usuario);
            $this->assertNotNull($audit->fecha);
            $this->assertStringContainsString('Rol ', $audit->descripcion_movimiento);
        }
    }

    #[TestWith(['ADMINISTRADOR'])]
    #[TestWith(['TI'])]
    #[TestWith(['CAJA'])]
    #[TestWith(['COCINA'])]
    public function test_initial_roles_keep_permissions_and_assignments_when_renamed_and_allow_status_changes(string $name): void
    {
        $actor = User::factory()->administrator()->create();
        Sanctum::actingAs($actor);
        $backupRole = Rol::factory()->create(['permisos' => Rol::systemPermissions('ADMINISTRADOR')]);
        $backup = User::factory()->create(['rol' => $backupRole->nombre]);
        $role = Rol::where('nombre', $name)->sole();
        $assigned = User::factory()->create(['rol' => $name]);
        $this->assertTrue($role->es_sistema);
        $this->assertSame(Rol::systemPermissions($name), $role->permisos);
        $this->patchJson('/api/roles/'.$role->id_rol, ['nombre' => 'OTRO'])
            ->assertOk()->assertJsonPath('rol.nombre', 'OTRO');
        $this->assertSame('OTRO', $assigned->fresh()->rol);
        Sanctum::actingAs($actor->fresh());
        $this->patchJson('/api/roles/'.$role->id_rol.'/estado', ['estado' => 'INACTIVO'])
            ->assertOk()->assertJsonPath('rol.estado', 'INACTIVO');
        Sanctum::actingAs($backup);
        $this->patchJson('/api/roles/'.$role->id_rol.'/estado', ['estado' => 'ACTIVO'])->assertOk();
        $this->postJson('/api/roles', ['nombre' => strtolower($name), 'permisos' => Rol::emptyPermissions()])
            ->assertCreated();
        $this->assertSame('OTRO', $role->fresh()->nombre);
        $this->assertSame(Rol::systemPermissions($name), $role->fresh()->permisos);
        $this->assertSame('ACTIVO', $role->fresh()->estado);
    }

    public function test_administrator_can_edit_system_role_permissions_and_access_changes_on_next_request(): void
    {
        $admin = User::factory()->administrator()->create();
        $cashier = User::factory()->create(['rol' => 'CAJA']);
        Sanctum::actingAs($admin);
        $role = Rol::where('nombre', 'CAJA')->sole();
        $permissions = $role->permisos;
        $permissions['productos']['ver'] = true;
        $this->patchJson('/api/roles/'.$role->id_rol, ['permisos' => $permissions])
            ->assertOk()->assertJsonPath('rol.permisos.productos.ver', true);
        Sanctum::actingAs($cashier);
        $this->getJson('/api/products')->assertOk();
        $this->assertSame($admin->id_usuario, Bitacora::sole()->id_usuario);
    }

    public function test_admin_cannot_remove_essential_permissions_or_deactivate_admin_role(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $role = Rol::where('nombre', 'ADMINISTRADOR')->sole();
        $permissions = $role->permisos;
        $permissions['usuarios']['ver'] = false;
        $this->patchJson('/api/roles/'.$role->id_rol, ['permisos' => $permissions])
            ->assertUnprocessable()->assertJsonValidationErrors('permisos');
        $this->assertTrue($role->fresh()->permisos['usuarios']['ver']);
        $this->patchJson('/api/roles/'.$role->id_rol.'/estado', ['estado' => 'INACTIVO'])
            ->assertUnprocessable()->assertJsonValidationErrors('estado');
    }

    public function test_ti_can_edit_its_role_and_disabled_permission_blocks_api(): void
    {
        $admin = User::factory()->administrator()->create();
        $ti = User::factory()->create(['rol' => 'TI']);
        $role = Rol::where('nombre', 'TI')->sole();
        Sanctum::actingAs($ti);
        $this->patchJson('/api/roles/'.$role->id_rol, ['permisos' => $role->permisos])->assertOk();
        Sanctum::actingAs($admin);
        $permissions = $role->permisos;
        $permissions['usuarios']['ver'] = false;
        $this->patchJson('/api/roles/'.$role->id_rol, ['permisos' => $permissions])->assertOk();
        Sanctum::actingAs($ti);
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/roles')->assertOk();
    }

    public function test_selection_endpoints_require_relevant_permissions(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $this->getJson('/api/users/roles')->assertOk()->assertJsonCount(4, 'roles');
        $this->getJson('/api/combos/productos')->assertOk()->assertJsonCount(0, 'productos');
        $this->getJson('/api/products/categorias')->assertOk()->assertJsonCount(0, 'categorias');
        Sanctum::actingAs(User::factory()->create(['rol' => 'CAJA']));
        $this->getJson('/api/users/roles')->assertForbidden();
        $this->getJson('/api/combos/productos')->assertForbidden();
        $this->getJson('/api/products/categorias')->assertForbidden();
    }

    public function test_permission_matrix_rejects_unknown_missing_and_non_boolean_values(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $permissions = Rol::emptyPermissions();
        $permissions['servidor'] = ['crear' => true];
        $permissions['usuarios']['extra'] = true;
        $permissions['roles']['crear'] = 'false';
        unset($permissions['combos']['editar']);
        $this->postJson('/api/roles', ['nombre' => 'AUDITOR', 'permisos' => $permissions])
            ->assertUnprocessable()->assertJsonValidationErrors(['permisos', 'permisos.usuarios', 'permisos.roles.crear', 'permisos.combos.editar']);
        $this->postJson('/api/roles', ['nombre' => 'AUDITOR', 'permisos' => Rol::emptyPermissions(), 'es_sistema' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('es_sistema');
        $this->assertDatabaseCount('roles', 4);
    }

    #[TestWith(['CAJA', 'ACTIVO'])]
    #[TestWith(['COCINA', 'ACTIVO'])]
    #[TestWith(['ADMINISTRADOR', 'INACTIVO'])]
    #[TestWith(['TI', 'INACTIVO'])]
    public function test_roles_deny_unauthenticated_inactive_and_other_system_roles(string $name, string $status): void
    {
        $role = Rol::factory()->create();
        $this->getJson('/api/roles')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['rol' => $name, 'estado' => $status]));
        $this->getJson('/api/roles')->assertForbidden();
        $this->getJson('/api/roles/'.$role->id_rol)->assertForbidden();
        $this->postJson('/api/roles', [])->assertForbidden();
        $this->patchJson('/api/roles/'.$role->id_rol, [])->assertForbidden();
        $this->patchJson('/api/roles/'.$role->id_rol.'/estado', ['estado' => 'INACTIVO'])->assertForbidden();
    }

    public function test_ti_can_manage_only_roles_with_permissions_it_possesses(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => 'TI']));
        $this->postJson('/api/roles', ['nombre' => 'AYUDANTE', 'permisos' => $this->permissions('ingredientes', ['ver'])])->assertCreated();
        $this->postJson('/api/roles', ['nombre' => 'VENTAS', 'permisos' => $this->permissions('productos', ['crear'])])->assertForbidden();
        $role = Rol::factory()->create(['permisos' => $this->permissions('productos', ['ver'])]);
        $this->patchJson('/api/roles/'.$role->id_rol, ['permisos' => Rol::emptyPermissions()])->assertForbidden();
        $this->patchJson('/api/roles/'.$role->id_rol.'/estado', ['estado' => 'INACTIVO'])->assertForbidden();
    }

    public function test_custom_role_is_assignable_through_creation_and_editing_users(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $role = Rol::factory()->create(['nombre' => 'AUDITOR', 'permisos' => $this->permissions('productos', ['ver'])]);
        $created = $this->postJson('/api/users', [
            'nombre_completo' => 'Usuario Auditor', 'nombre_usuario' => 'auditor', 'contrasena' => 'Password123', 'rol' => ' auditor ',
        ])->assertCreated()->assertJsonPath('usuario.rol', 'AUDITOR');
        $id = $created->json('usuario.id_usuario');
        $this->assertCount(5, $created->json('usuario'));
        $target = User::factory()->create();
        $originalHash = $target->contrasena_hash;
        $this->patchJson('/api/users/'.$target->id_usuario, [
            'nombre_completo' => $target->nombre_completo, 'nombre_usuario' => $target->nombre_usuario, 'rol' => $role->nombre,
        ])->assertOk()->assertJsonPath('usuario.rol', 'AUDITOR');
        $this->assertSame($originalHash, $target->fresh()->contrasena_hash);
        $this->assertTrue(Hash::check('Password123', User::find($id)->contrasena_hash));
        $role->update(['estado' => 'INACTIVO']);
        $this->patchJson('/api/users/'.$target->id_usuario, [
            'nombre_completo' => $target->nombre_completo, 'nombre_usuario' => $target->nombre_usuario, 'rol' => $role->nombre,
        ])->assertUnprocessable()->assertJsonValidationErrors('rol');
    }

    public function test_database_foreign_key_rejects_unknown_user_role(): void
    {
        $this->expectException(QueryException::class);
        User::factory()->create(['rol' => 'INEXISTENTE']);
    }

    public function test_assigned_role_can_be_renamed_and_permissions_can_change(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $role = Rol::factory()->create();
        $user = User::factory()->create(['rol' => $role->nombre]);
        $this->patchJson('/api/roles/'.$role->id_rol, ['nombre' => 'NUEVO'])->assertOk();
        $this->patchJson('/api/roles/'.$role->id_rol, ['permisos' => $this->permissions('productos', ['ver'])])->assertOk();
        $this->assertSame('NUEVO', $user->fresh()->rol);
    }

    public function test_permissions_are_checked_per_action_on_every_existing_management_module(): void
    {
        $role = Rol::factory()->create(['permisos' => $this->permissions('productos', ['ver'])]);
        Sanctum::actingAs(User::factory()->create(['rol' => $role->nombre]));
        $this->getJson('/api/products')->assertOk();
        foreach (['users', 'categories', 'ingredients', 'combos', 'roles'] as $route) {
            $this->getJson('/api/'.$route)->assertForbidden();
        }
        $this->postJson('/api/products', [])->assertForbidden();
        $product = Producto::factory()->create();
        $this->patchJson('/api/products/'.$product->id_producto, ['precio' => '5'])->assertForbidden();
        $this->getJson('/api/permissions')->assertOk()->assertJsonPath('permisos.productos.ver', true);
    }

    #[TestWith(['users', 'usuarios'])]
    #[TestWith(['categories', 'categorias'])]
    #[TestWith(['products', 'productos'])]
    #[TestWith(['ingredients', 'ingredientes'])]
    #[TestWith(['combos', 'combos'])]
    #[TestWith(['roles', 'roles'])]
    public function test_create_permission_does_not_imply_read_or_edit_permission(string $route, string $module): void
    {
        $role = Rol::factory()->create(['permisos' => $this->permissions($module, ['crear'])]);
        Sanctum::actingAs(User::factory()->create(['rol' => $role->nombre]));
        $this->getJson('/api/'.$route)->assertForbidden();
        $this->postJson('/api/'.$route, [])->assertUnprocessable();
    }

    public function test_edit_permission_cannot_be_used_to_deactivate_existing_catalog_records(): void
    {
        $role = Rol::factory()->create(['permisos' => $this->permissions('productos', ['editar'])]);
        Sanctum::actingAs(User::factory()->create(['rol' => $role->nombre]));
        $product = Producto::factory()->create();
        $this->patchJson('/api/products/'.$product->id_producto, ['precio' => '5.00'])->assertOk();
        $this->patchJson('/api/products/'.$product->id_producto, ['estado' => 'INACTIVO'])->assertForbidden();
        $this->assertSame('ACTIVO', $product->fresh()->estado);
        $product->update(['estado' => 'INACTIVO']);
        $this->patchJson('/api/products/'.$product->id_producto, ['nombre' => 'Producto corregido', 'estado' => 'INACTIVO'])
            ->assertOk()->assertJsonPath('producto.nombre', 'Producto corregido');
    }

    public function test_permissions_and_inactive_role_are_rechecked_on_next_request_in_same_session(): void
    {
        $role = Rol::factory()->create(['permisos' => $this->permissions('productos', ['ver'])]);
        Sanctum::actingAs(User::factory()->create(['rol' => $role->nombre]));
        $this->getJson('/api/products')->assertOk();
        $role->update(['permisos' => Rol::emptyPermissions()]);
        $this->getJson('/api/products')->assertForbidden();
        $role->update(['permisos' => $this->permissions('productos', ['ver']), 'estado' => 'ACTIVO']);
        $this->getJson('/api/products')->assertOk();
        $role->update(['estado' => 'INACTIVO']);
        $this->getJson('/api/products')->assertForbidden();
        $this->getJson('/api/permissions')->assertOk()->assertJsonPath('permisos.productos.ver', false);
    }

    public function test_custom_combo_permissions_allow_real_operations_but_not_role_management(): void
    {
        $role = Rol::factory()->create(['permisos' => $this->permissions('combos', Rol::ACTIONS)]);
        Sanctum::actingAs(User::factory()->create(['rol' => $role->nombre]));
        $products = Producto::factory()->count(2)->create();
        $created = $this->postJson('/api/combos', [
            'codigo_combo' => 'COM-CUSTOM', 'nombre' => 'Combo especial', 'precio' => '15.00',
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'productos' => $products->map(fn (Producto $product): array => ['id_producto' => $product->id_producto, 'cantidad' => 1])->all(),
        ])->assertCreated();
        $id = $created->json('combo.id_combo');
        $this->getJson('/api/combos/'.$id)->assertOk();
        $this->patchJson('/api/combos/'.$id, ['precio' => '20.00'])->assertOk();
        $this->patchJson('/api/combos/'.$id.'/estado', ['estado' => 'INACTIVO'])->assertOk();
        $this->patchJson('/api/combos/'.$id.'/estado', ['estado' => 'ACTIVO'])->assertOk();
        $this->postJson('/api/roles', [])->assertForbidden();
    }

    public function test_custom_user_permissions_allow_assignment_without_leaking_password_fields(): void
    {
        $role = Rol::factory()->create(['permisos' => $this->permissions('usuarios', Rol::ACTIONS)]);
        Sanctum::actingAs(User::factory()->create(['rol' => $role->nombre]));
        $created = $this->postJson('/api/users', [
            'nombre_completo' => 'Usuario personalizado', 'nombre_usuario' => 'personalizado',
            'contrasena' => 'Password123', 'rol' => $role->nombre,
        ])->assertCreated();
        $id = $created->json('usuario.id_usuario');
        $this->assertCount(5, $created->json('usuario'));
        $this->patchJson('/api/users/'.$id, [
            'nombre_completo' => 'Nombre actualizado', 'nombre_usuario' => 'personalizado', 'rol' => $role->nombre,
        ])->assertOk()->assertJsonPath('usuario.nombre_completo', 'Nombre actualizado');
        $this->patchJson('/api/users/'.$id.'/estado', ['estado' => 'INACTIVO'])->assertOk();
        $this->patchJson('/api/users/'.$id.'/estado', ['estado' => 'ACTIVO'])->assertOk();
        $this->patchJson('/api/users/'.auth()->id().'/estado', ['estado' => 'INACTIVO'])
            ->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->getJson('/api/users')->assertOk()->assertJsonMissingPath('usuarios.0.contrasena_hash');
    }

    public function test_custom_role_login_and_identity_restoration_preserve_public_response(): void
    {
        $role = Rol::factory()->create(['permisos' => $this->permissions('productos', ['ver'])]);
        $user = User::factory()->create(['rol' => $role->nombre]);
        config(['sanctum.stateful' => ['localhost']]);
        $login = $this->withHeader('Origin', 'http://localhost')->withCredentials()->postJson('/api/login', [
            'username' => $user->nombre_usuario, 'password' => 'Password123',
        ])->assertOk()->assertJsonPath('usuario.rol', $role->nombre);
        $cookie = $login->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);
        auth()->forgetGuards();
        $this->withCookie(config('session.cookie'), $cookie->getValue())->getJson('/api/user')
            ->assertOk()->assertJsonPath('usuario.rol', $role->nombre)->assertJsonMissingPath('usuario.contrasena_hash');
        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_dynamic_user_manager_cannot_escalate_to_or_edit_system_managers(): void
    {
        $role = Rol::factory()->create(['permisos' => $this->permissions('usuarios', Rol::ACTIONS)]);
        $actor = User::factory()->create(['rol' => $role->nombre]);
        $admin = User::factory()->administrator()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($actor);
        $this->patchJson('/api/users/'.$admin->id_usuario, [
            'nombre_completo' => $admin->nombre_completo, 'nombre_usuario' => $admin->nombre_usuario, 'rol' => 'CAJA',
        ])->assertForbidden();
        $this->patchJson('/api/users/'.$admin->id_usuario.'/estado', ['estado' => 'INACTIVO'])->assertForbidden();
        $this->patchJson('/api/users/'.$target->id_usuario, [
            'nombre_completo' => $target->nombre_completo, 'nombre_usuario' => $target->nombre_usuario, 'rol' => 'ADMINISTRADOR',
        ])->assertForbidden();
        $this->assertSame('CAJA', $target->fresh()->rol);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_another_permission_manager_allows_demoting_and_deactivating_administrator(): void
    {
        $admin = User::factory()->administrator()->create();
        $role = Rol::factory()->create();
        Rol::where('nombre', 'TI')->update(['permisos' => Rol::systemPermissions('ADMINISTRADOR')]);
        Sanctum::actingAs(User::factory()->create(['rol' => 'TI']));
        $this->patchJson('/api/users/'.$admin->id_usuario, [
            'nombre_completo' => $admin->nombre_completo, 'nombre_usuario' => $admin->nombre_usuario, 'rol' => $role->nombre,
        ])->assertOk()->assertJsonPath('usuario.rol', $role->nombre);
        $this->patchJson('/api/users/'.$admin->id_usuario.'/estado', ['estado' => 'INACTIVO'])
            ->assertOk()->assertJsonPath('usuario.estado', 'INACTIVO');
    }

    public function test_missing_role_returns_404_and_duplicate_name_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $this->getJson('/api/roles/99999')->assertNotFound();
        $this->patchJson('/api/roles/99999', [])->assertNotFound();
        $this->patchJson('/api/roles/99999/estado', ['estado' => 'INACTIVO'])->assertNotFound();
        $role = Rol::factory()->create(['nombre' => 'SUPERVISOR']);
        $this->postJson('/api/roles', ['nombre' => ' supervisor ', 'permisos' => Rol::emptyPermissions()])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->patchJson('/api/roles/'.$role->id_rol.'/estado', ['estado' => 'OTRO'])->assertUnprocessable();
    }

    public function test_audit_failure_rolls_back_role(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        Event::listen('eloquent.creating: '.Bitacora::class, function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $this->postJson('/api/roles', ['nombre' => 'SUPERVISOR', 'permisos' => Rol::emptyPermissions()])->assertStatus(500);
        $this->assertDatabaseCount('roles', 4);
    }

    #[TestWith(['ACTIVO', 'INACTIVO'])]
    #[TestWith(['INACTIVO', 'ACTIVO'])]
    public function test_status_change_returns_confirmed_role_with_one_locked_lookup_and_atomic_audit(string $previous, string $next): void
    {
        $actor = User::factory()->administrator()->create();
        $role = Rol::factory()->create(['estado' => $previous]);
        Sanctum::actingAs($actor);
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            $response = $this->patchJson('/api/roles/'.$role->id_rol.'/estado', ['estado' => $next]);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $response->assertOk()->assertJsonPath('rol.estado', $next)->assertJsonPath('rol.usuarios_count', 0);
        $this->assertCount(6, $queries);
        $this->assertCount(1, array_filter($queries, fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select * from "roles" order by')
        ));
        $this->assertCount(0, array_filter($queries, fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select * from "roles" where "roles"."id_rol"')));
        $this->assertSame($next, $role->fresh()->estado);
        $this->assertSame($actor->id_usuario, Bitacora::sole()->id_usuario);
        $this->assertStringContainsString($next, Bitacora::sole()->descripcion_movimiento);
    }

    private function permissions(string $module, array $actions): array
    {
        $permissions = Rol::emptyPermissions();
        foreach ($actions as $action) {
            $permissions[$module][$action] = true;
        }

        return $permissions;
    }
}
