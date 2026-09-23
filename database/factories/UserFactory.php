<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre_completo' => fake()->name(),
            'nombre_usuario' => mb_strtoupper(
                fake()->unique()->userName()
            ),
            'contrasena_hash' => static::$password ??=
                Hash::make('Password123'),
            'rol' => 'CAJA',
            'estado' => 'ACTIVO',
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function administrator(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rol' => 'ADMINISTRADOR',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'estado' => 'INACTIVO',
        ]);
    }
}
