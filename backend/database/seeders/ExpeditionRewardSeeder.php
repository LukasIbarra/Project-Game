<?php

namespace Database\Seeders;

use App\Models\ExpeditionDefinition;
use App\Models\ExpeditionReward;
use App\Models\Item;
use Illuminate\Database\Seeder;

// F21: reemplaza expedition_definitions.loot_pool_json (array plano de
// keys, sin pesos ni cantidades) por la loot table normalizada real. Se
// ejecuta DESPUÉS de ExpeditionDefinitionSeeder/EconomyItemSeeder/
// ExpeditionItemSeeder (necesita que expediciones e items ya existan).
//
// weight/min_qty/max_qty se derivan de una sola tabla por rareza -mismo
// vocabulario que App\Enums\ItemRarity- en vez de números sueltos por
// fila: mantiene la progresión de rareza coherente y auditable en un solo
// lugar. El balance FINO (números exactos) es contenido, no arquitectura
// -F23 lo ajusta con datos reales de juego, esto es un punto de partida
// razonable, no la palabra final-.
class ExpeditionRewardSeeder extends Seeder
{
    private const WEIGHT_BY_RARITY = [
        'common' => 40,
        'uncommon' => 15,
        'rare' => 6,
        'very_rare' => 2,
    ];

    private const QTY_BY_RARITY = [
        'common' => [2, 6],
        'uncommon' => [1, 3],
        'rare' => [1, 2],
        'very_rare' => [1, 1],
    ];

    public function run(): void
    {
        $items = Item::pluck('id', 'key');
        $definitions = ExpeditionDefinition::pluck('id', 'key');

        foreach ($this->rewards() as $expeditionKey => $entries) {
            $expeditionId = $definitions[$expeditionKey] ?? null;
            if (! $expeditionId) {
                continue; // catálogo de expediciones no sembrado todavía -no debería pasar, no revienta el seed-.
            }

            foreach ($entries as [$itemKey, $rarity]) {
                $itemId = $items[$itemKey] ?? null;
                if (! $itemId) {
                    continue;
                }

                [$minQty, $maxQty] = self::QTY_BY_RARITY[$rarity];

                ExpeditionReward::updateOrCreate(
                    ['expedition_definition_id' => $expeditionId, 'item_id' => $itemId],
                    [
                        'weight' => self::WEIGHT_BY_RARITY[$rarity],
                        'min_qty' => $minQty,
                        'max_qty' => $maxQty,
                        'rarity_tier' => $rarity,
                    ]
                );
            }
        }
    }

    /**
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    private function rewards(): array
    {
        return [
            // Bosque Encantado -reutiliza el pool real de F7/F8 (forest),
            // sin rareza alta todavía: es la expedición de entrada
            // (dif.1, 30min), + 1 acento "encantado" nuevo.
            'forest' => [
                ['wood', 'common'],
                ['branch', 'common'],
                ['plant_fiber', 'common'],
                ['wild_herb', 'common'],
                ['wild_mushroom', 'uncommon'],
                ['feather', 'uncommon'],
                ['enchanted_acorn', 'uncommon'],
            ],

            // Colinas del Viento -catálogo 100% nuevo, temática viento/
            // altura/viaje.
            'windy_hills' => [
                ['windswept_herb', 'common'],
                ['hilltop_clover', 'common'],
                ['windward_feather', 'uncommon'],
                ['traveler_compass_shard', 'uncommon'],
                ['soaring_feather', 'rare'],
            ],

            // Montañas Heladas -reutiliza el pool real de F7/F8
            // (mountains) + 2 nuevos con acento de hielo/frío, ya que la
            // expedición pasó a llamarse explícitamente "Heladas".
            'mountains' => [
                ['stone', 'common'],
                ['snow_lichen', 'common'],
                ['mountain_herb', 'uncommon'],
                ['mountain_hide', 'uncommon'],
                ['iron_ore', 'uncommon'],
                ['frostbitten_fur', 'uncommon'],
                ['crystal_fragment', 'rare'],
                ['frost_crystal', 'rare'],
            ],

            // Ruinas Antiguas -catálogo 100% nuevo, temática
            // arqueología/runas/civilización perdida.
            'ancient_ruins' => [
                ['weathered_stone_tablet', 'common'],
                ['broken_pottery', 'common'],
                ['rune_fragment', 'uncommon'],
                ['ancient_coin', 'uncommon'],
                ['forgotten_relic', 'rare'],
            ],

            // Pantano Maldito -catálogo 100% nuevo, temática pantano/
            // veneno/corrupción. Único de los 3 nuevos con very_rare
            // propio (dif.4, segunda expedición más difícil).
            'cursed_swamp' => [
                ['swamp_moss', 'common'],
                ['venomous_thorn', 'common'],
                ['corrupted_root', 'uncommon'],
                ['toxic_gland', 'uncommon'],
                ['cursed_lily', 'rare'],
                ['black_ichor', 'very_rare'],
            ],

            // Castillo Sangriento -reutiliza el pool real de F7/F8
            // (blood_castle, ya muy temático) + 1 capstone very_rare
            // nuevo, coherente con ser la expedición más difícil del
            // juego (dif.5, 12h).
            'blood_castle' => [
                ['ancient_cloth', 'uncommon'],
                ['bone_fragment', 'uncommon'],
                ['black_wax', 'rare'],
                ['crimson_essence', 'rare'],
                ['dark_feather', 'rare'],
                ['cursed_crown_shard', 'very_rare'],
            ],
        ];
    }
}
