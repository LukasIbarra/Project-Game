<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EquipmentTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    private function makeItem(array $overrides = []): Item
    {
        return Item::create(array_merge([
            'key' => 'test_hair_'.uniqid(),
            'name' => 'Pelo de prueba',
            'type' => ItemType::Cosmetic,
            'subtype' => 'hair',
        ], $overrides));
    }

    public function test_equipar_item_propio_funciona_y_actualiza_appearance_json(): void
    {
        [$character, $token] = $this->characterWithToken();
        $item = $this->makeItem();

        $inventoryItem = InventoryItem::create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/equipment/equip', [
            'inventory_item_id' => $inventoryItem->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('appearance.hair', $item->key);
        $response->assertJsonCount(1, 'equipment');

        $this->assertDatabaseHas('character_equipment', [
            'character_id' => $character->id,
            'inventory_item_id' => $inventoryItem->id,
            'slot' => 'hair',
        ]);
    }

    public function test_equipar_instancia_de_otro_personaje_falla(): void
    {
        [$characterA, $tokenA] = $this->characterWithToken();
        [$characterB] = $this->characterWithToken();
        $item = $this->makeItem();

        $inventoryItemOfB = InventoryItem::create([
            'character_id' => $characterB->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $this->withToken($tokenA)->postJson('/api/v1/equipment/equip', [
            'inventory_item_id' => $inventoryItemOfB->id,
        ])->assertStatus(403);
    }

    public function test_equipar_item_no_equipable_falla(): void
    {
        [$character, $token] = $this->characterWithToken();

        $resource = $this->makeItem([
            'key' => 'test_resource_'.uniqid(),
            'type' => ItemType::Resource,
            'subtype' => null,
        ]);

        $inventoryItem = InventoryItem::create([
            'character_id' => $character->id,
            'item_id' => $resource->id,
            'quantity' => 1,
        ]);

        $this->withToken($token)->postJson('/api/v1/equipment/equip', [
            'inventory_item_id' => $inventoryItem->id,
        ])->assertStatus(422);
    }

    public function test_equipar_en_un_slot_ya_ocupado_reemplaza_lo_anterior(): void
    {
        [$character, $token] = $this->characterWithToken();
        $hairA = $this->makeItem(['key' => 'hair_a_'.uniqid()]);
        $hairB = $this->makeItem(['key' => 'hair_b_'.uniqid()]);

        $invA = InventoryItem::create(['character_id' => $character->id, 'item_id' => $hairA->id, 'quantity' => 1]);
        $invB = InventoryItem::create(['character_id' => $character->id, 'item_id' => $hairB->id, 'quantity' => 1]);

        $this->withToken($token)->postJson('/api/v1/equipment/equip', ['inventory_item_id' => $invA->id])->assertOk();
        $response = $this->withToken($token)->postJson('/api/v1/equipment/equip', ['inventory_item_id' => $invB->id]);

        $response->assertOk();
        $response->assertJsonCount(1, 'equipment');
        $response->assertJsonPath('appearance.hair', $hairB->key);
    }

    public function test_desequipar_funciona_y_limpia_appearance_json(): void
    {
        [$character, $token] = $this->characterWithToken();
        $item = $this->makeItem();

        $inventoryItem = InventoryItem::create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $this->withToken($token)->postJson('/api/v1/equipment/equip', [
            'inventory_item_id' => $inventoryItem->id,
        ])->assertOk();

        $response = $this->withToken($token)->deleteJson('/api/v1/equipment/hair');

        $response->assertOk();
        $response->assertJsonCount(0, 'equipment');
        $response->assertJsonPath('appearance.hair', null);

        $this->assertDatabaseMissing('character_equipment', [
            'character_id' => $character->id,
            'slot' => 'hair',
        ]);
    }

    public function test_desequipar_slot_vacio_devuelve_404(): void
    {
        [, $token] = $this->characterWithToken();

        $this->withToken($token)->deleteJson('/api/v1/equipment/hair')->assertStatus(404);
    }
}
