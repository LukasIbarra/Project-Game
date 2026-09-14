<?php

namespace Database\Seeders;

use App\Enums\ItemRarity;
use App\Enums\RecipeCategory;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Database\Seeder;

// F8, sección 4-7: las 24 recetas (items 17-40 del catálogo) — cada una
// referencia claves de EconomyItemSeeder por `key`, resueltas a
// item_id acá (nunca se guarda la key cruda en recipes/recipe_ingredients,
// a diferencia de pet_destinations.loot_pool_json que sí es JSON de
// keys -acá conviene FK real porque cada ingrediente es una fila propia
// consultable, no un array opaco-).
class RecipeSeeder extends Seeder
{
    public function run(): void
    {
        $itemIds = Item::pluck('id', 'key');

        foreach ($this->recipes() as $recipeData) {
            $ingredients = $recipeData['ingredients'];
            unset($recipeData['ingredients']);

            $recipeData['result_item_id'] = $itemIds[$recipeData['result_item_key']];
            unset($recipeData['result_item_key']);

            $recipe = Recipe::updateOrCreate(
                ['key' => $recipeData['key']],
                $recipeData + ['required_level' => 1, 'unlock_condition' => null, 'is_active' => true]
            );

            foreach ($ingredients as $itemKey => $quantity) {
                RecipeIngredient::updateOrCreate(
                    ['recipe_id' => $recipe->id, 'item_id' => $itemIds[$itemKey]],
                    ['quantity' => $quantity]
                );
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recipes(): array
    {
        return [
            // ===================== MATERIALES PROCESADOS =====================
            ['key' => 'wood_plank', 'name' => 'Tablones', 'category' => RecipeCategory::Materials, 'rarity' => ItemRarity::Common, 'result_item_key' => 'wood_plank', 'result_quantity' => 2, 'ingredients' => ['wood' => 3]],
            ['key' => 'rope', 'name' => 'Cuerda', 'category' => RecipeCategory::Materials, 'rarity' => ItemRarity::Common, 'result_item_key' => 'rope', 'result_quantity' => 1, 'ingredients' => ['plant_fiber' => 3]],
            ['key' => 'herbal_extract', 'name' => 'Extracto herbal', 'category' => RecipeCategory::Materials, 'rarity' => ItemRarity::Common, 'result_item_key' => 'herbal_extract', 'result_quantity' => 1, 'ingredients' => ['wild_herb' => 2]],
            ['key' => 'iron_ingot', 'name' => 'Lingote de hierro', 'category' => RecipeCategory::Materials, 'rarity' => ItemRarity::Uncommon, 'result_item_key' => 'iron_ingot', 'result_quantity' => 1, 'ingredients' => ['iron_ore' => 3]],
            ['key' => 'cut_stone', 'name' => 'Piedra tallada', 'category' => RecipeCategory::Materials, 'rarity' => ItemRarity::Common, 'result_item_key' => 'cut_stone', 'result_quantity' => 2, 'ingredients' => ['stone' => 3]],
            ['key' => 'reinforced_cloth', 'name' => 'Tela reforzada', 'category' => RecipeCategory::Materials, 'rarity' => ItemRarity::Uncommon, 'result_item_key' => 'reinforced_cloth', 'result_quantity' => 1, 'ingredients' => ['ancient_cloth' => 2, 'plant_fiber' => 1]],
            ['key' => 'black_candle', 'name' => 'Vela negra', 'category' => RecipeCategory::Materials, 'rarity' => ItemRarity::Rare, 'result_item_key' => 'black_candle', 'result_quantity' => 2, 'ingredients' => ['black_wax' => 1, 'plant_fiber' => 1]],
            ['key' => 'crimson_crystal', 'name' => 'Cristal carmesí', 'category' => RecipeCategory::Materials, 'rarity' => ItemRarity::Rare, 'result_item_key' => 'crimson_crystal', 'result_quantity' => 1, 'ingredients' => ['crimson_essence' => 2, 'crystal_fragment' => 1]],

            // ===================== CONSUMIBLES =====================
            ['key' => 'small_potion', 'name' => 'Poción pequeña', 'category' => RecipeCategory::Consumables, 'rarity' => ItemRarity::Common, 'result_item_key' => 'small_potion', 'result_quantity' => 1, 'ingredients' => ['herbal_extract' => 1, 'wild_mushroom' => 1]],
            ['key' => 'energy_tonic', 'name' => 'Tónico de energía', 'category' => RecipeCategory::Consumables, 'rarity' => ItemRarity::Common, 'result_item_key' => 'energy_tonic', 'result_quantity' => 1, 'ingredients' => ['herbal_extract' => 1, 'mountain_herb' => 1]],
            ['key' => 'bandage', 'name' => 'Vendaje', 'category' => RecipeCategory::Consumables, 'rarity' => ItemRarity::Uncommon, 'result_item_key' => 'bandage', 'result_quantity' => 1, 'ingredients' => ['plant_fiber' => 2, 'reinforced_cloth' => 1]],
            ['key' => 'explorer_ration', 'name' => 'Ración de explorador', 'category' => RecipeCategory::Consumables, 'rarity' => ItemRarity::Common, 'result_item_key' => 'explorer_ration', 'result_quantity' => 1, 'ingredients' => ['wild_mushroom' => 2, 'mountain_herb' => 1]],
            ['key' => 'simple_antidote', 'name' => 'Antídoto sencillo', 'category' => RecipeCategory::Consumables, 'rarity' => ItemRarity::Uncommon, 'result_item_key' => 'simple_antidote', 'result_quantity' => 1, 'ingredients' => ['herbal_extract' => 2, 'wild_mushroom' => 1]],
            ['key' => 'pet_treat', 'name' => 'Premio para mascota', 'category' => RecipeCategory::Consumables, 'rarity' => ItemRarity::Common, 'result_item_key' => 'pet_treat', 'result_quantity' => 1, 'ingredients' => ['wild_mushroom' => 1, 'feather' => 1, 'wild_herb' => 1]],

            // ===================== OBJETOS DE CASA =====================
            // Fase 9.1: estas 3 SÍ tienen un sprite real en
            // Interior_Props_01.png (ver RoomFurnitureSeeder), así que
            // siguen activas y ahora además son colocables de verdad.
            ['key' => 'wooden_chair', 'name' => 'Silla de madera', 'category' => RecipeCategory::House, 'rarity' => ItemRarity::Common, 'result_item_key' => 'wooden_chair', 'result_quantity' => 1, 'ingredients' => ['wood_plank' => 4, 'rope' => 1]],
            ['key' => 'simple_table', 'name' => 'Mesa sencilla', 'category' => RecipeCategory::House, 'rarity' => ItemRarity::Common, 'result_item_key' => 'simple_table', 'result_quantity' => 1, 'ingredients' => ['wood_plank' => 6, 'rope' => 2]],
            ['key' => 'small_chest', 'name' => 'Cofre pequeño', 'category' => RecipeCategory::House, 'rarity' => ItemRarity::Uncommon, 'result_item_key' => 'small_chest', 'result_quantity' => 1, 'ingredients' => ['wood_plank' => 5, 'iron_ingot' => 2]],
            ['key' => 'decorative_pot', 'name' => 'Maceta decorativa', 'category' => RecipeCategory::House, 'rarity' => ItemRarity::Common, 'result_item_key' => 'decorative_pot', 'result_quantity' => 1, 'ingredients' => ['cut_stone' => 2, 'wood_plank' => 1]],

            // Fase 9.1: nuevas, mismo patrón que small_chest -ya tenían
            // metadata de colocación desde Fase 9, solo les faltaba
            // receta-. Costo de materiales proporcional a su tamaño/
            // sell_value real (crate 20 < nightstand 35 < bed 80, ver
            // RoomFurnitureSeeder), no inventado.
            ['key' => 'nightstand', 'name' => 'Velador', 'category' => RecipeCategory::House, 'rarity' => ItemRarity::Common, 'result_item_key' => 'nightstand', 'result_quantity' => 1, 'ingredients' => ['wood_plank' => 3, 'rope' => 1]],
            ['key' => 'wooden_crate', 'name' => 'Caja de madera', 'category' => RecipeCategory::House, 'rarity' => ItemRarity::Common, 'result_item_key' => 'wooden_crate', 'result_quantity' => 1, 'ingredients' => ['wood_plank' => 2, 'rope' => 1]],
            ['key' => 'bed_frame', 'name' => 'Cama', 'category' => RecipeCategory::House, 'rarity' => ItemRarity::Common, 'result_item_key' => 'bed_frame', 'result_quantity' => 1, 'ingredients' => ['wood_plank' => 8, 'reinforced_cloth' => 2, 'rope' => 2]],

            // Fase 9.1: sin sprite real que las represente en el atlas
            // -se revisó Interior_Props_01.png completo; lo más cercano a
            // "linterna" es un candelabro de techo y a "tótem" son
            // cabezas de trofeo de lobo/oso, ninguno es honestamente el
            // objeto que el nombre describe- así que quedan INACTIVAS:
            // no aparecen en /crafting (RecipeController filtra por
            // is_active) hasta que exista un sprite real que las
            // respalde. El Item se conserva intacto (rule: no romper
            // datos existentes), solo se le apaga la receta.
            ['key' => 'lantern', 'name' => 'Linterna', 'category' => RecipeCategory::House, 'rarity' => ItemRarity::Uncommon, 'result_item_key' => 'lantern', 'result_quantity' => 1, 'ingredients' => ['wood_plank' => 2, 'black_candle' => 1, 'rope' => 1], 'is_active' => false],
            ['key' => 'ancient_totem', 'name' => 'Tótem antiguo', 'category' => RecipeCategory::House, 'rarity' => ItemRarity::Rare, 'result_item_key' => 'ancient_totem', 'result_quantity' => 1, 'ingredients' => ['cut_stone' => 3, 'bone_fragment' => 1, 'dark_feather' => 1], 'is_active' => false],

            // ===================== EQUIPAMIENTO =====================
            ['key' => 'simple_sword', 'name' => 'Espada sencilla', 'category' => RecipeCategory::Equipment, 'rarity' => ItemRarity::Common, 'result_item_key' => 'simple_sword', 'result_quantity' => 1, 'ingredients' => ['iron_ingot' => 2, 'wood_plank' => 2, 'rope' => 1]],
            ['key' => 'adventurer_axe', 'name' => 'Hacha de aventurero', 'category' => RecipeCategory::Equipment, 'rarity' => ItemRarity::Common, 'result_item_key' => 'adventurer_axe', 'result_quantity' => 1, 'ingredients' => ['iron_ingot' => 2, 'wood_plank' => 2]],
            ['key' => 'reinforced_wooden_shield', 'name' => 'Escudo de madera reforzado', 'category' => RecipeCategory::Equipment, 'rarity' => ItemRarity::Uncommon, 'result_item_key' => 'reinforced_wooden_shield', 'result_quantity' => 1, 'ingredients' => ['wood_plank' => 4, 'iron_ingot' => 2, 'rope' => 1]],
            ['key' => 'crimson_sword', 'name' => 'Espada carmesí', 'category' => RecipeCategory::Equipment, 'rarity' => ItemRarity::Rare, 'result_item_key' => 'crimson_sword', 'result_quantity' => 1, 'ingredients' => ['iron_ingot' => 2, 'crimson_crystal' => 1, 'dark_feather' => 1, 'rope' => 1]],
        ];
    }
}
