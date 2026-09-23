<?php

namespace Database\Seeders;

use App\Enums\ItemRarity;
use App\Enums\ItemType;
use App\Models\Item;
use Illuminate\Database\Seeder;

// F21: items nuevos, exclusivos de las 3 expediciones nuevas (Colinas del
// Viento/Ruinas Antiguas/Pantano Maldito, sin ningún material temático
// existente) más un toque de identidad para 3 de las expediciones ya
// existentes (un acento "encantado" para Bosque, "helado" para Montañas,
// un capstone very_rare para Castillo Sangriento) -autorizado
// explícitamente, ver docs/PETS_EXPEDITIONS_SYSTEM.md y el pedido de F21.
// Antes de crear cada uno se revisó el catálogo real (EconomyItemSeeder,
// ItemSeeder) buscando algo reutilizable -ninguno de los 16 recursos
// existentes encaja con viento/ruinas/pantano, así que se crean acá en
// vez de forzar un mapeo temáticamente falso. Todos Resource, stackable,
// sell_value escalado por rareza (mismo rango que EconomyItemSeeder:
// common ~3-5, uncommon ~10-18, rare ~40-60, very_rare ~100-150).
class ExpeditionItemSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->items() as $item) {
            Item::updateOrCreate(
                ['key' => $item['key']],
                [
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'type' => ItemType::Resource,
                    'stackable' => true,
                    'max_stack' => 99,
                    'sell_value' => $item['sell_value'],
                    'icon' => $item['icon'],
                    'rarity' => $item['rarity'],
                ]
            );
        }
    }

    private function items(): array
    {
        return [
            // ===================== BOSQUE ENCANTADO — acento (1) =====================
            ['key' => 'enchanted_acorn', 'name' => 'Bellota encantada', 'icon' => '🌰', 'rarity' => ItemRarity::Uncommon, 'sell_value' => 12, 'description' => 'Brilla tenuemente. Dicen que solo crece bajo luz de luna llena.'],

            // ===================== COLINAS DEL VIENTO (5) =====================
            ['key' => 'windswept_herb', 'name' => 'Hierba de las colinas', 'icon' => '🌾', 'rarity' => ItemRarity::Common, 'sell_value' => 4, 'description' => 'Crece doblada por un viento que nunca para.'],
            ['key' => 'hilltop_clover', 'name' => 'Trébol de colina', 'icon' => '🍀', 'rarity' => ItemRarity::Common, 'sell_value' => 5, 'description' => 'De buena suerte, según los viajeros que pasan por ahí.'],
            ['key' => 'windward_feather', 'name' => 'Pluma de viento', 'icon' => '🪶', 'rarity' => ItemRarity::Uncommon, 'sell_value' => 10, 'description' => 'Perdida por un ave migratoria a mitad de su viaje.'],
            ['key' => 'traveler_compass_shard', 'name' => 'Fragmento de brújula', 'icon' => '🧭', 'rarity' => ItemRarity::Uncommon, 'sell_value' => 14, 'description' => 'Rota, pero todavía señala vagamente al norte.'],
            ['key' => 'soaring_feather', 'name' => 'Pluma de las alturas', 'icon' => '🕊️', 'rarity' => ItemRarity::Rare, 'sell_value' => 40, 'description' => 'De un ave que vuela más alto que ninguna otra.'],

            // ===================== MONTAÑAS HELADAS — acento (2) =====================
            ['key' => 'snow_lichen', 'name' => 'Liquen de nieve', 'icon' => '❄️', 'rarity' => ItemRarity::Common, 'sell_value' => 4, 'description' => 'Resistente, crece sobre la roca helada de las cumbres.'],
            ['key' => 'frostbitten_fur', 'name' => 'Piel escarchada', 'icon' => '🥶', 'rarity' => ItemRarity::Uncommon, 'sell_value' => 16, 'description' => 'Curtida por el frío extremo de las montañas altas.'],
            ['key' => 'frost_crystal', 'name' => 'Cristal de escarcha', 'icon' => '🧊', 'rarity' => ItemRarity::Rare, 'sell_value' => 55, 'description' => 'Frío al tacto incluso mucho después de salir de la montaña.'],

            // ===================== RUINAS ANTIGUAS (5) =====================
            ['key' => 'weathered_stone_tablet', 'name' => 'Tablilla desgastada', 'icon' => '🪨', 'rarity' => ItemRarity::Common, 'sell_value' => 5, 'description' => 'Piedra con inscripciones ya ilegibles.'],
            ['key' => 'broken_pottery', 'name' => 'Cerámica rota', 'icon' => '🏺', 'rarity' => ItemRarity::Common, 'sell_value' => 4, 'description' => 'Fragmentos de una civilización que ya nadie recuerda.'],
            ['key' => 'rune_fragment', 'name' => 'Fragmento rúnico', 'icon' => '🔯', 'rarity' => ItemRarity::Uncommon, 'sell_value' => 15, 'description' => 'Todavía emite un brillo tenue, sin razón aparente.'],
            ['key' => 'ancient_coin', 'name' => 'Moneda antigua', 'icon' => '🪙', 'rarity' => ItemRarity::Uncommon, 'sell_value' => 13, 'description' => 'De un imperio que ya nadie recuerda.'],
            ['key' => 'forgotten_relic', 'name' => 'Reliquia olvidada', 'icon' => '🏛️', 'rarity' => ItemRarity::Rare, 'sell_value' => 50, 'description' => 'Objeto ceremonial de un culto extinto. Su propósito original es un misterio.'],

            // ===================== PANTANO MALDITO (6) =====================
            ['key' => 'swamp_moss', 'name' => 'Musgo de pantano', 'icon' => '🟢', 'rarity' => ItemRarity::Common, 'sell_value' => 3, 'description' => 'Húmedo, cubre cada superficie del pantano.'],
            ['key' => 'venomous_thorn', 'name' => 'Espina venenosa', 'icon' => '🌵', 'rarity' => ItemRarity::Common, 'sell_value' => 5, 'description' => 'Cargada de un veneno leve pero persistente.'],
            ['key' => 'corrupted_root', 'name' => 'Raíz corrupta', 'icon' => '🕸️', 'rarity' => ItemRarity::Uncommon, 'sell_value' => 14, 'description' => 'Oscurecida por algo que no es solo el barro.'],
            ['key' => 'toxic_gland', 'name' => 'Glándula tóxica', 'icon' => '🧪', 'rarity' => ItemRarity::Uncommon, 'sell_value' => 18, 'description' => 'Mejor no tocarla sin guantes.'],
            ['key' => 'cursed_lily', 'name' => 'Lirio maldito', 'icon' => '🥀', 'rarity' => ItemRarity::Rare, 'sell_value' => 45, 'description' => 'Pálido. No debería poder crecer en un lugar así.'],
            ['key' => 'black_ichor', 'name' => 'Icor negro', 'icon' => '🖤', 'rarity' => ItemRarity::VeryRare, 'sell_value' => 110, 'description' => 'Oscuro y viscoso. Mejor no preguntar de dónde salió.'],

            // ===================== CASTILLO SANGRIENTO — capstone (1) =====================
            ['key' => 'cursed_crown_shard', 'name' => 'Fragmento de corona maldita', 'icon' => '👑', 'rarity' => ItemRarity::VeryRare, 'sell_value' => 150, 'description' => 'Perteneció a alguien que ya no debería recordarse.'],
        ];
    }
}
