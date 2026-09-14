<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Room;
use App\Models\RoomItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Room: habitación personal + muebles colocables. `small_chest` viene
// sembrado (F8 + RoomFurnitureSeeder) con metadata_json de colocación
// real -se usa tal cual en estos tests en vez de fabricar un item de
// prueba, porque la validación depende de esa metadata (tile_width/
// height/collision), no de un item genérico cualquiera.
class RoomTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    private function chestItem(): Item
    {
        return Item::where('key', 'small_chest')->firstOrFail();
    }

    public function test_usuario_puede_obtener_su_habitacion(): void
    {
        [, $token] = $this->characterWithToken();

        $response = $this->withToken($token)->getJson('/api/v1/room');

        $response->assertOk();
        $response->assertJsonPath('objects', []);
    }

    public function test_usuario_no_puede_acceder_a_room_objects_de_otro_usuario(): void
    {
        [$characterA, $tokenA] = $this->characterWithToken();
        [, $tokenB] = $this->characterWithToken();
        $chest = $this->chestItem();

        InventoryItem::create(['character_id' => $characterA->id, 'item_id' => $chest->id, 'quantity' => 1]);
        $placed = $this->withToken($tokenA)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 5,
            'tile_y' => 5,
        ]);
        $placed->assertStatus(201);
        $objectId = $placed->json('objects.0.id');

        $this->app['auth']->forgetGuards();

        $this->withToken($tokenB)->patchJson("/api/v1/room/objects/{$objectId}", ['tile_x' => 1, 'tile_y' => 1])
            ->assertStatus(403);
        $this->withToken($tokenB)->deleteJson("/api/v1/room/objects/{$objectId}")
            ->assertStatus(403);
    }

    public function test_puede_colocar_furniture_que_posee_y_descuenta_inventario(): void
    {
        [$character, $token] = $this->characterWithToken();
        $chest = $this->chestItem();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $chest->id, 'quantity' => 2]);

        $response = $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 5,
            'tile_y' => 5,
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(1, 'objects');
        $this->assertEquals(1, InventoryItem::where('character_id', $character->id)->where('item_id', $chest->id)->sum('quantity'));
    }

    public function test_no_puede_colocar_furniture_que_no_posee(): void
    {
        [, $token] = $this->characterWithToken();
        $chest = $this->chestItem();

        $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 5,
            'tile_y' => 5,
        ])->assertStatus(422);
    }

    public function test_no_puede_colocar_un_item_que_no_es_furniture(): void
    {
        [$character, $token] = $this->characterWithToken();
        $notFurniture = Item::where('key', 'wood')->firstOrFail();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $notFurniture->id, 'quantity' => 5]);

        $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $notFurniture->key,
            'tile_x' => 5,
            'tile_y' => 5,
        ])->assertStatus(422);
    }

    public function test_posicion_fuera_de_limites_es_rechazada(): void
    {
        [$character, $token] = $this->characterWithToken();
        $chest = $this->chestItem();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $chest->id, 'quantity' => 1]);

        // Habitación = 32x24 tiles; el cofre mide 2x2 -> tile_x=31 no entra.
        $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 31,
            'tile_y' => 5,
        ])->assertStatus(422);
    }

    public function test_posicion_sobre_pared_es_rechazada(): void
    {
        [$character, $token] = $this->characterWithToken();
        $chest = $this->chestItem();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $chest->id, 'quantity' => 1]);

        // tile(0,0) cae sobre "pared trasera"/"pared izquierda".
        $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 0,
            'tile_y' => 0,
        ])->assertStatus(422);
    }

    public function test_overlap_con_otro_furniture_es_rechazado(): void
    {
        [$character, $token] = $this->characterWithToken();
        $chest = $this->chestItem();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $chest->id, 'quantity' => 2]);

        $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 5,
            'tile_y' => 5,
        ])->assertStatus(201);

        // Mismo tile exacto -> se superpone con el que ya está.
        $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 5,
            'tile_y' => 5,
        ])->assertStatus(422);
    }

    public function test_puede_mover_su_furniture(): void
    {
        [$character, $token] = $this->characterWithToken();
        $chest = $this->chestItem();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $chest->id, 'quantity' => 1]);

        $placed = $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 5,
            'tile_y' => 5,
        ]);
        $objectId = $placed->json('objects.0.id');

        $response = $this->withToken($token)->patchJson("/api/v1/room/objects/{$objectId}", [
            'tile_x' => 10,
            'tile_y' => 12,
        ]);

        $response->assertOk();
        $this->assertEquals(10, RoomItem::find($objectId)->x);
        $this->assertEquals(12, RoomItem::find($objectId)->y);
    }

    public function test_mover_a_posicion_invalida_es_rechazado(): void
    {
        [$character, $token] = $this->characterWithToken();
        $chest = $this->chestItem();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $chest->id, 'quantity' => 1]);

        $placed = $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 5,
            'tile_y' => 5,
        ]);
        $objectId = $placed->json('objects.0.id');

        $this->withToken($token)->patchJson("/api/v1/room/objects/{$objectId}", [
            'tile_x' => 0,
            'tile_y' => 0,
        ])->assertStatus(422);

        $this->assertEquals(5, RoomItem::find($objectId)->x);
    }

    public function test_eliminar_furniture_lo_devuelve_al_inventario(): void
    {
        [$character, $token] = $this->characterWithToken();
        $chest = $this->chestItem();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $chest->id, 'quantity' => 1]);

        $placed = $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 5,
            'tile_y' => 5,
        ]);
        $objectId = $placed->json('objects.0.id');
        $this->assertEquals(0, InventoryItem::where('character_id', $character->id)->where('item_id', $chest->id)->sum('quantity'));

        $response = $this->withToken($token)->deleteJson("/api/v1/room/objects/{$objectId}");

        $response->assertOk();
        $response->assertJsonCount(0, 'objects');
        $this->assertEquals(1, InventoryItem::where('character_id', $character->id)->where('item_id', $chest->id)->sum('quantity'));
    }

    public function test_cerrar_y_reabrir_habitacion_mantiene_los_objetos(): void
    {
        [$character, $token] = $this->characterWithToken();
        $chest = $this->chestItem();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $chest->id, 'quantity' => 1]);

        $this->withToken($token)->postJson('/api/v1/room/objects', [
            'item_key' => $chest->key,
            'tile_x' => 5,
            'tile_y' => 5,
        ])->assertStatus(201);

        // "cerrar y volver a entrar" = simplemente otra consulta GET,
        // sin ningún estado de sesión de por medio.
        $response = $this->withToken($token)->getJson('/api/v1/room');

        $response->assertOk();
        $response->assertJsonCount(1, 'objects');
        $response->assertJsonPath('objects.0.item.key', 'small_chest');
    }
}
