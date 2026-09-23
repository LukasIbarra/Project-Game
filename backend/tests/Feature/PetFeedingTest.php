<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Pet;
use App\Models\PetFoodItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Fase 20: POST /v1/pet/feed + GET /v1/pet/food. Sin expediciones,
// checkpoints ni eventos (eso es F21) -solo alimentación/EXP/level-up.
class PetFeedingTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    // F23: GET /pet ya no auto-crea nada -adopta un starter fijo (la
    // especie en sí no importa para estos tests, ninguno depende de cuál).
    // forgetGuards(): el entorno de test reutiliza el mismo objeto User
    // (con su relación character() ya cacheada) entre llamadas HTTP
    // sucesivas dentro de un mismo test -sin esto, la request de la propia
    // prueba que llama a este helper vería el Character desactualizado de
    // ANTES de adoptar, aunque la DB ya esté correcta. Nunca ocurre en
    // producción -ahí cada request es un proceso nuevo-.
    private function petFor(string $token): Pet
    {
        $petId = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->json('id');
        $this->app['auth']->forgetGuards();

        return Pet::findOrFail($petId);
    }

    private function giveInventory(Character $character, string $itemKey, int $quantity): Item
    {
        $item = Item::where('key', $itemKey)->firstOrFail();

        InventoryItem::create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
        ]);

        return $item;
    }

    // --- GET /pet/food ---

    public function test_get_pet_food_devuelve_el_catalogo_sembrado(): void
    {
        [, $token] = $this->characterWithToken();

        $response = $this->withToken($token)->getJson('/api/v1/pet/food');

        $response->assertOk();
        $keys = array_column($response->json(), 'item_key');
        $this->assertContains('pet_treat', $keys);
    }

    public function test_get_pet_food_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/v1/pet/food')->assertStatus(401);
    }

    // --- POST /pet/feed: casos válidos ---

    public function test_alimentar_sin_token_devuelve_401(): void
    {
        $this->postJson('/api/v1/pet/feed', ['food_key' => 'pet_treat'])->assertStatus(401);
    }

    public function test_alimentar_con_item_valido_otorga_exp_y_consume_el_item(): void
    {
        [$character, $token] = $this->characterWithToken();
        $pet = $this->petFor($token);
        $this->giveInventory($character, 'pet_treat', 3);

        $response = $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'pet_treat']);

        $response->assertOk();
        $response->assertJsonPath('pet.experience', 15); // pet_treat = 15 exp (PetFoodItemSeeder)
        $response->assertJsonPath('leveled_up', false);

        $this->assertDatabaseHas('inventory_items', [
            'character_id' => $character->id,
            'item_id' => Item::where('key', 'pet_treat')->value('id'),
            'quantity' => 2, // 3 - 1 consumido
        ]);
        $this->assertEquals(15, $pet->fresh()->exp);
    }

    public function test_alimentar_sube_de_nivel_al_alcanzar_el_umbral(): void
    {
        [$character, $token] = $this->characterWithToken();
        $pet = $this->petFor($token);
        $pet->update(['exp' => 40, 'level' => 1]); // umbral nivel 1->2 = 1*50 = 50
        $this->giveInventory($character, 'pet_treat', 1); // +15 exp -> 55

        $response = $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'pet_treat']);

        $response->assertOk();
        $response->assertJsonPath('leveled_up', true);
        $response->assertJsonPath('pet.level', 2);
        $response->assertJsonPath('pet.experience', 5); // 55 - 50
    }

    public function test_alimentar_puede_subir_varios_niveles_de_una_sola_vez(): void
    {
        [$character, $token] = $this->characterWithToken();
        $pet = $this->petFor($token);
        $pet->update(['exp' => 0, 'level' => 1]);

        // Comida de prueba con mucho EXP -reusa un item real ya existente
        // del catálogo (energy_tonic), nunca crea uno nuevo solo para esto.
        $bigFoodItem = Item::where('key', 'energy_tonic')->firstOrFail();
        PetFoodItem::updateOrCreate(['item_id' => $bigFoodItem->id], ['exp_value' => 200]);
        $this->giveInventory($character, 'energy_tonic', 1);

        $response = $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'energy_tonic']);

        // level 1->2 (50), 2->3 (100) = 150 consumidos de 200, quedan 50,
        // no alcanza para 3->4 (150) -ver PetFeedingService::EXP_PER_LEVEL-.
        $response->assertOk();
        $response->assertJsonPath('leveled_up', true);
        $response->assertJsonPath('pet.level', 3);
        $response->assertJsonPath('pet.experience', 50);
    }

    // --- POST /pet/feed: casos inválidos ---

    public function test_alimentar_con_food_key_inexistente_en_el_catalogo_es_rechazado(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        $response = $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'esto_no_existe']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('food_key');
    }

    public function test_alimentar_con_item_que_no_es_comida_devuelve_422(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->petFor($token);
        // 'wood' es un item real del catálogo pero nunca se registró como
        // comida de mascota (ver PetFoodItemSeeder).
        $this->giveInventory($character, 'wood', 5);

        $response = $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'wood']);

        $response->assertStatus(422);
    }

    public function test_alimentar_sin_tener_el_item_en_inventario_devuelve_422(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        // Nunca se le dio pet_treat a este personaje.

        $response = $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'pet_treat']);

        $response->assertStatus(422);
    }

    public function test_alimentar_sin_food_key_es_rechazado(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        $response = $this->withToken($token)->postJson('/api/v1/pet/feed', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('food_key');
    }

    // --- Consumo correcto / protección contra doble consumo ---
    // No hay forma de disparar dos requests HTTP realmente simultáneos
    // dentro de un mismo test de PHPUnit (single-threaded) -esto valida
    // la garantía observable equivalente: con exactamente 1 unidad en
    // inventario, un segundo feed() nunca puede "colarse" y duplicar el
    // gasto. El locking real (Pet::lockForUpdate() + el lockForUpdate()
    // que ya usa InventoryGrantService::consume()) es lo que garantiza
    // esto también bajo concurrencia real -ver PetFeedingService-.
    public function test_no_se_puede_alimentar_dos_veces_con_una_sola_unidad_de_comida(): void
    {
        [$character, $token] = $this->characterWithToken();
        $pet = $this->petFor($token);
        $this->giveInventory($character, 'pet_treat', 1);

        $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'pet_treat'])
            ->assertOk();

        $this->assertEquals(15, $pet->fresh()->exp);

        // Segundo intento: ya no queda comida -debe fallar limpio, nunca
        // volver a otorgar EXP.
        $second = $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'pet_treat']);
        $second->assertStatus(422);

        $this->assertEquals(15, $pet->fresh()->exp, 'El segundo intento fallido no debe haber otorgado EXP de nuevo.');
        $this->assertDatabaseMissing('inventory_items', [
            'character_id' => $character->id,
            'item_id' => Item::where('key', 'pet_treat')->value('id'),
        ]);
    }

    public function test_alimentar_registra_actividad(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->petFor($token);
        $this->giveInventory($character, 'pet_treat', 1);

        $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'pet_treat'])->assertOk();

        $this->assertDatabaseHas('activity_events', [
            'character_id' => $character->id,
            'type' => 'pet_fed',
        ]);
    }

    public function test_level_up_registra_actividad_propia(): void
    {
        [$character, $token] = $this->characterWithToken();
        $pet = $this->petFor($token);
        $pet->update(['exp' => 40, 'level' => 1]);
        $this->giveInventory($character, 'pet_treat', 1);

        $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'pet_treat'])->assertOk();

        $this->assertDatabaseHas('activity_events', [
            'character_id' => $character->id,
            'type' => 'pet_leveled_up',
        ]);
    }
}
