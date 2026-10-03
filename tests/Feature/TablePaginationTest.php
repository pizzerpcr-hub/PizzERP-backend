<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Ingrediente;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsManagementSchema;
use Tests\TestCase;

class TablePaginationTest extends TestCase
{
    use BuildsManagementSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildManagementSchema();
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    public function test_categories_return_only_the_requested_page_with_totals(): void
    {
        foreach (range(1, 12) as $number) {
            Categoria::factory()->create(['nombre' => sprintf('Categoria %02d', $number)]);
        }

        $this->getJson('/api/categories?page=2')->assertOk()
            ->assertJsonCount(2, 'categorias')
            ->assertJsonPath('categorias.0.nombre', 'Categoria 11')
            ->assertJsonPath('paginacion.pagina', 2)
            ->assertJsonPath('paginacion.totalPaginas', 2)
            ->assertJsonPath('paginacion.totalElementos', 12)
            ->assertJsonPath('paginacion.inicio', 11)
            ->assertJsonPath('paginacion.fin', 12);

        $this->getJson('/api/categories')->assertOk()->assertJsonCount(12, 'categorias');
        $this->getJson('/api/categories?page=0')->assertUnprocessable();
    }

    public function test_search_is_applied_before_paginating_each_management_table(): void
    {
        foreach (range(1, 10) as $number) {
            Categoria::factory()->create(['nombre' => sprintf('Anterior %02d', $number)]);
        }
        $categoria = Categoria::factory()->create(['nombre' => 'Categoria Aguja']);
        Ingrediente::factory()->create(['nombre' => 'Ingrediente Aguja']);
        Rol::factory()->create(['nombre' => 'ROL AGUJA']);
        Producto::factory()->create(['id_categoria' => $categoria->id_categoria, 'nombre' => 'Pizza base']);
        Combo::factory()->create(['nombre' => 'Combo Aguja']);
        User::factory()->create(['nombre_completo' => 'Usuario Aguja']);

        foreach ([
            '/api/users' => 'usuarios',
            '/api/roles' => 'roles',
            '/api/categories' => 'categorias',
            '/api/products' => 'productos',
            '/api/ingredients' => 'ingredientes',
            '/api/combos' => 'combos',
        ] as $route => $key) {
            $this->getJson($route.'?page=1&search=aguja')->assertOk()
                ->assertJsonCount(1, $key)
                ->assertJsonPath('paginacion.totalElementos', 1);
        }
    }
}
