<?php

namespace Database\Seeders;

use App\Models\PetDestination;
use Illuminate\Database\Seeder;

// Fase 7: los 3 destinos iniciales pedidos por la fase. `loot_pool_json`
// referencia claves de ItemSeeder -reutiliza el catálogo de F6, no crea
// uno paralelo-. loot_min_tier/loot_max_tier son solo descriptivos para
// la UI ("Básico -> Raro"), no hay un sistema de tiers que filtre loot
// por ellos todavía.
//
// F8: remapeado a las 16 materias primas "canónicas" que la fase de
// economía/crafting define (sección 3) — las claves `resource_*` de F6/F7
// (madera/hierba/etc. genéricas, solo para probar el flujo de inventario)
// quedaron reemplazadas por estas para que el loot de expedición
// realmente alimente el crafting (ver decisión registrada en CLAUDE.md).
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
                'loot_pool_json' => ['wood', 'branch', 'plant_fiber', 'wild_herb', 'wild_mushroom', 'feather'],
            ],
            [
                'key' => 'mountains',
                'name' => 'Montañas',
                'difficulty' => 3,
                'duration_minutes' => 300,
                'loot_min_tier' => 'rare',
                'loot_max_tier' => 'mythic',
                'loot_pool_json' => ['stone', 'iron_ore', 'crystal_fragment', 'mountain_herb', 'mountain_hide'],
            ],
            [
                'key' => 'blood_castle',
                'name' => 'Castillo Sangriento',
                'difficulty' => 5,
                'duration_minutes' => 720,
                'loot_min_tier' => 'mythic',
                'loot_max_tier' => 'legendary',
                'loot_pool_json' => ['ancient_cloth', 'bone_fragment', 'black_wax', 'crimson_essence', 'dark_feather'],
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
