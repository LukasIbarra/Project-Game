<?php

namespace Tests\Feature;

use App\Enums\ItemRarity;
use App\Enums\ItemType;
use App\Enums\RecipeCategory;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CraftingTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    private function makeItem(string $keyPrefix, bool $stackable = true): Item
    {
        return Item::create([
            'key' => $keyPrefix.'_'.uniqid(),
            'name' => ucfirst($keyPrefix).' de prueba',
            'type' => ItemType::Resource,
            'stackable' => $stackable,
            'max_stack' => 99,
        ]);
    }

    /**
     * @param  array<int, array{0: Item, 1: int}>  $ingredients  tuplas [item, quantity]
     */
    private function makeRecipe(array $ingredients, Item $result, int $resultQuantity = 1, int $requiredLevel = 1, ?string $unlockCondition = null, bool $isActive = true): Recipe
    {
        $recipe = Recipe::create([
            'key' => 'test_recipe_'.uniqid(),
            'name' => 'Receta de prueba',
            'category' => RecipeCategory::Materials,
            'rarity' => ItemRarity::Common,
            'result_item_id' => $result->id,
            'result_quantity' => $resultQuantity,
            'required_level' => $requiredLevel,
            'unlock_condition' => $unlockCondition,
            'is_active' => $isActive,
        ]);

        foreach ($ingredients as [$item, $quantity]) {
            RecipeIngredient::create([
                'recipe_id' => $recipe->id,
                'item_id' => $item->id,
                'quantity' => $quantity,
            ]);
        }

        return $recipe;
    }

    public function test_puede_fabricar_una_receta_con_materiales_suficientes(): void
    {
        [$character, $token] = $this->characterWithToken();
        $ingredient = $this->makeItem('wood');
        $result = $this->makeItem('plank');

        InventoryItem::create(['character_id' => $character->id, 'item_id' => $ingredient->id, 'quantity' => 5]);
        $recipe = $this->makeRecipe([[$ingredient, 3]], $result, resultQuantity: 2);

        $response = $this->withToken($token)->postJson('/api/v1/crafting/craft', [
            'recipe_key' => $recipe->key,
        ]);

        $response->assertOk();
        $this->assertEquals(2, InventoryItem::where('character_id', $character->id)->where('item_id', $ingredient->id)->sum('quantity'));
        $this->assertEquals(2, InventoryItem::where('character_id', $character->id)->where('item_id', $result->id)->sum('quantity'));
    }

    public function test_crafting_falla_si_falta_material_y_no_consume_nada(): void
    {
        [$character, $token] = $this->characterWithToken();
        $ingredient = $this->makeItem('iron');
        $result = $this->makeItem('ingot');

        InventoryItem::create(['character_id' => $character->id, 'item_id' => $ingredient->id, 'quantity' => 2]);
        $recipe = $this->makeRecipe([[$ingredient, 3]], $result);

        $response = $this->withToken($token)->postJson('/api/v1/crafting/craft', [
            'recipe_key' => $recipe->key,
        ]);

        $response->assertStatus(422);
        $this->assertEquals(2, InventoryItem::where('character_id', $character->id)->where('item_id', $ingredient->id)->sum('quantity'));
        $this->assertEquals(0, InventoryItem::where('character_id', $character->id)->where('item_id', $result->id)->sum('quantity'));
    }

    public function test_crafting_con_multiples_ingredientes_consume_todos_y_agrega_el_resultado(): void
    {
        [$character, $token] = $this->characterWithToken();
        $a = $this->makeItem('a');
        $b = $this->makeItem('b');
        $result = $this->makeItem('c');

        InventoryItem::create(['character_id' => $character->id, 'item_id' => $a->id, 'quantity' => 10]);
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $b->id, 'quantity' => 10]);
        $recipe = $this->makeRecipe([[$a, 2], [$b, 1]], $result);

        $this->withToken($token)->postJson('/api/v1/crafting/craft', ['recipe_key' => $recipe->key])->assertOk();

        $this->assertEquals(8, InventoryItem::where('character_id', $character->id)->where('item_id', $a->id)->sum('quantity'));
        $this->assertEquals(9, InventoryItem::where('character_id', $character->id)->where('item_id', $b->id)->sum('quantity'));
        $this->assertEquals(1, InventoryItem::where('character_id', $character->id)->where('item_id', $result->id)->sum('quantity'));
    }

    public function test_crafting_no_puede_usar_inventario_de_otro_personaje(): void
    {
        [$characterA, $tokenA] = $this->characterWithToken();
        [, $tokenB] = $this->characterWithToken();
        $ingredient = $this->makeItem('wood');
        $result = $this->makeItem('plank');

        InventoryItem::create(['character_id' => $characterA->id, 'item_id' => $ingredient->id, 'quantity' => 10]);
        $recipe = $this->makeRecipe([[$ingredient, 3]], $result);

        $this->app['auth']->forgetGuards();

        $this->withToken($tokenB)->postJson('/api/v1/crafting/craft', ['recipe_key' => $recipe->key])
            ->assertStatus(422);

        $this->assertEquals(10, InventoryItem::where('character_id', $characterA->id)->sum('quantity'));
    }

    public function test_crafting_no_acepta_ingredientes_ni_output_manipulados_por_el_cliente(): void
    {
        [$character, $token] = $this->characterWithToken();
        $ingredient = $this->makeItem('wood');
        $result = $this->makeItem('plank');
        $fakeResult = $this->makeItem('fake_gold');

        InventoryItem::create(['character_id' => $character->id, 'item_id' => $ingredient->id, 'quantity' => 10]);
        $recipe = $this->makeRecipe([[$ingredient, 3]], $result, resultQuantity: 1);

        // El cliente intenta mandar ingredients/output_item/output_quantity
        // -no existen esos campos en CraftRequest, así que se ignoran y el
        // servidor sigue resolviendo todo desde la receta real.
        $response = $this->withToken($token)->postJson('/api/v1/crafting/craft', [
            'recipe_key' => $recipe->key,
            'ingredients' => [],
            'output_item' => $fakeResult->key,
            'output_quantity' => 999,
        ]);

        $response->assertOk();
        $this->assertEquals(0, InventoryItem::where('character_id', $character->id)->where('item_id', $fakeResult->id)->sum('quantity'));
        $this->assertEquals(1, InventoryItem::where('character_id', $character->id)->where('item_id', $result->id)->sum('quantity'));
    }

    public function test_crafting_respeta_stack_max_stack_existente(): void
    {
        [$character, $token] = $this->characterWithToken();
        $ingredient = $this->makeItem('wood');
        $result = $this->makeItem('plank');
        $result->update(['max_stack' => 5]);

        InventoryItem::create(['character_id' => $character->id, 'item_id' => $ingredient->id, 'quantity' => 3]);
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $result->id, 'quantity' => 4]);
        $recipe = $this->makeRecipe([[$ingredient, 3]], $result, resultQuantity: 4);

        $this->withToken($token)->postJson('/api/v1/crafting/craft', ['recipe_key' => $recipe->key])->assertOk();

        $rows = InventoryItem::where('character_id', $character->id)->where('item_id', $result->id)->get();
        $this->assertEquals(8, $rows->sum('quantity'));
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(5, $row->quantity);
        }
    }

    public function test_crafting_es_transaccional_falla_completa_si_un_ingrediente_intermedio_falta(): void
    {
        [$character, $token] = $this->characterWithToken();
        $a = $this->makeItem('a');
        $b = $this->makeItem('b');
        $result = $this->makeItem('c');

        InventoryItem::create(['character_id' => $character->id, 'item_id' => $a->id, 'quantity' => 10]);
        // Sin nada de $b -la receta necesita 1.
        $recipe = $this->makeRecipe([[$a, 2], [$b, 1]], $result);

        $this->withToken($token)->postJson('/api/v1/crafting/craft', ['recipe_key' => $recipe->key])
            ->assertStatus(422);

        // $a NO debe haberse consumido aunque sí alcanzaba -todo o nada-.
        $this->assertEquals(10, InventoryItem::where('character_id', $character->id)->where('item_id', $a->id)->sum('quantity'));
    }

    public function test_receta_inactiva_no_puede_fabricarse(): void
    {
        [$character, $token] = $this->characterWithToken();
        $ingredient = $this->makeItem('wood');
        $result = $this->makeItem('plank');
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $ingredient->id, 'quantity' => 10]);
        $recipe = $this->makeRecipe([[$ingredient, 1]], $result, isActive: false);

        $this->withToken($token)->postJson('/api/v1/crafting/craft', ['recipe_key' => $recipe->key])
            ->assertStatus(422);
    }

    public function test_receta_con_nivel_requerido_mayor_al_del_personaje_falla(): void
    {
        [$character, $token] = $this->characterWithToken();
        $ingredient = $this->makeItem('wood');
        $result = $this->makeItem('plank');
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $ingredient->id, 'quantity' => 10]);
        $recipe = $this->makeRecipe([[$ingredient, 1]], $result, requiredLevel: 5);

        // Character::factory() crea nivel 1 por defecto.
        $this->withToken($token)->postJson('/api/v1/crafting/craft', ['recipe_key' => $recipe->key])
            ->assertStatus(422);
    }

    public function test_receta_con_unlock_condition_queda_bloqueada_por_defecto(): void
    {
        [$character, $token] = $this->characterWithToken();
        $ingredient = $this->makeItem('wood');
        $result = $this->makeItem('plank');
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $ingredient->id, 'quantity' => 10]);
        $recipe = $this->makeRecipe([[$ingredient, 1]], $result, unlockCondition: 'future_condition');

        $this->withToken($token)->postJson('/api/v1/crafting/craft', ['recipe_key' => $recipe->key])
            ->assertStatus(422);
    }

    public function test_recipe_key_inexistente_falla(): void
    {
        [, $token] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/crafting/craft', [
            'recipe_key' => 'no_existe_'.uniqid(),
        ])->assertStatus(422);
    }
}
