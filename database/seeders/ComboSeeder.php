<?php

namespace Database\Seeders;

use App\Models\Combo;
use Illuminate\Database\Seeder;

class ComboSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Los combos de ejemplo solo se permiten en local o testing.');
        }
        Combo::factory()->count(3)->create();
    }
}
