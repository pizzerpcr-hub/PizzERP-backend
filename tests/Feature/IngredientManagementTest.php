<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Ingrediente;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class IngredientManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::connection()->getDriverName() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:'
        ) {
            throw new RuntimeException('Las pruebas de ingredientes requieren SQLite en memoria.');
        }

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
            $table->foreignId('id_usuario')->constrained('usuarios', 'id_usuario');
            $table->string('descripcion_movimiento', 255);
            $table->string('tipo_movimiento', 50);
            $table->string('motivo', 255);
            $table->timestamp('fecha')->useCurrent();
        });

        $migration = require database_path('migrations/2026_10_01_211033_create_ingredientes_table.php');
        $migration->up();
        (require database_path('migrations/2026_10_02_012949_create_roles_table_and_link_usuarios.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ingredientes');
        Schema::dropIfExists('bitacoras');
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('roles');

        parent::tearDown();
    }

    public function test_administrator_creates_lists_and_reads_ingredient_with_audit(): void
    {
        $manager = User::factory()->administrator()->create();
        Sanctum::actingAs($manager);

        $created = $this->postJson('/api/ingredients', [
            'nombre' => '  Harina  ',
            'unidad_medida' => '  kg  ',
            'cantidad_disponible' => '25.50',
            'estado' => 'activo',
        ]);

        $created->assertCreated()
            ->assertJsonPath('ingrediente.nombre', 'Harina')
            ->assertJsonPath('ingrediente.unidad_medida', 'kg')
            ->assertJsonPath('ingrediente.cantidad_disponible', '25.50')
            ->assertJsonPath('ingrediente.estado', 'ACTIVO');

        $id = $created->json('ingrediente.id_ingrediente');

        $this->getJson('/api/ingredients')->assertOk()
            ->assertJsonCount(1, 'ingredientes')
            ->assertJsonPath('ingredientes.0.id_ingrediente', $id);

        $this->getJson("/api/ingredients/{$id}")->assertOk()
            ->assertJsonPath('ingrediente.nombre', 'Harina');

        $this->assertDatabaseHas('ingredientes', ['id_ingrediente' => $id, 'nombre' => 'Harina']);
        $this->assertSame($manager->id_usuario, Bitacora::sole()->id_usuario);
        $this->assertStringContainsString('25.50 kg', Bitacora::sole()->motivo);
    }

    public function test_ti_user_can_create_ingredient_with_defaults(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => 'TI']));

        $this->postJson('/api/ingredients', ['nombre' => 'Queso', 'unidad_medida' => 'kg'])
            ->assertCreated()
            ->assertJsonPath('ingrediente.cantidad_disponible', '0.00')
            ->assertJsonPath('ingrediente.estado', 'ACTIVO');
    }

    public function test_stock_update_records_previous_and_new_quantities(): void
    {
        $manager = User::factory()->administrator()->create();
        $ingredient = Ingrediente::factory()->create([
            'cantidad_disponible' => '10.00',
            'unidad_medida' => 'kg',
        ]);
        Sanctum::actingAs($manager);

        $this->patchJson("/api/ingredients/{$ingredient->id_ingrediente}", [
            'cantidad_disponible' => '7.25',
            'motivo' => 'Corrección por conteo físico',
        ])->assertOk()->assertJsonPath('ingrediente.cantidad_disponible', '7.25');

        $this->assertDatabaseHas('ingredientes', [
            'id_ingrediente' => $ingredient->id_ingrediente,
            'cantidad_disponible' => 7.25,
        ]);
        $this->assertSame($manager->id_usuario, Bitacora::sole()->id_usuario);
        $this->assertSame('MOVIMIENTO_INVENTARIO', Bitacora::sole()->tipo_movimiento);
        $this->assertStringContainsString('Corrección por conteo físico', Bitacora::sole()->motivo);
        $this->assertStringContainsString('Movimiento: SALIDA', Bitacora::sole()->motivo);
        $this->assertStringContainsString('10.00 kg -> 7.25 kg', Bitacora::sole()->motivo);
    }

    public function test_increase_and_unit_change_have_distinct_audit_movements(): void
    {
        $ingredient = Ingrediente::factory()->create([
            'cantidad_disponible' => '5.00',
            'unidad_medida' => 'kg',
        ]);
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->patchJson("/api/ingredients/{$ingredient->id_ingrediente}", [
            'cantidad_disponible' => '8.00',
            'motivo' => 'Compra recibida',
        ])->assertOk();

        $this->assertStringContainsString('Movimiento: ENTRADA', Bitacora::sole()->motivo);

        $this->patchJson("/api/ingredients/{$ingredient->id_ingrediente}", [
            'unidad_medida' => 'g',
            'cantidad_disponible' => '8000.00',
            'motivo' => 'Cambio de unidad',
        ])->assertOk();

        $this->assertDatabaseCount('bitacoras', 2);
        $this->assertStringContainsString(
            'Movimiento: AJUSTE',
            Bitacora::query()->latest('id_bitacora')->firstOrFail()->motivo
        );
    }

    public function test_unchanged_update_does_not_write_audit(): void
    {
        $ingredient = Ingrediente::factory()->create(['cantidad_disponible' => '10.00']);
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->patchJson("/api/ingredients/{$ingredient->id_ingrediente}", [
            'cantidad_disponible' => '10.00',
        ])->assertOk()->assertJsonPath('ingrediente.cantidad_disponible', '10.00');

        $this->assertDatabaseHas('ingredientes', [
            'id_ingrediente' => $ingredient->id_ingrediente,
            'cantidad_disponible' => 10.00,
        ]);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_metadata_update_is_audited_without_changing_stock(): void
    {
        $ingredient = Ingrediente::factory()->create([
            'nombre' => 'Queso',
            'cantidad_disponible' => '10.00',
        ]);
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->patchJson("/api/ingredients/{$ingredient->id_ingrediente}", [
            'nombre' => 'Mozzarella',
            'estado' => 'inactivo',
            'motivo' => 'Cambio de presentación',
        ])->assertOk()
            ->assertJsonPath('ingrediente.nombre', 'Mozzarella')
            ->assertJsonPath('ingrediente.estado', 'INACTIVO')
            ->assertJsonPath('ingrediente.cantidad_disponible', '10.00');

        $this->assertStringContainsString('nombre, estado', Bitacora::sole()->motivo);
        $this->assertStringContainsString('Cambio de presentación', Bitacora::sole()->motivo);
    }

    public function test_update_requires_nonblank_reason_without_changing_ingredient_or_audit(): void
    {
        $ingredient = Ingrediente::factory()->create(['nombre' => 'Queso']);
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->patchJson("/api/ingredients/{$ingredient->id_ingrediente}", [
            'nombre' => 'Mozzarella',
            'motivo' => '   ',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.motivo.0', 'El motivo de la modificación es obligatorio.');

        $this->assertDatabaseHas('ingredientes', [
            'id_ingrediente' => $ingredient->id_ingrediente,
            'nombre' => 'Queso',
        ]);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_deletion_preserves_audit_record(): void
    {
        $manager = User::factory()->administrator()->create();
        $ingredient = Ingrediente::factory()->create([
            'cantidad_disponible' => '4.50',
            'unidad_medida' => 'kg',
        ]);
        Sanctum::actingAs($manager);

        $this->deleteJson("/api/ingredients/{$ingredient->id_ingrediente}")->assertNoContent();

        $this->assertDatabaseMissing('ingredientes', ['id_ingrediente' => $ingredient->id_ingrediente]);
        $this->assertSame($manager->id_usuario, Bitacora::sole()->id_usuario);
        $this->assertStringContainsString('4.50 kg', Bitacora::sole()->motivo);
    }

    public function test_ingredient_status_can_be_changed_without_deleting_it(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $ingredient = Ingrediente::factory()->create(['estado' => 'ACTIVO']);

        $this->patchJson("/api/ingredients/{$ingredient->id_ingrediente}/estado", [
            'estado' => 'inactivo',
        ])->assertOk()->assertJsonPath('ingrediente.estado', 'INACTIVO');

        $this->assertDatabaseHas('ingredientes', [
            'id_ingrediente' => $ingredient->id_ingrediente,
            'estado' => 'INACTIVO',
        ]);
        $this->assertDatabaseCount('bitacoras', 1);

        $this->patchJson("/api/ingredients/{$ingredient->id_ingrediente}/estado", [
            'estado' => 'INACTIVO',
        ])->assertOk();
        $this->assertDatabaseCount('bitacoras', 1);

        $this->patchJson("/api/ingredients/{$ingredient->id_ingrediente}/estado", [
            'estado' => 'ACTIVO',
        ])->assertOk()->assertJsonPath('ingrediente.estado', 'ACTIVO');
        $this->assertDatabaseCount('bitacoras', 2);
    }

    public function test_ingredient_status_rejects_invalid_value(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $ingredient = Ingrediente::factory()->create(['estado' => 'ACTIVO']);

        $this->patchJson("/api/ingredients/{$ingredient->id_ingrediente}/estado", [
            'estado' => 'BORRADO',
        ])->assertUnprocessable()->assertJsonValidationErrors('estado');

        $this->assertSame('ACTIVO', $ingredient->fresh()->estado);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_unauthenticated_user_cannot_list_ingredients(): void
    {
        $this->getJson('/api/ingredients')->assertUnauthorized();
    }

    public function test_cashier_cannot_create_ingredient(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => 'CAJA']));

        $this->postJson('/api/ingredients', ['nombre' => 'Harina', 'unidad_medida' => 'kg'])
            ->assertForbidden();

        $this->assertDatabaseCount('ingredientes', 0);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_name_and_unit_are_required_when_creating_ingredient(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->postJson('/api/ingredients', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.nombre.0', 'El nombre del ingrediente es obligatorio.')
            ->assertJsonPath('errors.unidad_medida.0', 'La unidad de medida es obligatoria.');

        $this->assertDatabaseCount('ingredientes', 0);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    #[TestWith(['-1', 'cantidad_disponible', 'La cantidad disponible no puede ser negativa.'])]
    #[TestWith(['1.234', 'cantidad_disponible', 'La cantidad disponible admite hasta dos decimales.'])]
    #[TestWith(['100000000', 'cantidad_disponible', 'La cantidad disponible no puede superar 99999999.99.'])]
    #[TestWith(['ACTIVADO', 'estado', 'El estado debe ser ACTIVO o INACTIVO.'])]
    public function test_invalid_values_return_validation_error_without_audit(
        string $value,
        string $field,
        string $message
    ): void {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->postJson('/api/ingredients', [
            'nombre' => 'Harina',
            'unidad_medida' => 'kg',
            $field => $value,
        ])->assertUnprocessable()->assertJsonPath("errors.{$field}.0", $message);

        $this->assertDatabaseCount('ingredientes', 0);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_missing_ingredient_returns_not_found_without_audit(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->patchJson('/api/ingredients/999999', ['cantidad_disponible' => '1.00', 'motivo' => 'Conteo'])
            ->assertNotFound();

        $this->assertDatabaseCount('bitacoras', 0);
    }
}
