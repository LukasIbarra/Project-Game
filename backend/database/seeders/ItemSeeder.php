<?php

namespace Database\Seeders;

use App\Enums\ItemType;
use App\Models\Item;
use Illuminate\Database\Seeder;

// Fase 6: catálogo MÍNIMO para probar inventario/equipamiento -no es el
// catálogo real del juego-. `key` es la identidad estable del item y
// también la clave de apariencia que se escribe en
// characters.appearance_json al equipar (ver CLAUDE.md, ejemplo de
// appearance_json). El manifest de assets (web/public/assets/
// manifest.json) hoy no tiene entradas reales para hair/shirt/pants/
// shoes/weapon/accessory -el CharacterRenderer las omite en silencio si
// no las encuentra, así que estos items funcionan (se pueden poseer y
// equipar) aunque todavía no tengan sprite propio-.
class ItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['key' => 'hair_01', 'name' => 'Pelo básico', 'type' => ItemType::Cosmetic, 'subtype' => 'hair'],
            ['key' => 'hair_02', 'name' => 'Pelo corto', 'type' => ItemType::Cosmetic, 'subtype' => 'hair'],
            ['key' => 'shirt_01', 'name' => 'Camisa básica', 'type' => ItemType::Cosmetic, 'subtype' => 'shirt'],
            ['key' => 'shirt_02', 'name' => 'Camisa a rayas', 'type' => ItemType::Cosmetic, 'subtype' => 'shirt'],
            ['key' => 'pants_01', 'name' => 'Pantalón básico', 'type' => ItemType::Cosmetic, 'subtype' => 'pants'],
            ['key' => 'shoes_01', 'name' => 'Zapatos básicos', 'type' => ItemType::Cosmetic, 'subtype' => 'shoes'],
            ['key' => 'weapon_sword_basic', 'name' => 'Espada básica', 'type' => ItemType::Equipment, 'subtype' => 'weapon'],
            ['key' => 'accessory_ring_basic', 'name' => 'Anillo básico', 'type' => ItemType::Equipment, 'subtype' => 'accessory'],

            // No equipable a propósito: sirve para probar stacking/grant
            // y para verificar que el sistema de equipamiento rechaza
            // items sin slot válido.
            [
                'key' => 'resource_wood',
                'name' => 'Madera',
                'type' => ItemType::Resource,
                'subtype' => null,
                'stackable' => true,
                'max_stack' => 99,
            ],

            // Fase 7: loot de expediciones de mascota (ver PetSeeder,
            // pet_destinations.loot_pool_json) — resource_wood de arriba
            // se reutiliza para el Bosque, no se duplica.
            ['key' => 'resource_herb', 'name' => 'Hierba', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
            ['key' => 'resource_berry', 'name' => 'Baya', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
            ['key' => 'resource_stone', 'name' => 'Piedra', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
            ['key' => 'resource_iron_ore', 'name' => 'Mineral de hierro', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
            ['key' => 'resource_coal', 'name' => 'Carbón', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
            ['key' => 'resource_crystal', 'name' => 'Cristal', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
            ['key' => 'resource_rare_ore', 'name' => 'Mineral raro', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
            ['key' => 'resource_blood_crystal', 'name' => 'Cristal de sangre', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
            ['key' => 'resource_ancient_fragment', 'name' => 'Fragmento antiguo', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
            ['key' => 'resource_mythic_material', 'name' => 'Material mítico', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
            ['key' => 'resource_legendary_fragment', 'name' => 'Fragmento legendario', 'type' => ItemType::Resource, 'stackable' => true, 'max_stack' => 99],
        ];

        foreach ($items as $item) {
            Item::updateOrCreate(
                ['key' => $item['key']],
                [
                    'name' => $item['name'],
                    'type' => $item['type'],
                    'subtype' => $item['subtype'] ?? null,
                    'stackable' => $item['stackable'] ?? false,
                    'max_stack' => $item['max_stack'] ?? 1,
                ]
            );
        }
    }
}
