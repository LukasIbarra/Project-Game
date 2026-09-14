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

        // F8: los 12 recursos genéricos "resource_*" de F7 (madera/hierba/
        // piedra/etc.) fueron reemplazados por las 16 materias primas
        // "canónicas" de la economía real (ver EconomyItemSeeder +
        // PetSeeder actualizado) — quedaban huérfanos y duplicaban el
        // mismo concepto con distinta clave. Se borran acá en vez de
        // dejarlos como basura en el catálogo; restrictOnDelete() los
        // protege solos si alguna instancia real todavía los referencia.
        $obsoleteKeys = [
            'resource_wood', 'resource_herb', 'resource_berry', 'resource_stone',
            'resource_iron_ore', 'resource_coal', 'resource_crystal', 'resource_rare_ore',
            'resource_blood_crystal', 'resource_ancient_fragment', 'resource_mythic_material',
            'resource_legendary_fragment',
        ];

        foreach ($obsoleteKeys as $key) {
            try {
                Item::where('key', $key)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $this->command?->warn("No se pudo borrar el item obsoleto '{$key}' (todavía referenciado): {$e->getMessage()}");
            }
        }
    }
}
