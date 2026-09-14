<?php

namespace Database\Seeders;

use App\Enums\ItemRarity;
use App\Enums\ItemType;
use App\Models\Item;
use Illuminate\Database\Seeder;

// F8, sección 3-8: los 40 items iniciales de la economía real (materias
// primas → procesados → consumibles/casa/equipamiento). `icon` es un
// emoji placeholder -sección 27: reemplazable después por una clave de
// asset real sin tocar lógica de inventario/crafting, por eso vive en su
// propia columna y no hardcodeado en el frontend-. `sell_value` sale
// siempre de acá (nunca del cliente, sección 11).
//
// Las 16 materias primas reemplazan a los "resource_*" genéricos de F7
// (ver ItemSeeder) — son las que las expediciones de PetSeeder entregan
// realmente, cerrando el loop expedición → recursos → crafting/venta.
class EconomyItemSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->items() as $item) {
            Item::updateOrCreate(
                ['key' => $item['key']],
                [
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'type' => $item['type'],
                    'subtype' => $item['subtype'] ?? null,
                    'stackable' => $item['stackable'] ?? true,
                    'max_stack' => $item['max_stack'] ?? 99,
                    'sell_value' => $item['sell_value'],
                    'icon' => $item['icon'],
                    'rarity' => $item['rarity'],
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(): array
    {
        return [
            // ===================== MATERIAS PRIMAS — BOSQUE (6) =====================
            ['key' => 'wood', 'name' => 'Madera', 'icon' => '🌲', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Common, 'sell_value' => 3],
            ['key' => 'branch', 'name' => 'Rama', 'icon' => '🪵', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Common, 'sell_value' => 2],
            ['key' => 'plant_fiber', 'name' => 'Fibra vegetal', 'icon' => '🧵', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Common, 'sell_value' => 3],
            ['key' => 'wild_herb', 'name' => 'Hierba silvestre', 'icon' => '🌿', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Common, 'sell_value' => 4],
            ['key' => 'wild_mushroom', 'name' => 'Hongo silvestre', 'icon' => '🍄', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 8],
            ['key' => 'feather', 'name' => 'Pluma', 'icon' => '🪶', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 8],

            // ===================== MATERIAS PRIMAS — MONTAÑAS (5) =====================
            ['key' => 'stone', 'name' => 'Piedra', 'icon' => '🪨', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Common, 'sell_value' => 3],
            ['key' => 'iron_ore', 'name' => 'Mineral de hierro', 'icon' => '⛏️', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 12],
            ['key' => 'crystal_fragment', 'name' => 'Fragmento de cristal', 'icon' => '💎', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Rare, 'sell_value' => 35],
            ['key' => 'mountain_herb', 'name' => 'Hierba de montaña', 'icon' => '🌿', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 10],
            ['key' => 'mountain_hide', 'name' => 'Piel de montaña', 'icon' => '🐺', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 15],

            // ================= MATERIAS PRIMAS — CASTILLO SANGRIENTO (5) =================
            ['key' => 'ancient_cloth', 'name' => 'Tela antigua', 'icon' => '🧵', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 12],
            ['key' => 'bone_fragment', 'name' => 'Fragmento de hueso', 'icon' => '🦴', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 8],
            ['key' => 'black_wax', 'name' => 'Cera negra', 'icon' => '🕯️', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Rare, 'sell_value' => 45],
            ['key' => 'crimson_essence', 'name' => 'Esencia carmesí', 'icon' => '🩸', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Rare, 'sell_value' => 60],
            ['key' => 'dark_feather', 'name' => 'Pluma oscura', 'icon' => '🪶', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Rare, 'sell_value' => 50],

            // ===================== MATERIALES PROCESADOS (8) =====================
            ['key' => 'wood_plank', 'name' => 'Tablones', 'icon' => '🪵', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Common, 'sell_value' => 4],
            ['key' => 'rope', 'name' => 'Cuerda', 'icon' => '🧶', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Common, 'sell_value' => 10],
            ['key' => 'herbal_extract', 'name' => 'Extracto herbal', 'icon' => '🌿', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Common, 'sell_value' => 10],
            ['key' => 'iron_ingot', 'name' => 'Lingote de hierro', 'icon' => '🔩', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 30],
            ['key' => 'cut_stone', 'name' => 'Piedra tallada', 'icon' => '🪨', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Common, 'sell_value' => 4],
            ['key' => 'reinforced_cloth', 'name' => 'Tela reforzada', 'icon' => '🧵', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 30],
            ['key' => 'black_candle', 'name' => 'Vela negra', 'icon' => '🕯️', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Rare, 'sell_value' => 25],
            ['key' => 'crimson_crystal', 'name' => 'Cristal carmesí', 'icon' => '🔮', 'type' => ItemType::Resource, 'rarity' => ItemRarity::Rare, 'sell_value' => 100],

            // ===================== CONSUMIBLES (6) =====================
            ['key' => 'small_potion', 'name' => 'Poción pequeña', 'icon' => '❤️', 'type' => ItemType::Consumable, 'rarity' => ItemRarity::Common, 'sell_value' => 18],
            ['key' => 'energy_tonic', 'name' => 'Tónico de energía', 'icon' => '⚡', 'type' => ItemType::Consumable, 'rarity' => ItemRarity::Common, 'sell_value' => 25],
            ['key' => 'bandage', 'name' => 'Vendaje', 'icon' => '🩹', 'type' => ItemType::Consumable, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 25],
            ['key' => 'explorer_ration', 'name' => 'Ración de explorador', 'icon' => '🍲', 'type' => ItemType::Consumable, 'rarity' => ItemRarity::Common, 'sell_value' => 25],
            ['key' => 'simple_antidote', 'name' => 'Antídoto sencillo', 'icon' => '🧪', 'type' => ItemType::Consumable, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 30],
            ['key' => 'pet_treat', 'name' => 'Premio para mascota', 'icon' => '🐾', 'type' => ItemType::Consumable, 'rarity' => ItemRarity::Common, 'sell_value' => 20],

            // ===================== OBJETOS DE CASA (6) =====================
            ['key' => 'wooden_chair', 'name' => 'Silla de madera', 'icon' => '🪑', 'type' => ItemType::Room, 'rarity' => ItemRarity::Common, 'sell_value' => 25],
            ['key' => 'simple_table', 'name' => 'Mesa sencilla', 'icon' => '🪵', 'type' => ItemType::Room, 'rarity' => ItemRarity::Common, 'sell_value' => 40],
            ['key' => 'small_chest', 'name' => 'Cofre pequeño', 'icon' => '📦', 'type' => ItemType::Room, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 60],
            ['key' => 'lantern', 'name' => 'Linterna', 'icon' => '🏮', 'type' => ItemType::Room, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 55],
            ['key' => 'decorative_pot', 'name' => 'Maceta decorativa', 'icon' => '🪴', 'type' => ItemType::Room, 'rarity' => ItemRarity::Common, 'sell_value' => 30],
            ['key' => 'ancient_totem', 'name' => 'Tótem antiguo', 'icon' => '🗿', 'type' => ItemType::Room, 'rarity' => ItemRarity::Rare, 'sell_value' => 120],

            // ===================== EQUIPAMIENTO (4) =====================
            // No equipable todavía vía F6 (ningún EquipmentSlot mapea
            // limpiamente a "arma de una mano" vs "escudo" hoy) salvo las
            // 3 espadas/hacha, que sí comparten slot "weapon" con el
            // equipamiento existente -ver decisión en el reporte de F8-.
            ['key' => 'simple_sword', 'name' => 'Espada sencilla', 'icon' => '🗡️', 'type' => ItemType::Equipment, 'subtype' => 'weapon', 'rarity' => ItemRarity::Common, 'sell_value' => 65, 'stackable' => false, 'max_stack' => 1],
            ['key' => 'adventurer_axe', 'name' => 'Hacha de aventurero', 'icon' => '🪓', 'type' => ItemType::Equipment, 'subtype' => 'weapon', 'rarity' => ItemRarity::Common, 'sell_value' => 60, 'stackable' => false, 'max_stack' => 1],
            ['key' => 'reinforced_wooden_shield', 'name' => 'Escudo de madera reforzado', 'icon' => '🛡️', 'type' => ItemType::Equipment, 'subtype' => null, 'rarity' => ItemRarity::Uncommon, 'sell_value' => 75, 'stackable' => false, 'max_stack' => 1],
            ['key' => 'crimson_sword', 'name' => 'Espada carmesí', 'icon' => '🗡️', 'type' => ItemType::Equipment, 'subtype' => 'weapon', 'rarity' => ItemRarity::Rare, 'sell_value' => 220, 'stackable' => false, 'max_stack' => 1],
        ];
    }
}
