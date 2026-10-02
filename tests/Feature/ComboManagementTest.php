<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Combo;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsManagementSchema;
use Tests\TestCase;

class ComboManagementTest extends TestCase
{
    use BuildsManagementSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildManagementSchema();
    }

    public function test_admin_creates_lists_reads_updates_deactivates_and_reactivates_combo(): void
    {
        $actor = User::factory()->administrator()->create();
        Sanctum::actingAs($actor);
        $data = $this->validData();
        $created = $this->postJson('/api/combos', $data)->assertCreated()
            ->assertJsonPath('combo.codigo_combo', 'COM-01')
            ->assertJsonPath('combo.nombre', 'Combo familiar')
            ->assertJsonPath('combo.precio', '120.50')
            ->assertJsonPath('combo.estado', 'ACTIVO')
            ->assertJsonCount(2, 'combo.productos')
            ->assertJsonPath('combo.productos.0.cantidad', 2);
        $id = $created->json('combo.id_combo');
        $this->getJson('/api/combos')->assertOk()->assertJsonCount(1, 'combos');
        $this->getJson('/api/combos/'.$id)->assertOk()->assertJsonPath('combo.id_combo', $id);
        $this->patchJson('/api/combos/'.$id, ['precio' => '99.99', 'productos' => [
            ['id_producto' => $data['productos'][0]['id_producto'], 'cantidad' => 3],
            ['id_producto' => $data['productos'][1]['id_producto'], 'cantidad' => 1],
        ]])->assertOk()->assertJsonPath('combo.precio', '99.99')->assertJsonPath('combo.productos.0.cantidad', 3);
        foreach (['inactivo' => 'INACTIVO', 'activo' => 'ACTIVO'] as $input => $expected) {
            $this->patchJson('/api/combos/'.$id.'/estado', ['estado' => $input])
                ->assertOk()->assertJsonPath('combo.estado', $expected);
        }
        $this->assertDatabaseCount('combos', 1);
        $this->assertDatabaseCount('combo_producto', 2);
        $this->assertSame(4, Bitacora::where('tipo_movimiento', 'GESTION_COMBOS')->count());
        foreach (Bitacora::all() as $audit) {
            $this->assertSame($actor->id_usuario, $audit->id_usuario);
            $this->assertNotNull($audit->fecha);
            $this->assertStringContainsString('COM-01', $audit->descripcion_movimiento);
        }
    }

    #[TestWith(['CAJA'])]
    #[TestWith(['COCINA'])]
    #[TestWith(['TI'])]
    public function test_legacy_non_admin_roles_have_no_combo_access(string $role): void
    {
        $combo = Combo::factory()->create();
        $this->getJson('/api/combos')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['rol' => $role]));
        $this->getJson('/api/combos')->assertForbidden();
        $this->getJson('/api/combos/'.$combo->id_combo)->assertForbidden();
        $this->postJson('/api/combos', [])->assertForbidden();
        $this->patchJson('/api/combos/'.$combo->id_combo, [])->assertForbidden();
        $this->patchJson('/api/combos/'.$combo->id_combo.'/estado', ['estado' => 'INACTIVO'])->assertForbidden();
    }

    public function test_duplicate_code_invalid_dates_price_and_missing_fields_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        Combo::factory()->create(['codigo_combo' => 'COM-01']);
        $this->postJson('/api/combos', [...$this->validData(), 'precio' => '-1', 'fecha_fin' => '2025-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['codigo_combo', 'precio', 'fecha_fin']);
        $this->postJson('/api/combos', [])->assertUnprocessable()->assertJsonValidationErrors([
            'codigo_combo', 'nombre', 'precio', 'fecha_inicio', 'fecha_fin', 'productos',
        ]);
        $this->assertDatabaseCount('combos', 1);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    #[TestWith(['missing'])]
    #[TestWith(['inactive'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['single'])]
    #[TestWith(['quantity'])]
    public function test_products_must_be_distinct_existing_active_and_have_positive_quantities(string $case): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $data = $this->validData();
        match ($case) {
            'missing' => $data['productos'][1]['id_producto'] = 99999,
            'inactive' => Producto::find($data['productos'][1]['id_producto'])->update(['estado' => 'INACTIVO']),
            'duplicate' => $data['productos'][1]['id_producto'] = $data['productos'][0]['id_producto'],
            'single' => array_pop($data['productos']),
            'quantity' => $data['productos'][0]['cantidad'] = 0,
        };
        $this->postJson('/api/combos', $data)->assertUnprocessable();
        $this->assertDatabaseCount('combos', 0);
        $this->assertDatabaseCount('combo_producto', 0);
        $this->assertDatabaseCount('bitacoras', 0);
    }

    public function test_partial_edit_preserves_date_invariant_and_status_is_separate(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $combo = Combo::factory()->create();
        $this->patchJson('/api/combos/'.$combo->id_combo, ['fecha_inicio' => '2027-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
        $this->patchJson('/api/combos/'.$combo->id_combo, ['estado' => 'INACTIVO'])
            ->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->assertSame('ACTIVO', $combo->fresh()->estado);
    }

    public function test_combo_with_inactive_products_can_be_deactivated_but_not_reactivated(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $combo = Combo::factory()->create();
        $combo->productos()->first()->update(['estado' => 'INACTIVO']);
        $this->patchJson('/api/combos/'.$combo->id_combo.'/estado', ['estado' => 'INACTIVO'])->assertOk();
        $this->patchJson('/api/combos/'.$combo->id_combo.'/estado', ['estado' => 'ACTIVO'])
            ->assertUnprocessable()->assertJsonValidationErrors('productos');
        $this->assertSame('INACTIVO', $combo->fresh()->estado);
    }

    public function test_nonexistent_combo_returns_404_and_inactive_account_is_denied(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $this->getJson('/api/combos/99999')->assertNotFound();
        $this->patchJson('/api/combos/99999', [])->assertNotFound();
        $this->patchJson('/api/combos/99999/estado', ['estado' => 'INACTIVO'])->assertNotFound();
        Sanctum::actingAs(User::factory()->administrator()->inactive()->create());
        $this->getJson('/api/combos')->assertForbidden();
    }

    public function test_audit_failure_rolls_back_combo_and_product_links(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        Event::listen('eloquent.creating: '.Bitacora::class, function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $this->postJson('/api/combos', $this->validData())->assertStatus(500);
        $this->assertDatabaseCount('combos', 0);
        $this->assertDatabaseCount('combo_producto', 0);
    }

    private function validData(): array
    {
        $products = Producto::factory()->count(2)->create();

        return [
            'codigo_combo' => ' com-01 ', 'nombre' => ' Combo familiar ',
            'descripcion' => 'Dos productos', 'precio' => '120.50', 'estado' => 'activo',
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'productos' => $products->map(fn (Producto $product): array => ['id_producto' => $product->id_producto, 'cantidad' => 2])->all(),
        ];
    }
}
