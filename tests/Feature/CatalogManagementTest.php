<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

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
