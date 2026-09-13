<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// DatabaseTransactions, no RefreshDatabase: la DB de desarrollo real
// (MariaDB) ya tiene el esquema completo del pre-flight -no hace falta
// (ni conviene) migrar/dropear nada en cada corrida de tests. Cada test
// corre en una transacción que se revierte al final.
class InventoryTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    public function test_usuario_puede_consultar_su_inventario(): void
    {
        [$character, $token] = $this->characterWithToken();

        $item = Item::create([
            'key' => 'test_hair_'.uniqid(),
            'name' => 'Pelo de prueba',
            'type' => ItemType::Cosmetic,
            'subtype' => 'hair',
        ]);

        InventoryItem::create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/inventory');

        $response->assertOk()->assertJsonCount(1);
        $response->assertJsonPath('0.item.key', $item->key);
    }

    public function test_usuario_no_puede_consultar_inventario_de_otro_personaje_por_defecto(): void
    {
        // No existe un endpoint que reciba character_id -el propio diseño
        // de la API ya impide "consultar el inventario de otro": siempre
        // se resuelve del usuario autenticado. Este test lo verifica: dos
        // usuarios distintos ven inventarios distintos e independientes.
        [$characterA, $tokenA] = $this->characterWithToken();
        [$characterB, $tokenB] = $this->characterWithToken();

        $item = Item::create([
            'key' => 'test_item_'.uniqid(),
            'name' => 'Item de prueba',
            'type' => ItemType::Cosmetic,
            'subtype' => 'hair',
        ]);

        InventoryItem::create([
            'character_id' => $characterA->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $this->withToken($tokenB)->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(0);

        // Sanctum's guard caches the resolved user for the life of the
        // container -en test real de HTTP no pasa porque cada request
        // bootea un container nuevo-, así que hay que forzarlo a olvidar
        // antes de autenticar como un usuario distinto en el mismo test.
        $this->app['auth']->forgetGuards();

        $this->withToken($tokenA)->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(1);
    }

    public function test_grant_agrega_item_no_stackable_como_instancia_propia(): void
    {
        [$character, $token] = $this->characterWithToken();

        $item = Item::create([
            'key' => 'test_weapon_'.uniqid(),
            'name' => 'Arma de prueba',
            'type' => ItemType::Equipment,
            'subtype' => 'weapon',
            'stackable' => false,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/inventory/grant', [
            'item_key' => $item->key,
            'quantity' => 2,
        ]);

        $response->assertOk()->assertJsonCount(2);
    }

    public function test_grant_respeta_max_stack_de_item_stackable(): void
    {
        [$character, $token] = $this->characterWithToken();

        $item = Item::create([
            'key' => 'test_resource_'.uniqid(),
            'name' => 'Recurso de prueba',
            'type' => ItemType::Resource,
            'stackable' => true,
            'max_stack' => 10,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/inventory/grant', [
            'item_key' => $item->key,
            'quantity' => 25,
        ]);

        $response->assertOk();
        $rows = $response->json();

        // 25 con max_stack 10 -> 3 filas (10, 10, 5), nunca una fila > 10.
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(10, $row['quantity']);
        }
        $this->assertEquals(25, array_sum(array_column($rows, 'quantity')));
    }

    public function test_grant_rechaza_item_key_inexistente(): void
    {
        [, $token] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/inventory/grant', [
            'item_key' => 'no_existe_'.uniqid(),
        ])->assertStatus(422);
    }
}
