<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Categoria;
use App\Models\Ingrediente;
use App\Models\Producto;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsManagementSchema;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use BuildsManagementSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildManagementSchema();
    }

    public function test_administrator_creates_category_and_product_with_their_relation(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $categoryResponse = $this->postJson('/api/categories', [
            'nombre' => '  Pizzas  ',
            'descripcion' => 'Pizzas artesanales',
        ])->assertCreated()->assertJsonPath('categoria.nombre', 'Pizzas')
            ->assertJsonPath('categoria.productos_count', 0);

        $categoryId = $categoryResponse->json('categoria.id_categoria');

        $this->postJson('/api/products', [
            'id_categoria' => $categoryId,
            'codigo_producto' => ' piz-001 ',
            'nombre' => 'Pizza Suprema',
            'descripcion' => 'Pizza con vegetales y queso',
            'precio' => '12500.50',
        ])->assertCreated()->assertJsonPath('producto.codigo_producto', 'PIZ-001')
            ->assertJsonPath('producto.precio', '12500.50')
            ->assertJsonPath('producto.categoria.nombre', 'Pizzas');

        $this->assertDatabaseHas('productos', [
            'id_categoria' => $categoryId,
            'codigo_producto' => 'PIZ-001',
            'estado' => 'ACTIVO',
        ]);
        $this->getJson('/api/categories')->assertOk()
            ->assertJsonPath('categorias.0.productos_count', 1);
        $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('productos.0.categoria.id_categoria', $categoryId);
        $this->assertSame(2, Bitacora::count());
    }

    public function test_administrator_updates_category_and_product(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $categoria = Categoria::factory()->create();
        $producto = Producto::factory()->create(['id_categoria' => $categoria->id_categoria]);

        $this->patchJson("/api/categories/{$categoria->id_categoria}", [
            'nombre' => 'Bebidas',
            'estado' => 'inactivo',
        ])->assertOk()->assertJsonPath('categoria.nombre', 'Bebidas')
            ->assertJsonPath('categoria.estado', 'INACTIVO');

        $this->patchJson("/api/products/{$producto->id_producto}", [
            'precio' => '1990.75',
            'estado' => 'inactivo',
        ])->assertOk()->assertJsonPath('producto.precio', '1990.75')
            ->assertJsonPath('producto.estado', 'INACTIVO');

        $this->assertDatabaseHas('productos', [
            'id_producto' => $producto->id_producto,
            'precio' => 1990.75,
            'estado' => 'INACTIVO',
        ]);
        $this->assertSame(2, Bitacora::count());
    }

    public function test_product_status_can_change_without_editing_other_fields(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $producto = Producto::factory()->create(['estado' => 'ACTIVO']);

        $this->patchJson("/api/products/{$producto->id_producto}/estado", ['estado' => 'inactivo'])
            ->assertOk()->assertJsonPath('producto.estado', 'INACTIVO');
        $this->assertSame('INACTIVO', $producto->fresh()->estado);
        $this->assertDatabaseCount('bitacoras', 1);

        $this->patchJson("/api/products/{$producto->id_producto}/estado", ['estado' => 'INACTIVO'])
            ->assertOk();
        $this->assertDatabaseCount('bitacoras', 1);

        $this->patchJson("/api/products/{$producto->id_producto}/estado", ['estado' => 'ACTIVO'])
            ->assertOk()->assertJsonPath('producto.estado', 'ACTIVO');
        $this->assertDatabaseCount('bitacoras', 2);
    }

    public function test_product_can_store_and_replace_multiple_ingredients_with_required_quantities(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $categoria = Categoria::factory()->create();
        $harina = Ingrediente::factory()->create(['nombre' => 'Harina', 'unidad_medida' => 'kg']);
        $queso = Ingrediente::factory()->create(['nombre' => 'Queso', 'unidad_medida' => 'kg']);

        $created = $this->postJson('/api/products', [
            'id_categoria' => $categoria->id_categoria,
            'codigo_producto' => 'PIZ-ING',
            'nombre' => 'Pizza con queso',
            'descripcion' => 'Pizza artesanal',
            'precio' => '1500.00',
            'ingredientes' => [
                ['id_ingrediente' => $harina->id_ingrediente, 'cantidad_requerida' => '250', 'unidad_medida' => 'g'],
                ['id_ingrediente' => $queso->id_ingrediente, 'cantidad_requerida' => '0.50'],
            ],
        ])->assertCreated()->assertJsonCount(2, 'producto.ingredientes')
            ->assertJsonPath('producto.ingredientes.0.pivot.unidad_medida', 'g')
            ->assertJsonPath('producto.ingredientes.1.pivot.unidad_medida', 'kg');

        $productoId = $created->json('producto.id_producto');
        $this->assertDatabaseHas('producto_ingredientes', [
            'id_producto' => $productoId,
            'id_ingrediente' => $harina->id_ingrediente,
            'cantidad_requerida' => 250,
            'unidad_medida' => 'g',
        ]);
        $this->assertDatabaseHas('producto_ingredientes', [
            'id_producto' => $productoId,
            'id_ingrediente' => $queso->id_ingrediente,
            'cantidad_requerida' => 0.50,
            'unidad_medida' => 'kg',
        ]);
        $this->assertSame($productoId, $harina->productos()->firstOrFail()->id_producto);

        $this->patchJson("/api/products/{$productoId}", [
            'ingredientes' => [
                ['id_ingrediente' => $queso->id_ingrediente, 'cantidad_requerida' => '750', 'unidad_medida' => 'g'],
            ],
        ])->assertOk()->assertJsonCount(1, 'producto.ingredientes')
            ->assertJsonPath('producto.ingredientes.0.pivot.unidad_medida', 'g');

        $this->assertDatabaseMissing('producto_ingredientes', [
            'id_producto' => $productoId,
            'id_ingrediente' => $harina->id_ingrediente,
        ]);
        $this->assertDatabaseHas('producto_ingredientes', [
            'id_producto' => $productoId,
            'id_ingrediente' => $queso->id_ingrediente,
            'cantidad_requerida' => 750,
            'unidad_medida' => 'g',
        ]);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'productos.0.ingredientes');
        $this->assertSame(2, Bitacora::count());

        $this->patchJson("/api/products/{$productoId}", [
            'ingredientes' => [
                ['id_ingrediente' => $queso->id_ingrediente, 'cantidad_requerida' => '750', 'unidad_medida' => 'g'],
            ],
        ])->assertOk();
        $this->assertSame(2, Bitacora::count());

        $this->patchJson("/api/products/{$productoId}", [
            'ingredientes' => [
                ['id_ingrediente' => $queso->id_ingrediente, 'cantidad_requerida' => '750', 'unidad_medida' => 'kg'],
            ],
        ])->assertOk()->assertJsonPath('producto.ingredientes.0.pivot.unidad_medida', 'kg');
        $this->assertSame(3, Bitacora::count());

        $this->patchJson("/api/products/{$productoId}", ['ingredientes' => []])
            ->assertOk()->assertJsonCount(0, 'producto.ingredientes');
        $this->assertDatabaseCount('producto_ingredientes', 0);
    }

    public function test_product_rejects_invalid_or_repeated_ingredients_without_saving(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $categoria = Categoria::factory()->create();
        $harina = Ingrediente::factory()->create();

        $this->postJson('/api/products', [
            'id_categoria' => $categoria->id_categoria,
            'codigo_producto' => 'PIZ-ING',
            'nombre' => 'Pizza',
            'descripcion' => 'Pizza artesanal',
            'precio' => '1500.00',
            'ingredientes' => [
                ['id_ingrediente' => $harina->id_ingrediente, 'cantidad_requerida' => '0.123'],
                ['id_ingrediente' => $harina->id_ingrediente, 'cantidad_requerida' => '0.00'],
                ['id_ingrediente' => 999999, 'cantidad_requerida' => '1.00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'ingredientes.0.cantidad_requerida',
            'ingredientes.1.id_ingrediente',
            'ingredientes.1.cantidad_requerida',
            'ingredientes.2.id_ingrediente',
        ]);

        $this->assertDatabaseCount('productos', 0);
        $this->assertDatabaseCount('producto_ingredientes', 0);
    }

    public function test_product_rejects_incompatible_ingredient_unit(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $categoria = Categoria::factory()->create();
        $harina = Ingrediente::factory()->create(['unidad_medida' => 'kg']);

        $this->postJson('/api/products', [
            'id_categoria' => $categoria->id_categoria,
            'codigo_producto' => 'PIZ-ING',
            'nombre' => 'Pizza',
            'descripcion' => 'Pizza artesanal',
            'precio' => '1500.00',
            'ingredientes' => [
                ['id_ingrediente' => $harina->id_ingrediente, 'cantidad_requerida' => '1', 'unidad_medida' => 'l'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('ingredientes.0.unidad_medida');

        $this->assertDatabaseCount('productos', 0);
    }

    public function test_product_editor_can_select_only_active_ingredients(): void
    {
        $active = Ingrediente::factory()->create(['nombre' => 'Harina', 'estado' => 'ACTIVO']);
        Ingrediente::factory()->create(['nombre' => 'Levadura', 'estado' => 'INACTIVO']);
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->getJson('/api/products/ingredientes')->assertOk()
            ->assertJsonCount(1, 'ingredientes')
            ->assertJsonPath('ingredientes.0.id_ingrediente', $active->id_ingrediente)
            ->assertJsonPath('ingredientes.0.unidad_medida', $active->unidad_medida);
    }

    public function test_product_rejects_missing_category_duplicate_code_and_invalid_price(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $producto = Producto::factory()->create(['codigo_producto' => 'PIZ-001']);

        $this->postJson('/api/products', [
            'id_categoria' => 99999,
            'codigo_producto' => 'PIZ-001',
            'nombre' => 'Otra pizza',
            'descripcion' => 'Descripción válida',
            'precio' => '1.234',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'id_categoria', 'codigo_producto', 'precio',
        ]);

        $this->assertSame(1, Producto::count());
        $this->assertSame('PIZ-001', $producto->fresh()->codigo_producto);
    }

    public function test_category_and_product_require_administrator_access(): void
    {
        $this->getJson('/api/categories')->assertUnauthorized();
        $this->getJson('/api/products')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['rol' => 'TI']));

        $this->postJson('/api/categories', [
            'nombre' => 'Pizzas', 'descripcion' => 'Pizzas artesanales',
        ])->assertForbidden();
        $this->postJson('/api/products', [
            'id_categoria' => 1, 'codigo_producto' => 'PIZ-001',
            'nombre' => 'Pizza', 'descripcion' => 'Pizza artesanal', 'precio' => 1000,
        ])->assertForbidden();

        $this->assertSame(0, Categoria::count());
        $this->assertSame(0, Producto::count());
    }
}
