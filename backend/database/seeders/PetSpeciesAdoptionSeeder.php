<?php

namespace Database\Seeders;

use App\Models\PetSpecies;
use Illuminate\Database\Seeder;

// F23: las 5 especies definitivas de adopción -Lumio, Rakhun, Qappha,
// Kitsu, Sapphoro-. Reutiliza PetSpecies/PetModifierResolver tal cual
// (ningún catálogo ni sistema de stats paralelo), mismo vocabulario de
// App\Enums\PetModifierType y las keys reales de expedition_definitions
// (forest/mountains/blood_castle/...) que ya usa PetSpeciesSeeder (F20).
//
// Modificadores conservadores (1 por especie, valores chicos) a propósito
// -balance real queda para una fase posterior, ver
// docs/PETS_EXPEDITIONS_SYSTEM.md §5.1/§10-.
//
// sprite_meta_json: grilla REAL medida con `sharp` sobre los 5 PNG
// (1774×887, sin alfa, 4 columnas × 2 filas, celdas de 443×443 sin
// padding, confirmado extrayendo recortes y verificándolos visualmente
// -no asumido-). idle_frames=[0,1] es el par "ojos abiertos ↔ cerrados"
// -parpadeo-, verificado como seguro y consistente en las 5 especies.
// expression_frames queda documentado/preparado, sin consumidor todavía
// (F23 no implementa expresiones adicionales, solo idle).
//
// adoption_price=150: auditado contra la economía real (combate: 15
// monedas por victoria / 3 por derrota; ítem más caro de la tienda hoy:
// 60; materiales very_rare se venden 100-150) -del orden de un material
// very_rare o ~10 combates ganados. Provisional, documentado -balance real
// queda para una fase posterior-. La primera mascota (adoptStarter) NUNCA
// cobra esto, sea cual sea su valor.
class PetSpeciesAdoptionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->species() as $entry) {
            PetSpecies::updateOrCreate(
                ['key' => $entry['key']],
                [
                    'name' => $entry['name'],
                    'description' => $entry['description'],
                    'rarity' => 'uncommon',
                    'sprite_key' => $entry['key'],
                    'sprite_meta_json' => [
                        'file' => "/assets/pets/{$entry['file']}.png",
                        'frame_width' => 443,
                        'frame_height' => 443,
                        'columns' => 4,
                        'rows' => 2,
                        'idle_frames' => [0, 1],
                        // Preparado para F24+ -índices reales del
                        // spritesheet (8 frames, fila 1: 0-3, fila 2: 4-7),
                        // sin consumidor todavía. No es una promesa exacta
                        // de qué expresa cada frame en cada especie (varía
                        // levemente entre las 5), solo un punto de partida
                        // razonable común a las 5 grillas.
                        'expression_frames' => [
                            'happy' => [2, 3],
                            'fed' => [1],
                            'expedition' => [4, 5],
                            'hurt' => [6],
                            'interact' => [7],
                        ],
                    ],
                    'modifiers_json' => $entry['modifiers'],
                    'level_modifiers_json' => [],
                    'is_active' => true,
                    'is_starter_option' => true,
                    'adoption_price' => 150,
                ]
            );
        }
    }

    private function species(): array
    {
        return [
            [
                'key' => 'lumio',
                'file' => 'Lumio',
                'name' => 'Lumio',
                'description' => 'Un mapache curioso que siempre lleva una hoja fresca en la cabeza. Conoce cada rincón del bosque.',
                'modifiers' => [
                    ['type' => 'material_quantity_bonus_pct', 'scope' => 'destination', 'target' => 'forest', 'value' => 10],
                ],
            ],
            [
                'key' => 'rakhun',
                'file' => 'Rakhun',
                'name' => 'Rakhun',
                'description' => 'Un pequeño farol viviente que desprende un brillo cálido. Nadie sabe bien de dónde salió.',
                'modifiers' => [
                    ['type' => 'rare_loot_chance_pct', 'scope' => 'global', 'target' => null, 'value' => 6],
                ],
            ],
            [
                'key' => 'qappha',
                'file' => 'Qappha',
                'name' => 'Qappha',
                'description' => 'Lleva un pequeño estanque en la cabeza que nunca se derrama. Tranquilo, casi nunca se sobresalta.',
                'modifiers' => [
                    ['type' => 'loot_bonus_pct', 'scope' => 'global', 'target' => null, 'value' => 5],
                ],
            ],
            [
                'key' => 'kitsu',
                'file' => 'Kitsu',
                'name' => 'Kitsu',
                'description' => 'Un zorrito con un cascabel dorado y un aire misterioso. Algo en su mirada sabe más de lo que dice.',
                'modifiers' => [
                    ['type' => 'rare_loot_chance_pct', 'scope' => 'destination', 'target' => 'blood_castle', 'value' => 8],
                ],
            ],
            [
                'key' => 'sapphoro',
                'file' => 'Sapphoro',
                'name' => 'Sapphoro',
                'description' => 'Pequeño y con cuernos de fuego, pero de piel sorprendentemente resistente.',
                'modifiers' => [
                    ['type' => 'damage_reduction_pct', 'scope' => 'event_type', 'target' => 'enemy', 'value' => 8],
                ],
            ],
        ];
    }
}
