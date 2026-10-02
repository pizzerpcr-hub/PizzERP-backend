<?php

namespace Database\Factories;

use App\Models\Combo;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Combo>
 */
class ComboFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo_combo' => fake()->unique()->bothify('COM-########'),
            'nombre' => fake()->words(2, true),
            'descripcion' => fake()->sentence(),
            'precio' => fake()->randomFloat(2, 1, 10000),
            'estado' => 'ACTIVO',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-12-31',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Combo $combo): void {
            $products = Producto::factory()->count(2)->create();
            $combo->productos()->attach($products->modelKeys(), ['cantidad' => 1]);
        });
    }
}
