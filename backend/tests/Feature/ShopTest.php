<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\ShopProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Fase 16: Tienda (primera versión). Mismo criterio que EconomyTest -el
// precio SIEMPRE sale de shop_products en DB, la operación es atómica
// (nunca coins descontadas sin el item entregado), y coins/inventario
// usan exactamente los mismos mecanismos que ya existían (Character.coins,
// InventoryItem), sin sistema paralelo.
class ShopTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(array $attrs = []): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(array_merge(['user_id' => $user->id], $attrs));
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    private function makeProduct(int $price = 10, bool $active = true): array
    {
        $item = Item::create([
            'key' => 'test_shop_item_'.uniqid(),
            'name' => 'Item de tienda de prueba',
            'type' => ItemType::Resource,
            'stackable' => true,
            'max_stack' => 99,
            'sell_value' => 1,
        ]);
        $product = ShopProduct::create(['item_id' => $item->id, 'price' => $price, 'is_active' => $active]);

        return [$item, $product];
    }

    public function test_get_shop_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/v1/shop')->assertStatus(401);
    }

    public function test_get_shop_devuelve_los_productos_activos_con_precio(): void
    {
        [, $token] = $this->characterWithToken(['coins' => 50]);
        [$item] = $this->makeProduct(price: 15);

        $response = $this->withToken($token)->getJson('/api/v1/shop');

        $response->assertOk();
        $response->assertJsonPath('coins', 50);
        $entry = collect($response->json('products'))->firstWhere('item_key', $item->key);
        $this->assertNotNull($entry);
        $this->assertEquals(15, $entry['price']);
    }

    public function test_get_shop_no_incluye_productos_inactivos(): void
    {
        [, $token] = $this->characterWithToken();
        [$item] = $this->makeProduct(price: 20, active: false);

        $response = $this->withToken($token)->getJson('/api/v1/shop');

        $response->assertOk();
        $entry = collect($response->json('products'))->firstWhere('item_key', $item->key);
        $this->assertNull($entry);
    }

    public function test_comprar_con_monedas_suficientes_descuenta_coins_y_entrega_el_item(): void
    {
        [$character, $token] = $this->characterWithToken(['coins' => 100]);
        [$item] = $this->makeProduct(price: 15);

        $response = $this->withToken($token)->postJson('/api/v1/shop/purchase', [
            'item_key' => $item->key,
            'quantity' => 3,
        ]);

        $response->assertOk();
        $response->assertJsonPath('spent', 45); // 3 * 15
        $response->assertJsonPath('coins', 55); // 100 - 45

        $this->assertEquals(55, $character->fresh()->coins);
        $this->assertEquals(3, InventoryItem::where('character_id', $character->id)->where('item_id', $item->id)->sum('quantity'));
        $this->assertDatabaseHas('activity_events', [
            'character_id' => $character->id,
            'type' => 'purchase',
        ]);
    }

    public function test_compra_sin_monedas_suficientes_es_rechazada_y_no_cambia_nada(): void
    {
        [$character, $token] = $this->characterWithToken(['coins' => 10]);
        [$item] = $this->makeProduct(price: 15);

        $response = $this->withToken($token)->postJson('/api/v1/shop/purchase', [
            'item_key' => $item->key,
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
        // Ni las coins ni el inventario deben haber cambiado -la
        // validación de fondos ocurre ANTES de la transacción-.
        $this->assertEquals(10, $character->fresh()->coins);
        $this->assertEquals(0, InventoryItem::where('character_id', $character->id)->where('item_id', $item->id)->sum('quantity'));
    }

    public function test_no_puede_comprar_un_item_que_no_esta_en_la_tienda(): void
    {
        [$character, $token] = $this->characterWithToken(['coins' => 1000]);
        $item = Item::create([
            'key' => 'not_in_shop_'.uniqid(),
            'name' => 'No listado',
            'type' => ItemType::Resource,
            'stackable' => true,
            'max_stack' => 99,
            'sell_value' => 1,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/shop/purchase', [
            'item_key' => $item->key,
            'quantity' => 1,
        ]);

        $response->assertStatus(404);
        $this->assertEquals(1000, $character->fresh()->coins);
    }

    public function test_no_puede_comprar_un_producto_desactivado(): void
    {
        [$character, $token] = $this->characterWithToken(['coins' => 1000]);
        [$item] = $this->makeProduct(price: 10, active: false);

        $response = $this->withToken($token)->postJson('/api/v1/shop/purchase', [
            'item_key' => $item->key,
            'quantity' => 1,
        ]);

        $response->assertStatus(404);
        $this->assertEquals(1000, $character->fresh()->coins);
    }

    public function test_no_puede_comprar_un_item_key_inexistente(): void
    {
        [, $token] = $this->characterWithToken(['coins' => 1000]);

        $this->withToken($token)->postJson('/api/v1/shop/purchase', [
            'item_key' => 'esto_no_existe_'.uniqid(),
            'quantity' => 1,
        ])->assertStatus(422);
    }

    public function test_no_puede_comprar_cantidad_cero_o_negativa(): void
    {
        [, $token] = $this->characterWithToken(['coins' => 1000]);
        [$item] = $this->makeProduct(price: 5);

        $this->withToken($token)->postJson('/api/v1/shop/purchase', [
            'item_key' => $item->key,
            'quantity' => 0,
        ])->assertStatus(422);

        $this->withToken($token)->postJson('/api/v1/shop/purchase', [
            'item_key' => $item->key,
            'quantity' => -2,
        ])->assertStatus(422);
    }

    public function test_el_precio_de_compra_siempre_sale_del_backend_nunca_del_cliente(): void
    {
        [$character, $token] = $this->characterWithToken(['coins' => 100]);
        [$item] = $this->makeProduct(price: 10);

        // El request manda "price" manipulado -no existe ningún campo que
        // la validación acepte para eso, se ignora silenciosamente-.
        $response = $this->withToken($token)->postJson('/api/v1/shop/purchase', [
            'item_key' => $item->key,
            'quantity' => 1,
            'price' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('spent', 10);
        $this->assertEquals(90, $character->fresh()->coins);
    }

    public function test_compra_es_atomica_stackable(): void
    {
        [$character, $token] = $this->characterWithToken(['coins' => 100]);
        [$item] = $this->makeProduct(price: 20);

        $this->withToken($token)->postJson('/api/v1/shop/purchase', [
            'item_key' => $item->key,
            'quantity' => 2,
        ])->assertOk();

        // 100 - 40 = 60 coins, y exactamente 2 unidades entregadas -nunca
        // coins descontadas de más/de menos respecto al item entregado-.
        $this->assertEquals(60, $character->fresh()->coins);
        $this->assertEquals(2, InventoryItem::where('character_id', $character->id)->where('item_id', $item->id)->sum('quantity'));
    }
}
