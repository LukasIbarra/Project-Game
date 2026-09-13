<?php

namespace Database\Seeders;

use App\Models\PetDestination;
use Illuminate\Database\Seeder;

// Fase 7: los 3 destinos iniciales pedidos por la fase. `loot_pool_json`
// referencia claves de ItemSeeder -reutiliza el catálogo de F6, no crea
// uno paralelo-. loot_min_tier/loot_max_tier son solo descriptivos para
// la UI ("Básico -> Raro"), no hay un sistema de tiers que filtre loot
// por ellos todavía.
class PetSeeder extends Seeder
{
    public function run(): void
    {
        $destinations = [
            [
                'key' => 'forest',
                'name' => 'Bosque',
                'difficulty' => 2,
                'duration_minutes' => 120,
                'loot_min_tier' => 'basic',
                'loot_max_tier' => 'rare',
                'loot_pool_json' => ['resource_wood', 'resource_herb', 'resource_berry', 'resource_stone'],
            ],
            [
                'key' => 'mountains',
                'name' => 'Montañas',
                'difficulty' => 3,
                'duration_minutes' => 300,
                'loot_min_tier' => 'rare',
                'loot_max_tier' => 'mythic',
                'loot_pool_json' => ['resource_iron_ore', 'resource_coal', 'resource_crystal', 'resource_rare_ore'],
            ],
            [
                'key' => 'blood_castle',
                'name' => 'Castillo Sangriento',
                'difficulty' => 5,
                'duration_minutes' => 720,
                'loot_min_tier' => 'mythic',
                'loot_max_tier' => 'legendary',
                'loot_pool_json' => ['resource_blood_crystal', 'resource_ancient_fragment', 'resource_mythic_material', 'resource_legendary_fragment'],
            ],
        ];

        foreach ($destinations as $destination) {
            PetDestination::updateOrCreate(
                ['key' => $destination['key']],
                $destination
            );
        }
    }
}
