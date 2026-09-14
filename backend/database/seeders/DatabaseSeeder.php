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

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(ItemSeeder::class);
        $this->call(EconomyItemSeeder::class);
        $this->call(RecipeSeeder::class);
        $this->call(RoomFurnitureSeeder::class);
        $this->call(ArenaEquipmentSeeder::class);
        $this->call(PetSeeder::class);
        $this->call(PetNarrativeEventSeeder::class);
    }
}
