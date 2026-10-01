<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id_categoria' => Categoria::factory(),
            'codigo_producto' => fake()->unique()->bothify('PROD-#####'),
            'nombre' => fake()->words(2, true),
            'descripcion' => fake()->sentence(),
            'precio' => fake()->randomFloat(2, 1, 10000),
            'estado' => 'ACTIVO',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['estado' => 'INACTIVO']);
    }
}
