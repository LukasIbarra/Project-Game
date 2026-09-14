<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Fase 9.1: consistencia entre /crafting y la habitación. La regla de
// negocio central de esta fase es "todo furniture que aparece como
// crafteable en la categoría Casa debe poder recorrer todo el flujo
// Craft -> Inventory -> Place" (nunca un mueble fabricable que no se
// pueda colocar de verdad). Estos tests usan el catálogo SEMBRADO real
// (RecipeSeeder/RoomFurnitureSeeder vía DatabaseTransactions sobre la DB
// de desarrollo, mismo patrón que RoomTest/CraftingTest) porque la regla
// que se verifica es sobre los DATOS reales, no sobre fixtures sintéticos.
class RoomFurnitureCraftingTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    public function test_toda_receta_activa_de_casa_produce_furniture_realmente_colocable(): void
    {
        $recipes = Recipe::with('result')->where('category', 'house')->where('is_active', true)->get();

        $this->assertGreaterThan(0, $recipes->count(), 'Debe haber al menos una receta de Casa activa.');

        foreach ($recipes as $recipe) {
            $metadata = $recipe->result->metadata_json;

            $this->assertNotNull($metadata, "La receta '{$recipe->key}' produce '{$recipe->result->key}', que no tiene metadata_json (no es colocable).");
            $this->assertTrue($metadata['placeable'] ?? false, "El resultado de '{$recipe->key}' ('{$recipe->result->key}') tiene placeable=false.");
            $this->assertArrayHasKey('tile_width', $metadata);
            $this->assertArrayHasKey('tile_height', $metadata);
            $this->assertArrayHasKey('collision', $metadata);
        }
    }

    public function test_lantern_y_ancient_totem_quedan_inactivas_y_no_aparecen_en_crafting(): void
    {
        $lantern = Recipe::where('key', 'lantern')->firstOrFail();
        $totem = Recipe::where('key', 'ancient_totem')->firstOrFail();

        $this->assertFalse($lantern->is_active);
        $this->assertFalse($totem->is_active);

        // El Item se conserva -no se rompe nada de lo existente-, solo
        // queda sin metadata de colocación (no hay sprite real para él).
        $this->assertNotNull(Item::where('key', 'lantern')->firstOrFail());
        $this->assertNotNull(Item::where('key', 'ancient_totem')->firstOrFail());

        [, $token] = $this->characterWithToken();
        $keys = collect($this->withToken($token)->getJson('/api/v1/recipes')->json())->pluck('key');

        $this->assertNotContains('lantern', $keys);
        $this->assertNotContains('ancient_totem', $keys);
    }

    public function test_bed_frame_nightstand_y_wooden_crate_tienen_receta_activa(): void
    {
        foreach (['bed_frame', 'nightstand', 'wooden_crate'] as $key) {
            $recipe = Recipe::with('ingredients')->where('key', $key)->first();

            $this->assertNotNull($recipe, "Falta la receta de '{$key}'.");
            $this->assertTrue($recipe->is_active);
            $this->assertGreaterThan(0, $recipe->ingredients->count());
        }
    }

    public function test_flujo_completo_craftear_silla_y_colocarla_en_la_habitacion(): void
    {
        [$character, $token] = $this->characterWithToken();
        $recipe = Recipe::with('ingredients.item')->where('key', 'wooden_chair')->firstOrFail();

        foreach ($recipe->ingredients as $ingredient) {
            InventoryItem::create([
                'character_id' => $character->id,
                'item_id' => $ingredient->item_id,
                'quantity' => $ingredient->quantity,
            ]);
        }

        $this->withToken($token)->postJson('/api/v1/crafting/craft', ['recipe_key' => 'wooden_chair'])
            ->assertOk();

        $this->assertEquals(1, InventoryItem::where('character_id', $character->id)
            ->where('item_id', $recipe->result_item_id)->sum('quantity'));

        $placed = $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => 'wooden_chair',
            'tile_x' => 5,
            'tile_y' => 5,
        ]);

        $placed->assertStatus(201);
        $placed->assertJsonPath('objects.0.item.key', 'wooden_chair');
        $this->assertEquals(0, InventoryItem::where('character_id', $character->id)
            ->where('item_id', $recipe->result_item_id)->sum('quantity'));
    }

    public function test_flujo_completo_craftear_velador_y_colocarlo_en_la_habitacion(): void
    {
        [$character, $token] = $this->characterWithToken();
        $recipe = Recipe::with('ingredients.item')->where('key', 'nightstand')->firstOrFail();

        foreach ($recipe->ingredients as $ingredient) {
            InventoryItem::create([
                'character_id' => $character->id,
                'item_id' => $ingredient->item_id,
                'quantity' => $ingredient->quantity,
            ]);
        }

        $this->withToken($token)->postJson('/api/v1/crafting/craft', ['recipe_key' => 'nightstand'])
            ->assertOk();

        $placed = $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => 'nightstand',
            'tile_x' => 8,
            'tile_y' => 8,
        ]);

        $placed->assertStatus(201);
        $placed->assertJsonPath('objects.0.item.key', 'nightstand');
    }
}
