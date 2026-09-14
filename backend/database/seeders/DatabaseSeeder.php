<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Fase Deploy, sección 13: una demo pública debe arrancar con
        // catálogo (items/recetas/destinos/eventos) pero SIN personajes de
        // prueba. Este usuario de desarrollo solo se crea fuera de
        // producción -`php artisan db:seed --force` en Railway con
        // APP_ENV=production lo salta solo-.
        if (! app()->environment('production')) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        $this->call(ItemSeeder::class);
        $this->call(EconomyItemSeeder::class);
        $this->call(RecipeSeeder::class);
        $this->call(RoomFurnitureSeeder::class);
        $this->call(ArenaEquipmentSeeder::class);
        $this->call(PetSeeder::class);
        $this->call(PetNarrativeEventSeeder::class);
    }
}
