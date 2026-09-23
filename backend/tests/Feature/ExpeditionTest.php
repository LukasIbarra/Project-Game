<?php

namespace Tests\Feature;

use App\Enums\PetExpeditionStatus;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

// F21: reemplaza PetExpeditionTest -el contrato cambió por completo
// (checkpoints en vez de events/narrative_log inline, rutas nuevas, loot
// resuelto al completar en vez de precalculado en start()). Mismo patrón
// de testing que el resto del proyecto: Carbon::setTestNow() en vez de
// esperar tiempo real, DatabaseTransactions sobre la DB de desarrollo
// real (nunca RefreshDatabase).
class ExpeditionTest extends TestCase
{
    use DatabaseTransactions;

    // F22: este archivo prueba el flujo F21 (planificación/catch-up/claim)
    // sobre destinos reales -fuerza 0% de checkpoints kind=event para que
    // sea determinista (sin esto, un checkpoint podía volverse un enemy
    // awaiting_decision al azar y bloquear la expedición antes de
    // completar, haciendo estos tests flaky). El comportamiento con
    // eventos reales vive en ExpeditionEventTest.php.
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('expeditions.checkpoint_event_chance_pct', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function characterWithToken(int $level = 1): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id, 'level' => $level]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    private function petFor(string $token): Pet
    {
        $petId = $this->withToken($token)->getJson('/api/v1/pet')->json('id');

        return Pet::findOrFail($petId);
    }

    public function test_puede_iniciar_una_expedicion_y_los_checkpoints_quedan_planificados_sin_payload(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:00:00'));

        $response = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'forest',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('started_at', '2026-01-01T13:00:00+00:00');
        // Bosque Encantado = 1800s = 30 min, F21 ya no ajusta ends_at por
        // eventos (ese mecanismo se retiró, ver ExpeditionService).
        $response->assertJsonPath('finishes_at', '2026-01-01T13:30:00+00:00');

        $checkpoints = $response->json('checkpoints');
        $this->assertNotEmpty($checkpoints);
        // El primero SIEMPRE es narrative (apertura de la expedición); a
        // partir de ahí puede haber kind=event (F22) según
        // config('expeditions.checkpoint_event_chance_pct') -lo que nunca
        // cambia es que NINGUNO trae payload todavía (nada se pre-rollea).
        $this->assertSame('narrative', $checkpoints[0]['kind']);
        foreach ($checkpoints as $checkpoint) {
            $this->assertSame('pending', $checkpoint['status']);
            $this->assertContains($checkpoint['kind'], ['narrative', 'event']);
            $this->assertNull($checkpoint['payload']);
        }
    }

    public function test_no_puede_iniciar_dos_expediciones_simultaneas(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', ['expedition_key' => 'forest'])
            ->assertStatus(201);

        $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', ['expedition_key' => 'windy_hills'])
            ->assertStatus(409);
    }

    public function test_expedicion_invalida_falla(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'no_existe',
        ])->assertStatus(422);
    }

    public function test_no_puede_iniciar_si_la_mascota_no_cumple_el_nivel_minimo(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token); // nivel 1 por defecto (PetProvisioningService)

        // mountains pide nivel mínimo 3 (ver ExpeditionDefinitionSeeder).
        $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'mountains',
        ])->assertStatus(409);
    }

    public function test_expedicion_activa_no_puede_reclamarse(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $start = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', ['expedition_key' => 'forest']);

        $this->withToken($token)->postJson("/api/v1/pet/expeditions/{$start->json('id')}/claim")
            ->assertStatus(409);
    }

    // F21: a diferencia de F7 (todo resuelto en start()), acá el loot NO
    // existe hasta que resolveDueCheckpoints() marca la expedición
    // completed -esto ocurre server-authoritative la primera vez que algo
    // pide el estado después de ends_at (GET /pet en este caso), nunca por
    // un job programado.
    public function test_get_pet_resuelve_la_expedicion_automaticamente_al_pasar_ends_at(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:00:00'));
        $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', ['expedition_key' => 'forest'])
            ->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:35:00'));
        $response = $this->withToken($token)->getJson('/api/v1/pet');

        $response->assertOk();
        $response->assertJsonPath('expedition.status', 'completed');
        $checkpoints = $response->json('expedition.checkpoints');
        foreach ($checkpoints as $checkpoint) {
            $this->assertSame('resolved', $checkpoint['status']);
            $this->assertNotNull($checkpoint['payload']);
        }
    }

    public function test_expedicion_completada_puede_reclamarse_y_entrega_loot_al_inventario(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:00:00'));
        $start = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', ['expedition_key' => 'forest']);
        $expeditionId = $start->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:35:00'));

        $response = $this->withToken($token)->postJson("/api/v1/pet/expeditions/{$expeditionId}/claim");

        $response->assertOk();
        $response->assertJsonPath('expedition.status', 'claimed');
        $this->assertNotEmpty($response->json('loot'));

        $this->assertGreaterThan(0, InventoryItem::where('character_id', $character->id)->count());

        $pet = Pet::where('character_id', $character->id)->first();
        $this->assertSame('idle', $pet->status->value);
    }

    // Fix real del bug de doble-claim (docs/PETS_EXPEDITIONS_SYSTEM.md
    // §3.4): dos llamadas a claim() sobre la MISMA expedición ya completada
    // -mismo criterio de "test de concurrencia explícito" que pide el
    // diseño (§20): lo que se verifica es la garantía de
    // lockForUpdate()+re-chequeo de status DENTRO de la transacción, no la
    // paralelización real de PHP (este proyecto nunca testea con threads
    // reales, ver el resto de la suite).
    public function test_reclamar_dos_veces_no_entrega_el_loot_dos_veces(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:00:00'));
        $start = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', ['expedition_key' => 'forest']);
        $expeditionId = $start->json('id');
        Carbon::setTestNow(Carbon::parse('2026-01-01 13:35:00'));

        $first = $this->withToken($token)->postJson("/api/v1/pet/expeditions/{$expeditionId}/claim");
        $first->assertOk();
        $lootAfterFirst = InventoryItem::where('character_id', $character->id)->sum('quantity');

        $second = $this->withToken($token)->postJson("/api/v1/pet/expeditions/{$expeditionId}/claim");
        $second->assertOk();
        $lootAfterSecond = InventoryItem::where('character_id', $character->id)->sum('quantity');

        $this->assertEquals($lootAfterFirst, $lootAfterSecond, 'El segundo claim() no debe volver a conceder loot.');
        // El segundo claim también debe devolver el MISMO loot ya
        // registrado (idempotente), no una lista vacía ni un error.
        $this->assertEquals($first->json('loot'), $second->json('loot'));
    }

    public function test_no_puede_reclamar_ni_iniciar_expedicion_de_otro_usuario(): void
    {
        [, $tokenA] = $this->characterWithToken();
        [, $tokenB] = $this->characterWithToken();
        $this->petFor($tokenA);
        $this->petFor($tokenB);

        $start = $this->withToken($tokenA)->postJson('/api/v1/pet/expeditions/start', ['expedition_key' => 'forest']);
        $start->assertStatus(201);
        $expeditionId = $start->json('id');

        $this->app['auth']->forgetGuards();

        // B nunca puede reclamar la expedición de A, aunque mande su id
        // real -ownership siempre server-side vía $pet->expeditions()-.
        $this->withToken($tokenB)->postJson("/api/v1/pet/expeditions/{$expeditionId}/claim")
            ->assertStatus(404);

        $this->withToken($tokenB)->postJson('/api/v1/pet/expeditions/start', ['expedition_key' => 'windy_hills'])
            ->assertStatus(201);
    }

    public function test_definiciones_de_expedicion_incluyen_reward_preview_con_porcentajes(): void
    {
        [, $token] = $this->characterWithToken();

        $response = $this->withToken($token)->getJson('/api/v1/pet/expeditions/definitions');

        $response->assertOk();
        $forest = collect($response->json())->firstWhere('key', 'forest');
        $this->assertNotNull($forest);
        $this->assertSame(1800, $forest['duration_seconds']);
        $this->assertSame(1, $forest['min_pet_level']);
        $this->assertNotEmpty($forest['reward_preview']);

        $totalPercent = array_sum(array_column($forest['reward_preview'], 'percent'));
        $this->assertEqualsWithDelta(100.0, $totalPercent, 0.5);
    }

    public function test_historial_solo_muestra_expediciones_reclamadas(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:00:00'));
        $start = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', ['expedition_key' => 'forest']);
        Carbon::setTestNow(Carbon::parse('2026-01-01 13:35:00'));

        $this->withToken($token)->getJson('/api/v1/pet/expeditions/history')->assertOk()->assertJsonCount(0);

        $this->withToken($token)->postJson("/api/v1/pet/expeditions/{$start->json('id')}/claim")->assertOk();

        $response = $this->withToken($token)->getJson('/api/v1/pet/expeditions/history');
        $response->assertOk()->assertJsonCount(1);
        $response->assertJsonPath('0.status', 'claimed');
    }

    public function test_catalogo_de_especies_no_expone_modifiers_json(): void
    {
        [, $token] = $this->characterWithToken();

        $response = $this->withToken($token)->getJson('/api/v1/pet/species');

        $response->assertOk();
        $this->assertNotEmpty($response->json());
        $starter = collect($response->json())->firstWhere('key', 'starter');
        $this->assertNotNull($starter);
        $this->assertArrayNotHasKey('modifiers_json', $starter);
        $this->assertArrayHasKey('rarity', $starter);
    }
}
