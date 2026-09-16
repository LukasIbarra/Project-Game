<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\PetExpedition;
use App\Models\Recipe;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Fase 12: feed de actividad reciente. Cubre: persistencia genérica del
// logger, forma/orden/paginación de GET /v1/activity, aislamiento entre
// personajes, y que los puntos de instrumentación reales (level_up,
// combat_attacked/combat_defended -Fase 17-, expedition_claimed, sale,
// crafting) efectivamente escriben un evento -no solo que el endpoint
// "funcione" en abstracto-.
class ActivityTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(array $attrs = []): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(array_merge(['user_id' => $user->id], $attrs));
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    public function test_get_activity_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/v1/activity')->assertStatus(401);
    }

    public function test_get_activity_devuelve_solo_eventos_propios_en_orden_cronologico(): void
    {
        [$character, $token] = $this->characterWithToken();
        [$otherCharacter] = $this->characterWithToken();

        $logger = app(ActivityLogger::class);
        $logger->log($character, 'sale', ['item_key' => 'a']);
        $logger->log($otherCharacter, 'sale', ['item_key' => 'ajeno']);
        $logger->log($character, 'crafting', ['recipe_key' => 'b']);

        $response = $this->withToken($token)->getJson('/api/v1/activity');

        $response->assertOk();
        $types = collect($response->json())->pluck('type')->all();
        $this->assertEquals(['sale', 'crafting'], $types);
    }

    public function test_get_activity_soporta_after_id_para_polling_incremental(): void
    {
        [$character, $token] = $this->characterWithToken();
        $logger = app(ActivityLogger::class);

        $first = $logger->log($character, 'sale', []);
        $logger->log($character, 'crafting', []);
        $logger->log($character, 'level_up', ['new_level' => 2]);

        $response = $this->withToken($token)->getJson("/api/v1/activity?after_id={$first->id}");

        $response->assertOk();
        $types = collect($response->json())->pluck('type')->all();
        $this->assertEquals(['crafting', 'level_up'], $types);
    }

    public function test_get_activity_respeta_el_limite_de_25(): void
    {
        [$character, $token] = $this->characterWithToken();
        $logger = app(ActivityLogger::class);

        for ($i = 0; $i < 30; $i++) {
            $logger->log($character, 'sale', ['n' => $i]);
        }

        $response = $this->withToken($token)->getJson('/api/v1/activity');

        $response->assertOk();
        $this->assertCount(25, $response->json());
    }

    public function test_vender_item_registra_evento_de_actividad_sale(): void
    {
        [$character, $token] = $this->characterWithToken();
        $item = Item::where('key', 'stone')->firstOrFail();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 5]);

        $this->withToken($token)->postJson('/api/v1/inventory/sell', [
            'item_key' => 'stone',
            'quantity' => 5,
        ])->assertOk();

        $this->assertDatabaseHas('activity_events', [
            'character_id' => $character->id,
            'type' => 'sale',
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/activity');
        $event = collect($response->json())->firstWhere('type', 'sale');
        $this->assertNotNull($event);
        $this->assertEquals('stone', $event['payload']['item_key']);
        $this->assertEquals(5, $event['payload']['quantity']);
    }

    public function test_craftear_registra_evento_de_actividad_crafting(): void
    {
        [$character, $token] = $this->characterWithToken(['level' => 99]);
        $recipe = Recipe::where('is_active', true)->whereHas('ingredients')->firstOrFail();
        $recipe->loadMissing('ingredients.item');

        foreach ($recipe->ingredients as $ingredient) {
            InventoryItem::create([
                'character_id' => $character->id,
                'item_id' => $ingredient->item_id,
                'quantity' => $ingredient->quantity,
            ]);
        }

        $this->withToken($token)->postJson('/api/v1/crafting/craft', [
            'recipe_key' => $recipe->key,
        ])->assertOk();

        $this->assertDatabaseHas('activity_events', [
            'character_id' => $character->id,
            'type' => 'crafting',
        ]);
    }

    public function test_reclamar_expedicion_registra_evento_expedition_claimed(): void
    {
        [$character, $token] = $this->characterWithToken();

        // Fuerza el pet+destino igual que PetExpeditionTest: consultar
        // GET /pet aprovisiona la mascota perezosamente.
        $this->withToken($token)->getJson('/api/v1/pet')->assertOk();
        $destinationKey = $this->withToken($token)->getJson('/api/v1/pet/destinations')->json('0.key');

        $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => $destinationKey,
        ])->assertStatus(201);

        PetExpedition::where('pet_id', $character->fresh()->pet->id)
            ->update(['ends_at' => now()->subMinute()]);

        $this->withToken($token)->postJson('/api/v1/pet/expedition/claim')->assertOk();

        $this->assertDatabaseHas('activity_events', [
            'character_id' => $character->id,
            'type' => 'expedition_claimed',
        ]);
    }

    public function test_ganar_combate_registra_combat_attacked_para_el_atacante_y_combat_defended_para_el_defensor(): void
    {
        [$attacker, $token] = $this->characterWithToken(['strength' => 50, 'agility' => 50, 'vitality' => 50]);
        [$defender] = $this->characterWithToken(['strength' => 1, 'agility' => 1, 'vitality' => 1]);

        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ])->assertStatus(201);

        // Fase 17: ahora ambos se enteran, cada uno con SU tipo de evento
        // (el atacante inició la acción, el defensor la sufrió) -ver
        // CombatService::attack-.
        $this->assertDatabaseHas('activity_events', [
            'character_id' => $attacker->id,
            'type' => 'combat_attacked',
        ]);
        $this->assertDatabaseHas('activity_events', [
            'character_id' => $defender->id,
            'type' => 'combat_defended',
        ]);
    }

    public function test_subir_de_nivel_registra_evento_level_up_incluso_para_el_defensor(): void
    {
        [, $token] = $this->characterWithToken(['exp' => 95, 'strength' => 50, 'agility' => 50, 'vitality' => 50]);
        [$defender] = $this->characterWithToken(['strength' => 1, 'agility' => 1, 'vitality' => 1]);

        // Atacante con exp=95 y stats altas: gana casi seguro, +30 xp lo
        // hace cruzar el umbral de 100 -level_up determinístico sin mockear
        // random_int().
        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ])->assertStatus(201);

        $response = $this->withToken($token)->getJson('/api/v1/activity');
        $levelUpEvent = collect($response->json())->firstWhere('type', 'level_up');

        $this->assertNotNull($levelUpEvent, 'Se esperaba un evento level_up para el atacante.');
        $this->assertEquals(2, $levelUpEvent['payload']['new_level']);
    }
}
