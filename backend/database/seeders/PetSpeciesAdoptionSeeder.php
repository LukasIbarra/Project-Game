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
// (ajuste post-F23: reemplazo manual de los 5 PNG por versiones con fondo
// transparente real -alpha channel min:0/max:255 verificado, no solo el
// flag hasAlpha-, misma grilla 1774×887 / 4 columnas × 2 filas / celdas de
// 443×443 sin padding, vuelta a medir en vez de asumida).
//
// `frames`: los 8 frames documentados uno por uno (index + label corto)
// -inspección visual real de cada PNG, no inventado por índice-. `animations`
// agrupa esos frames en 5 estados con el MISMO vocabulario en las 5
// especies (idle/fidget/happy/excited/interaction), aunque los índices que
// cada uno usa varían según lo que realmente muestra cada spritesheet -no
// las 5 especies tienen el mismo "significado" en los frames 3-7, ver
// PETS_EXPEDITIONS_SYSTEM.md §0-. El renderer de /pet consume `animations`
// de forma genérica, nunca condiciona por species key.
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
                        'frames' => $entry['frames'],
                        'animations' => $entry['animations'],
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
                'description' => 'Un pequeño farol viviente que desprende un brillo cálido, acompañado por un espíritu flotante. Nadie sabe bien de dónde salió.',
                'modifiers' => [
                    ['type' => 'material_quantity_bonus_pct', 'scope' => 'destination', 'target' => 'forest', 'value' => 10],
                ],
                'frames' => [
                    ['index' => 0, 'label' => 'neutral'],
                    ['index' => 1, 'label' => 'eyes_closed_content'],
                    ['index' => 2, 'label' => 'excited_fangs_grin'],
                    ['index' => 3, 'label' => 'neutral_tail_visible'],
                    ['index' => 4, 'label' => 'wave_start_sparkle'],
                    ['index' => 5, 'label' => 'wave_mid_sparkle'],
                    ['index' => 6, 'label' => 'wave_paw_raised'],
                    ['index' => 7, 'label' => 'wave_settle_sparkle'],
                ],
                'animations' => [
                    'idle' => [0, 1],
                    'fidget' => [3],
                    'happy' => [2, 1],
                    'excited' => [2],
                    'interaction' => [4, 5, 6, 7],
                ],
            ],
            [
                'key' => 'rakhun',
                'file' => 'Rakhun',
                'name' => 'Rakhun',
                'description' => 'Un mapache curioso que siempre lleva una hoja fresca en la cabeza. Conoce cada rincón del bosque.',
                'modifiers' => [
                    ['type' => 'rare_loot_chance_pct', 'scope' => 'global', 'target' => null, 'value' => 6],
                ],
                'frames' => [
                    ['index' => 0, 'label' => 'neutral'],
                    ['index' => 1, 'label' => 'eyes_closed_content'],
                    ['index' => 2, 'label' => 'excited_wide_eyes'],
                    ['index' => 3, 'label' => 'alert_leaf_tilt'],
                    ['index' => 4, 'label' => 'leaf_wobble_excited'],
                    ['index' => 5, 'label' => 'leaf_blown_off'],
                    ['index' => 6, 'label' => 'leaf_floating_laugh'],
                    ['index' => 7, 'label' => 'leaf_landing_happy'],
                ],
                'animations' => [
                    'idle' => [0, 1],
                    'fidget' => [3],
                    'happy' => [6],
                    'excited' => [2],
                    'interaction' => [4, 5, 6, 7],
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
                'frames' => [
                    ['index' => 0, 'label' => 'neutral_calm_pool'],
                    ['index' => 1, 'label' => 'eyes_closed_content'],
                    ['index' => 2, 'label' => 'excited_sparkle_cloud'],
                    ['index' => 3, 'label' => 'neutral_blush'],
                    ['index' => 4, 'label' => 'pool_calm'],
                    ['index' => 5, 'label' => 'pool_bubble_start'],
                    ['index' => 6, 'label' => 'pool_spout_peak'],
                    ['index' => 7, 'label' => 'pool_settle_ripple'],
                ],
                'animations' => [
                    'idle' => [0, 1],
                    'fidget' => [3],
                    'happy' => [2],
                    'excited' => [4, 5, 6, 7],
                    'interaction' => [1, 2],
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
                'frames' => [
                    ['index' => 0, 'label' => 'neutral'],
                    ['index' => 1, 'label' => 'eyes_closed_content'],
                    ['index' => 2, 'label' => 'excited_wide_eyes'],
                    ['index' => 3, 'label' => 'wink_playful'],
                    ['index' => 4, 'label' => 'ears_perk_alert'],
                    ['index' => 5, 'label' => 'playful_tongue_out'],
                    ['index' => 6, 'label' => 'tail_wag'],
                    ['index' => 7, 'label' => 'neutral_alt'],
                ],
                'animations' => [
                    'idle' => [0, 1],
                    'fidget' => [7],
                    'happy' => [5],
                    'excited' => [2, 3],
                    'interaction' => [6],
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
                'frames' => [
                    ['index' => 0, 'label' => 'neutral'],
                    ['index' => 1, 'label' => 'eyes_closed_content'],
                    ['index' => 2, 'label' => 'curious_wide_eyes'],
                    ['index' => 3, 'label' => 'smirk_alert'],
                    ['index' => 4, 'label' => 'perk_sparkle_fangs'],
                    ['index' => 5, 'label' => 'puffed_cheeks_content'],
                    ['index' => 6, 'label' => 'cheer_laugh_open_mouth'],
                    ['index' => 7, 'label' => 'settle_sparkle_grin'],
                ],
                'animations' => [
                    'idle' => [0, 1],
                    'fidget' => [3],
                    'happy' => [5],
                    'excited' => [4, 5, 6, 7],
                    'interaction' => [2],
                ],
            ],
        ];
    }
}
