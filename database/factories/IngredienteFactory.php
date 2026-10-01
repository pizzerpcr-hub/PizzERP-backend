<?php

namespace Database\Factories;

use App\Models\Ingrediente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingrediente>
 */
class IngredienteFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(2, true),
            'unidad_medida' => fake()->randomElement(['kg', 'g', 'l', 'unidad']),
            'cantidad_disponible' => fake()->randomFloat(2, 0, 1000),
            'estado' => 'ACTIVO',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'estado' => 'INACTIVO',
        ]);
    }
}
