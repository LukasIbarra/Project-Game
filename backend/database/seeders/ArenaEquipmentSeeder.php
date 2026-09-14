<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

// Fase 10: los 4 items de equipamiento de F8 (EconomyItemSeeder) ya
// existían pero nunca modificaban estadísticas de combate -el combate no
// existía todavía-. Igual que RoomFurnitureSeeder hizo con wooden_chair/
// simple_table/decorative_pot (Fase 9.1), acá solo se AGREGA
// `metadata_json.stat_bonus` a items que ya existen, sin tocar su
// name/icon/rarity/sell_value (responsabilidad de EconomyItemSeeder).
// `CombatStatsService` lee esta bolsa genéricamente -sumando lo que
// encuentre en el equipo puesto-, así que agregar un item nuevo con
// stat_bonus en el futuro no requiere tocar ningún `if item === "x"`.
//
// `reinforced_wooden_shield` además gana `subtype: 'shield'` -el nuevo
// caso del enum EquipmentSlot- porque hasta ahora no era equipable en
// absoluto (se había sembrado con subtype null a propósito, ver F8).
class ArenaEquipmentSeeder extends Seeder
{
    public function run(): void
    {
        $bonuses = [
            'simple_sword' => ['attack' => 5],
            'adventurer_axe' => ['attack' => 6],
            'crimson_sword' => ['attack' => 12, 'crit' => 3],
            'reinforced_wooden_shield' => ['defense' => 6, 'hp' => 15],
        ];

        foreach ($bonuses as $key => $statBonus) {
            $item = Item::where('key', $key)->first();
            if (! $item) {
                continue; // catálogo incompleto en este entorno -no rompe el resto del seed-.
            }

            $metadata = $item->metadata_json ?? [];
            $metadata['stat_bonus'] = $statBonus;
            $item->metadata_json = $metadata;

            if ($key === 'reinforced_wooden_shield') {
                $item->subtype = 'shield';
            }

            $item->save();
        }
    }
}
