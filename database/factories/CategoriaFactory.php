<?php

namespace Database\Factories;

use App\Models\Categoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Categoria>
 */
class CategoriaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(2, true),
            'descripcion' => fake()->sentence(),
            'estado' => 'ACTIVO',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['estado' => 'INACTIVO']);
    }
}
