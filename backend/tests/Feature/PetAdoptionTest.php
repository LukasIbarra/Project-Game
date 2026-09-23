<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Pet;
use App\Models\PetExpedition;
use App\Models\PetSpecies;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

// F23 (docs/PETS_EXPEDITIONS_SYSTEM.md, adopción/colección): catálogo de
// starter, adopción gratis, compra de especies adicionales, colección,
// cambio de mascota activa, migración legacy de "Compañero". Mismo patrón
// de testing del resto del proyecto: Carbon::setTestNow() en vez de
// esperar tiempo real, DatabaseTransactions sobre la DB de desarrollo real.
class PetAdoptionTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function characterWithToken(int $coins = 0): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id, 'coins' => $coins]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    // ===================== Catálogo =====================

    // Test 2: usuario sin mascota obtiene catálogo starter.
    public function test_catalogo_de_especies_expone_las_5_opciones_de_starter(): void
    {
        [, $token] = $this->characterWithToken();

        $response = $this->withToken($token)->getJson('/api/v1/pet/species');

        $response->assertOk();
        $starterKeys = collect($response->json())->where('is_starter_option', true)->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['lumio', 'rakhun', 'qappha', 'kitsu', 'sapphoro'], $starterKeys);
    }

    // ===================== Adopción starter =====================

    // Test 3 + 4: adopción starter válida, gratis.
    public function test_adopcion_starter_es_gratis_y_queda_activa(): void
    {
        [$character, $token] = $this->characterWithToken(coins: 0);

        $response = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu']);

        $response->assertStatus(201);
        $response->assertJsonPath('species', 'kitsu');
        $this->assertSame(0, $character->fresh()->coins, 'La primera mascota nunca debe cobrar monedas.');
        $this->assertSame($response->json('id'), $character->fresh()->active_pet_id);
    }

    // Test 5: doble request no crea dos starters (mismo criterio de
    // idempotencia/concurrencia que el resto del proyecto -lockForUpdate()
    // sobre Character serializa las dos, la segunda ve la Pet ya creada).
    public function test_doble_adopcion_starter_no_crea_dos_mascotas(): void
    {
        [$character, $token] = $this->characterWithToken();

        $first = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu']);
        $second = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'lumio']);

        $first->assertStatus(201);
        $second->assertStatus(409);
        $this->assertSame(1, $character->fresh()->pets()->count());
    }

    // Test 6: especie inválida.
    public function test_adoptar_especie_inexistente_falla(): void
    {
        [, $token] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'no_existe'])
            ->assertStatus(422);
    }

    // Test 7: especie no disponible como starter (existe y está activa,
    // pero is_starter_option=false -p.ej. una de las 19 especies de F20-).
    public function test_adoptar_especie_no_disponible_como_starter_falla(): void
    {
        [, $token] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'bat_pup'])
            ->assertStatus(409);
    }

    // Test 8: usuario con mascota no obtiene otro starter gratis.
    public function test_usuario_con_mascota_no_puede_adoptar_otro_starter(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->assertStatus(201);

        $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'lumio'])
            ->assertStatus(409);

        $this->assertSame(1, $character->fresh()->pets()->count());
    }

    // ===================== Compra de especies adicionales =====================

    // Test 9: usuario puede poseer varias especies.
    public function test_usuario_puede_poseer_varias_especies(): void
    {
        [$character, $token] = $this->characterWithToken(coins: 500);
        $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->assertStatus(201);

        $this->withToken($token)->postJson('/api/v1/pet/species/lumio/purchase')->assertStatus(201);
        $this->withToken($token)->postJson('/api/v1/pet/species/qappha/purchase')->assertStatus(201);

        $this->assertSame(3, $character->fresh()->pets()->count());
    }

    // Test 10: no puede poseer duplicado de especie.
    public function test_no_puede_comprar_una_especie_que_ya_posee(): void
    {
        [$character, $token] = $this->characterWithToken(coins: 500);
        $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->assertStatus(201);

        $this->withToken($token)->postJson('/api/v1/pet/species/kitsu/purchase')
            ->assertStatus(422);

        $this->assertSame(1, $character->fresh()->pets()->count());
    }

    // Test 11: compra descuenta monedas exactamente una vez.
    public function test_compra_descuenta_monedas_exactamente_una_vez(): void
    {
        [$character, $token] = $this->characterWithToken(coins: 500);
        $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->assertStatus(201);

        $price = PetSpecies::where('key', 'lumio')->value('adoption_price');
        $this->withToken($token)->postJson('/api/v1/pet/species/lumio/purchase')->assertStatus(201);

        $this->assertSame(500 - $price, $character->fresh()->coins);
    }

    // Test 12: fondos insuficientes.
    public function test_compra_con_fondos_insuficientes_falla_sin_cambiar_nada(): void
    {
        [$character, $token] = $this->characterWithToken(coins: 10);
        $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->assertStatus(201);

        $this->withToken($token)->postJson('/api/v1/pet/species/lumio/purchase')
            ->assertStatus(422);

        $this->assertSame(10, $character->fresh()->coins);
        $this->assertSame(1, $character->fresh()->pets()->count());
    }

    // Test 13: precio viene del servidor (el endpoint ni siquiera acepta
    // un precio en el body -aunque el cliente mande uno, se ignora
    // silenciosamente, mismo criterio que EconomyService::buy()).
    public function test_precio_de_compra_siempre_sale_del_servidor(): void
    {
        [$character, $token] = $this->characterWithToken(coins: 500);
        $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->assertStatus(201);

        $realPrice = PetSpecies::where('key', 'lumio')->value('adoption_price');
        $this->withToken($token)->postJson('/api/v1/pet/species/lumio/purchase', ['price' => 1])
            ->assertStatus(201);

        $this->assertSame(500 - $realPrice, $character->fresh()->coins);
    }

    // ===================== Colección / mascota activa =====================

    // Test 14 + 15: cambio de mascota activa, solo una activa a la vez.
    public function test_cambiar_mascota_activa(): void
    {
        [$character, $token] = $this->characterWithToken(coins: 500);
        $kitsuId = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->json('id');
        $lumioId = $this->withToken($token)->postJson('/api/v1/pet/species/lumio/purchase')->json('id');

        $this->assertSame($kitsuId, $character->fresh()->active_pet_id);

        $response = $this->withToken($token)->postJson('/api/v1/pet/active', ['pet_id' => $lumioId]);

        $response->assertOk();
        $response->assertJsonPath('species', 'lumio');
        $this->assertSame($lumioId, $character->fresh()->active_pet_id, 'Solo puede haber una mascota activa a la vez.');
    }

    // Test 16: ownership -no puede activar una mascota de otro usuario.
    public function test_no_puede_activar_una_mascota_de_otro_usuario(): void
    {
        [, $tokenA] = $this->characterWithToken();
        [, $tokenB] = $this->characterWithToken();
        $petBId = $this->withToken($tokenB)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->json('id');

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenA)->postJson('/api/v1/pet/adopt', ['species_key' => 'lumio'])->assertStatus(201);

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenA)->postJson('/api/v1/pet/active', ['pet_id' => $petBId])
            ->assertStatus(404);
    }

    // GET /pet/mine: identifica activa y lista la colección completa.
    public function test_mine_lista_la_coleccion_e_identifica_la_activa(): void
    {
        [$character, $token] = $this->characterWithToken(coins: 500);
        $kitsuId = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->json('id');
        $this->withToken($token)->postJson('/api/v1/pet/species/lumio/purchase')->assertStatus(201);

        // forgetGuards(): el entorno de test reutiliza el mismo objeto
        // User (con su relación character() ya cacheada) entre llamadas
        // HTTP sucesivas dentro de un mismo test -sin esto, la próxima
        // request vería el Character desactualizado de ANTES de adoptar,
        // aunque la DB ya esté correcta (confirmado: una query cruda ve el
        // valor correcto, la relación Eloquent cacheada no). Nunca ocurre
        // en producción -ahí cada request es un proceso nuevo-, es
        // puramente un artefacto de reutilizar el mismo proceso PHP entre
        // llamadas de test.
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($token)->getJson('/api/v1/pet/mine');

        $response->assertOk();
        $response->assertJsonPath('active_pet_id', $kitsuId);
        $this->assertCount(2, $response->json('pets'));
    }

    // ===================== Expediciones =====================

    // Test 17: expedición conserva pet_id original.
    // Test 18: cambiar la activa durante una expedición no la reasigna ni
    // rompe su estado -la expedición sigue perteneciendo a la Pet original.
    public function test_cambiar_mascota_activa_durante_una_expedicion_no_la_reasigna(): void
    {
        // Este test prueba pet_id/active_pet_id, no el sistema de eventos
        // (F22) -fuerza 100% narrative para que sea determinista y no
        // dependa de si un checkpoint real termina en awaiting_decision
        // (que bloquearía el claim() de más abajo con 409, sin relación
        // con lo que este test verifica). Ver el mismo criterio en
        // ExpeditionTest.php::setUp().
        Config::set('expeditions.checkpoint_event_chance_pct', 0);
        Config::set('expeditions.min_event_checkpoints', 0);

        [$character, $token] = $this->characterWithToken(coins: 500);
        $kitsuId = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->json('id');
        $lumioId = $this->withToken($token)->postJson('/api/v1/pet/species/lumio/purchase')->json('id');

        // Ver nota en test_mine_lista_la_coleccion_e_identifica_la_activa
        // -artefacto del entorno de test, nunca ocurre en producción-.
        $this->app['auth']->forgetGuards();

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', ['expedition_key' => 'forest'])->json('id');
        $expeditionBefore = PetExpedition::findOrFail($expeditionId);
        $this->assertSame($kitsuId, $expeditionBefore->pet_id);

        // Cambia la activa a Lumio MIENTRAS Kitsu sigue explorando.
        $this->withToken($token)->postJson('/api/v1/pet/active', ['pet_id' => $lumioId])->assertOk();
        $this->assertSame($lumioId, $character->fresh()->active_pet_id);

        // La expedición sigue perteneciendo a Kitsu -pet_id nunca cambia-.
        $this->assertSame($kitsuId, PetExpedition::findOrFail($expeditionId)->pet_id);

        // Vuelve a activar Kitsu y puede seguir reclamando su expedición
        // con normalidad -nada se rompió por el cambio intermedio-.
        $this->withToken($token)->postJson('/api/v1/pet/active', ['pet_id' => $kitsuId])->assertOk();
        $this->app['auth']->forgetGuards();

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:35:00'));
        $claim = $this->withToken($token)->postJson("/api/v1/pet/expeditions/{$expeditionId}/claim");
        $claim->assertOk();
        $claim->assertJsonPath('expedition.status', 'claimed');
    }

    // ===================== Alimentación =====================

    // Test 19: alimentación afecta la mascota correcta (la ACTIVA, nunca
    // otra de la colección).
    public function test_alimentacion_afecta_la_mascota_activa_correcta(): void
    {
        [$character, $token] = $this->characterWithToken(coins: 500);
        $kitsuId = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->json('id');
        $lumioId = $this->withToken($token)->postJson('/api/v1/pet/species/lumio/purchase')->json('id');
        $this->app['auth']->forgetGuards();

        $item = \App\Models\Item::where('key', 'wild_herb')->firstOrFail();
        InventoryItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 5]);

        $this->withToken($token)->postJson('/api/v1/pet/feed', ['food_key' => 'wild_herb'])->assertOk();

        $this->assertGreaterThan(0, Pet::findOrFail($kitsuId)->exp, 'Kitsu (activa) debe haber ganado EXP.');
        $this->assertSame(0, Pet::findOrFail($lumioId)->exp, 'Lumio (no activa) no debe verse afectada.');
    }

    // ===================== Presentación =====================

    // Test 20: PetPresenter presenta especie/asset correcto.
    public function test_get_pet_expone_el_sprite_de_la_especie_activa(): void
    {
        [, $token] = $this->characterWithToken();
        $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->assertStatus(201);
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($token)->getJson('/api/v1/pet');

        $response->assertOk();
        $response->assertJsonPath('sprite.file', '/assets/pets/Kitsu.png');
        $response->assertJsonPath('sprite.frame_width', 443);
        $response->assertJsonPath('sprite.animations.idle', [0, 1]);
        $this->assertCount(8, $response->json('sprite.frames'), 'Los 8 frames del spritesheet deben quedar documentados.');
    }

    // ===================== Migración legacy =====================

    // Test 21: usuarios legacy con "Compañero" entran al nuevo onboarding.
    public function test_usuario_legacy_con_companero_retirado_entra_a_seleccion_inicial(): void
    {
        [$character, $token] = $this->characterWithToken();
        $starter = PetSpecies::where('key', 'starter')->firstOrFail();

        // Simula el estado real post-migración (2026_09_23_000004): una
        // Pet legacy ya retirada, sin ninguna activa.
        Pet::create([
            'character_id' => $character->id,
            'species_id' => $starter->id,
            'name' => 'Compañero',
            'level' => 1, 'exp' => 0, 'health' => 100, 'max_health' => 100, 'energy' => 100, 'max_energy' => 100,
            'status' => 'idle',
            'retired_at' => now(),
        ]);

        $this->withToken($token)->getJson('/api/v1/pet')->assertStatus(404);

        $response = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'sapphoro']);
        $response->assertStatus(201);
        $this->assertSame('sapphoro', $character->fresh()->activePet->species->key);
    }

    // Test 22: el historial antiguo (expediciones de la Pet legacy) no
    // queda corrupto por el retiro -sigue existiendo, íntegro.
    public function test_expediciones_historicas_de_pet_legacy_retirada_siguen_intactas(): void
    {
        [$character] = $this->characterWithToken();
        $starter = PetSpecies::where('key', 'starter')->firstOrFail();

        $legacyPet = Pet::create([
            'character_id' => $character->id,
            'species_id' => $starter->id,
            'name' => 'Compañero',
            'level' => 3, 'exp' => 0, 'health' => 100, 'max_health' => 100, 'energy' => 100, 'max_energy' => 100,
            'status' => 'idle',
            'retired_at' => now(),
        ]);

        $definition = \App\Models\ExpeditionDefinition::where('key', 'forest')->firstOrFail();
        $historicalExpedition = PetExpedition::create([
            'pet_id' => $legacyPet->id,
            'expedition_definition_id' => $definition->id,
            'status' => 'claimed',
            'started_at' => now()->subDays(10),
            'ends_at' => now()->subDays(9),
            'resolved_at' => now()->subDays(9),
            'result_data_json' => ['expedition_loot' => [], 'event_loot' => []],
        ]);

        // La fila sigue existiendo, con su pet_id original intacto -nunca
        // se reasigna ni se borra por retirar la Pet-.
        $this->assertDatabaseHas('pet_expeditions', [
            'id' => $historicalExpedition->id,
            'pet_id' => $legacyPet->id,
        ]);
        $this->assertNotNull($legacyPet->fresh());
        $this->assertNotNull($legacyPet->fresh()->retired_at);
    }
}
