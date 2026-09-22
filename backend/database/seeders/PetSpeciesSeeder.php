<?php

namespace Database\Seeders;

use App\Models\PetSpecies;
use Illuminate\Database\Seeder;

// Fase 20: ~20 especies pedidas por la fase (19 acá + "starter", creada
// por la migración 2026_09_19_000002 para las mascotas ya existentes).
// Modificadores simples y diferenciados -nunca más de 2 por especie-,
// usando exclusivamente App\Enums\PetModifierType y las keys reales de
// pet_destinations (forest/mountains/blood_castle). Solo un puñado trae
// `level_modifiers_json` -a propósito, para dejar claro que NO todas las
// especies necesitan progresión por nivel (objetivo #4 de la fase)-.
class PetSpeciesSeeder extends Seeder
{
    public function run(): void
    {
        $species = [
            [
                'key' => 'bat_pup',
                'name' => 'Murcielagito',
                'description' => 'Un pequeño murciélago curioso, cómodo en la oscuridad de castillos y cuevas.',
                'rarity' => 'common',
                'modifiers_json' => [
                    ['type' => 'loot_bonus_pct', 'scope' => 'destination', 'target' => 'blood_castle', 'value' => 10],
                ],
                'level_modifiers_json' => [
                    ['level' => 1, 'modifiers' => [
                        ['type' => 'loot_bonus_pct', 'scope' => 'destination', 'target' => 'blood_castle', 'value' => 10],
                    ]],
                    ['level' => 5, 'modifiers' => [
                        ['type' => 'loot_bonus_pct', 'scope' => 'destination', 'target' => 'blood_castle', 'value' => 12],
                    ]],
                    ['level' => 10, 'modifiers' => [
                        ['type' => 'loot_bonus_pct', 'scope' => 'destination', 'target' => 'blood_castle', 'value' => 15],
                    ]],
                ],
            ],
            [
                'key' => 'wolf_pup',
                'name' => 'Lobito',
                'description' => 'Cachorro de lobo con buen instinto para esquivar peligro.',
                'rarity' => 'common',
                'modifiers_json' => [
                    ['type' => 'damage_reduction_pct', 'scope' => 'event_type', 'target' => 'enemy', 'value' => 10],
                ],
            ],
            [
                'key' => 'kitten',
                'name' => 'Gatito',
                'description' => 'Tiene un sexto sentido para encontrar lo inusual.',
                'rarity' => 'common',
                'modifiers_json' => [
                    ['type' => 'rare_loot_chance_pct', 'scope' => 'global', 'target' => null, 'value' => 5],
                ],
            ],
            [
                'key' => 'bunny',
                'name' => 'Conejito',
                'description' => 'Excava con entusiasmo, siempre trae un poco más.',
                'rarity' => 'common',
                'modifiers_json' => [
                    ['type' => 'material_quantity_bonus_pct', 'scope' => 'global', 'target' => null, 'value' => 10],
                ],
            ],
            [
                'key' => 'slime_pup',
                'name' => 'Slimecito',
                'description' => 'Se desliza entre la maleza del bosque sin dificultad.',
                'rarity' => 'common',
                'modifiers_json' => [
                    ['type' => 'loot_bonus_pct', 'scope' => 'destination', 'target' => 'forest', 'value' => 8],
                ],
            ],
            [
                'key' => 'fox_pup',
                'name' => 'Zorrito',
                'description' => 'Astuto para encontrar lo que otros pasan por alto.',
                'rarity' => 'uncommon',
                'modifiers_json' => [
                    ['type' => 'rare_loot_chance_pct', 'scope' => 'global', 'target' => null, 'value' => 8],
                ],
            ],
            [
                'key' => 'turtle_pup',
                'name' => 'Tortuguita',
                'description' => 'Su caparazón absorbe buena parte de cualquier golpe.',
                'rarity' => 'common',
                'modifiers_json' => [
                    ['type' => 'damage_reduction_pct', 'scope' => 'event_type', 'target' => 'enemy', 'value' => 15],
                ],
            ],
            [
                'key' => 'frog_pup',
                'name' => 'Ranita',
                'description' => 'Salta entre las rocas de las montañas sin esfuerzo.',
                'rarity' => 'common',
                'modifiers_json' => [
                    ['type' => 'loot_bonus_pct', 'scope' => 'destination', 'target' => 'mountains', 'value' => 8],
                ],
            ],
            [
                'key' => 'bear_cub',
                'name' => 'Osito',
                'description' => 'Fuerte y metódico, siempre junta un poco más de todo.',
                'rarity' => 'uncommon',
                'modifiers_json' => [
                    ['type' => 'material_quantity_bonus_pct', 'scope' => 'global', 'target' => null, 'value' => 12],
                ],
            ],
            [
                'key' => 'bird_pup',
                'name' => 'Pajarito',
                'description' => 'Sobrevuela el camino y avista loot antes que nadie.',
                'rarity' => 'common',
                'modifiers_json' => [
                    ['type' => 'loot_bonus_pct', 'scope' => 'global', 'target' => null, 'value' => 5],
                ],
            ],
            [
                'key' => 'squirrel_pup',
                'name' => 'Ardillita',
                'description' => 'Nadie junta más provisiones del bosque que ella.',
                'rarity' => 'common',
                'modifiers_json' => [
                    ['type' => 'material_quantity_bonus_pct', 'scope' => 'destination', 'target' => 'forest', 'value' => 15],
                ],
            ],
            [
                'key' => 'hedgehog_pup',
                'name' => 'Erizito',
                'description' => 'Sus púas desalientan a cualquiera que se acerque de más.',
                'rarity' => 'uncommon',
                'modifiers_json' => [
                    ['type' => 'damage_reduction_pct', 'scope' => 'global', 'target' => null, 'value' => 8],
                ],
            ],
            [
                'key' => 'dragon_pup',
                'name' => 'Dragoncito',
                'description' => 'Diminuto, pero con un instinto certero para las salas del castillo.',
                'rarity' => 'rare',
                'modifiers_json' => [
                    ['type' => 'loot_bonus_pct', 'scope' => 'destination', 'target' => 'blood_castle', 'value' => 15],
                ],
                'level_modifiers_json' => [
                    ['level' => 1, 'modifiers' => [
                        ['type' => 'loot_bonus_pct', 'scope' => 'destination', 'target' => 'blood_castle', 'value' => 15],
                    ]],
                    ['level' => 10, 'modifiers' => [
                        ['type' => 'loot_bonus_pct', 'scope' => 'destination', 'target' => 'blood_castle', 'value' => 20],
                    ]],
                ],
            ],
            [
                'key' => 'phoenix_pup',
                'name' => 'Fenixcito',
                'description' => 'Un aura cálida que atrae la buena fortuna.',
                'rarity' => 'rare',
                'modifiers_json' => [
                    ['type' => 'rare_loot_chance_pct', 'scope' => 'global', 'target' => null, 'value' => 12],
                ],
            ],
            [
                'key' => 'unicorn_pup',
                'name' => 'Unicornito',
                'description' => 'Extremadamente raro. Todo lo que toca parece salir mejor.',
                'rarity' => 'very_rare',
                'modifiers_json' => [
                    ['type' => 'loot_bonus_pct', 'scope' => 'global', 'target' => null, 'value' => 10],
                    ['type' => 'rare_loot_chance_pct', 'scope' => 'global', 'target' => null, 'value' => 10],
                ],
            ],
            [
                'key' => 'griffin_pup',
                'name' => 'Grifito',
                'description' => 'Vigila desde el aire y protege de los peores golpes.',
                'rarity' => 'rare',
                'modifiers_json' => [
                    ['type' => 'damage_reduction_pct', 'scope' => 'event_type', 'target' => 'enemy', 'value' => 20],
                ],
            ],
            [
                'key' => 'serpent_pup',
                'name' => 'Serpentino',
                'description' => 'Se desliza entre las grietas de las montañas más altas.',
                'rarity' => 'uncommon',
                'modifiers_json' => [
                    ['type' => 'rare_loot_chance_pct', 'scope' => 'destination', 'target' => 'mountains', 'value' => 10],
                ],
            ],
            [
                'key' => 'crab_pup',
                'name' => 'Cangrejito',
                'description' => 'Su caparazón lo hace más resistente de lo que aparenta.',
                'rarity' => 'common',
                'modifiers_json' => [
                    ['type' => 'damage_reduction_pct', 'scope' => 'global', 'target' => null, 'value' => 5],
                ],
            ],
            [
                'key' => 'octopus_pup',
                'name' => 'Pulpito',
                'description' => 'Con tantos brazos, nunca vuelve con las manos vacías de la montaña.',
                'rarity' => 'uncommon',
                'modifiers_json' => [
                    ['type' => 'material_quantity_bonus_pct', 'scope' => 'destination', 'target' => 'mountains', 'value' => 12],
                ],
            ],
        ];

        foreach ($species as $entry) {
            PetSpecies::updateOrCreate(
                ['key' => $entry['key']],
                [
                    'name' => $entry['name'],
                    'description' => $entry['description'],
                    'rarity' => $entry['rarity'],
                    'modifiers_json' => $entry['modifiers_json'],
                    'level_modifiers_json' => $entry['level_modifiers_json'] ?? [],
                    'is_active' => true,
                ]
            );
        }
    }
}
