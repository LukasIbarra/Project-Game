<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// F8: venta + moneda. sell_value SIEMPRE sale del item en DB (sección 11
// de la fase) -estos tests deliberadamente nunca mandan un price/total
// desde el request, para confirmar que no hay forma de hacerlo.
class EconomyTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    private function makeSellableItem(int $sellValue = 5, bool $stackable = true): Item
    {
        return Item::create([
            'key' => 'test_sellable_'.uniqid(),
            'name' => 'Item vendible de prueba',
            'type' => ItemType::Resource,
            'stackable' => $stackable,
            'max_stack' => 99,
            'sell_value' => $sellValue,
        ]);
    }

    public function test_puede_vender_un_item_propio_y_recibe_las_monedas_correctas(): void
    {
        [$character, $token] = $this->characterWithToken();
        $item = $this->makeSellableItem(sellValue: 7);

        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 10]);

        $response = $this->withToken($token)->postJson('/api/v1/inventory/sell', [
            'item_key' => $item->key,
            'quantity' => 4,
        ]);

        $response->assertOk();
        $response->assertJsonPath('earned', 28); // 4 * 7
        $response->assertJsonPath('coins', 28);

        $this->assertEquals(6, InventoryItem::where('character_id', $character->id)->where('item_id', $item->id)->sum('quantity'));
        $this->assertEquals(28, $character->fresh()->coins);
    }

    public function test_no_puede_vender_item_de_otro_personaje(): void
    {
        [$characterA, $tokenA] = $this->characterWithToken();
        [, $tokenB] = $this->characterWithToken();
        $item = $this->makeSellableItem();

        InventoryItem::create(['character_id' => $characterA->id, 'item_id' => $item->id, 'quantity' => 5]);

        $this->app['auth']->forgetGuards();

        // B no tiene ninguna unidad de este item -su propio inventario
        // está vacío-, así que la venta debe fallar por cantidad
        // insuficiente, nunca tocar el stock de A.
        $this->withToken($tokenB)->postJson('/api/v1/inventory/sell', [
            'item_key' => $item->key,
            'quantity' => 1,
        ])->assertStatus(422);

        $this->assertEquals(5, InventoryItem::where('character_id', $characterA->id)->sum('quantity'));
    }

    public function test_no_puede_vender_cantidad_superior_a_la_disponible(): void
    {
        [$character, $token] = $this->characterWithToken();
        $item = $this->makeSellableItem();

        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 3]);

        $this->withToken($token)->postJson('/api/v1/inventory/sell', [
            'item_key' => $item->key,
            'quantity' => 4,
        ])->assertStatus(422);

        $this->assertEquals(0, $character->fresh()->coins);
        $this->assertEquals(3, InventoryItem::where('character_id', $character->id)->sum('quantity'));
    }

    public function test_no_puede_vender_cantidad_cero_o_negativa(): void
    {
        [$character, $token] = $this->characterWithToken();
        $item = $this->makeSellableItem();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 5]);

        $this->withToken($token)->postJson('/api/v1/inventory/sell', [
            'item_key' => $item->key,
            'quantity' => 0,
        ])->assertStatus(422);

        $this->withToken($token)->postJson('/api/v1/inventory/sell', [
            'item_key' => $item->key,
            'quantity' => -3,
        ])->assertStatus(422);
    }

    public function test_no_puede_vender_un_item_no_vendible(): void
    {
        [$character, $token] = $this->characterWithToken();
        $item = $this->makeSellableItem(sellValue: 0);
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 5]);

        $this->withToken($token)->postJson('/api/v1/inventory/sell', [
            'item_key' => $item->key,
            'quantity' => 1,
        ])->assertStatus(422);
    }

    public function test_venta_no_stackable_consume_instancias_individuales(): void
    {
        [$character, $token] = $this->characterWithToken();
        $item = $this->makeSellableItem(sellValue: 10, stackable: false);

        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 1]);
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 1]);
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 1]);

        $response = $this->withToken($token)->postJson('/api/v1/inventory/sell', [
            'item_key' => $item->key,
            'quantity' => 2,
        ]);

        $response->assertOk();
        $this->assertEquals(1, InventoryItem::where('character_id', $character->id)->count());
        $this->assertEquals(20, $character->fresh()->coins);
    }

    public function test_precio_de_venta_siempre_sale_del_backend_nunca_del_cliente(): void
    {
        [$character, $token] = $this->characterWithToken();
        $item = $this->makeSellableItem(sellValue: 3);
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 1]);

        // El request manda "price"/"total_price" manipulados -no existe
        // ningún campo que la validación acepte para eso, así que se
        // ignoran silenciosamente y el servidor sigue usando sell_value.
        $response = $this->withToken($token)->postJson('/api/v1/inventory/sell', [
            'item_key' => $item->key,
            'quantity' => 1,
            'price' => 999999,
            'total_price' => 999999,
        ]);

        $response->assertOk();
        $response->assertJsonPath('earned', 3);
        $this->assertEquals(3, $character->fresh()->coins);
    }

    public function test_venta_reduce_inventario_y_aumenta_monedas_de_forma_transaccional(): void
    {
        [$character, $token] = $this->characterWithToken();
        $item = $this->makeSellableItem(sellValue: 5);
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 20]);

        $this->withToken($token)->postJson('/api/v1/inventory/sell', [
            'item_key' => $item->key,
            'quantity' => 12,
        ])->assertOk();

        $this->assertEquals(8, InventoryItem::where('character_id', $character->id)->sum('quantity'));
        $this->assertEquals(60, $character->fresh()->coins);
    }
}
