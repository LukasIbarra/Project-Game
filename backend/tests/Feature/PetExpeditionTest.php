<?php

namespace Tests\Feature;

use App\Enums\PetExpeditionStatus;
use App\Enums\PetStatus;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Pet;
use App\Models\PetDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

// Carbon::setTestNow() (sección 22 de la fase) en vez de esperar horas
// reales -"pasar tiempo" en un test es solo mover el reloj del servidor,
// la resolución de la expedición ya es puro cálculo de timestamps
// (PetExpeditionService), no hay ningún job real que esperar.
class PetExpeditionTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    private function petFor(string $token): Pet
    {
        $petId = $this->withToken($token)->getJson('/api/v1/pet')->json('id');

        return Pet::findOrFail($petId);
    }

    // rollEvents() decide primero CUÁNTOS eventos aplicar (puede ser 0
    // aunque el pool tenga contenido) y recién después cuáles -así que
    // ni con un pool de un solo evento está garantizado que se dispare en
    // un único intento-. Reintenta con un personaje nuevo cada vez (cada
    // uno solo puede tener una expedición) hasta observarlo -en la
    // práctica, unos pocos intentos alcanzan-.
    private function startUntilEventFires(string $destinationKey): array
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->app['auth']->forgetGuards();

            [, $token] = $this->characterWithToken();
            $this->petFor($token);

            $response = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
                'destination_key' => $destinationKey,
            ]);
            $response->assertStatus(201);

            if (! empty($response->json('events'))) {
                return ['token' => $token, 'response' => $response];
            }
        }

        $this->fail("El evento configurado para {$destinationKey} nunca se disparó en 30 intentos.");
    }

    public function test_puede_iniciar_una_expedicion_y_la_duracion_viene_del_backend(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        Carbon::setTestNow(Carbon::parse('2026-01-01 13:00:00'));

        $response = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'forest',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('started_at', '2026-01-01T13:00:00+00:00');
        // forest = 120 min, sin eventos de retraso garantizado -pero el
        // backend nunca deja que sea MENOS que la duración base-.
        $finishesAt = Carbon::parse($response->json('finishes_at'));
        $this->assertTrue($finishesAt->gte(Carbon::parse('2026-01-01 15:00:00')));
    }

    public function test_no_puede_iniciar_dos_expediciones_simultaneas(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        $this->withToken($token)->postJson('/api/v1/pet/expedition/start', ['destination_key' => 'forest'])
            ->assertStatus(201);

        $this->withToken($token)->postJson('/api/v1/pet/expedition/start', ['destination_key' => 'mountains'])
            ->assertStatus(409);
    }

    public function test_destino_invalido_falla(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'no_existe',
        ])->assertStatus(422);
    }

    public function test_expedicion_activa_no_puede_reclamarse(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $this->withToken($token)->postJson('/api/v1/pet/expedition/start', ['destination_key' => 'forest']);

        $this->withToken($token)->postJson('/api/v1/pet/expedition/claim')->assertStatus(409);
    }

    public function test_expedicion_completada_puede_reclamarse_y_entrega_loot_al_inventario(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:00:00'));
        $this->withToken($token)->postJson('/api/v1/pet/expedition/start', ['destination_key' => 'forest'])
            ->assertStatus(201);

        // Bosque = 120 min -avanzamos 3h para no depender de si hubo
        // retraso aleatorio.
        Carbon::setTestNow(Carbon::parse('2026-01-01 16:00:00'));

        $response = $this->withToken($token)->postJson('/api/v1/pet/expedition/claim');

        $response->assertOk();
        $response->assertJsonPath('expedition.status', 'claimed');
        $this->assertNotEmpty($response->json('loot'));

        $this->assertGreaterThan(0, InventoryItem::where('character_id', $character->id)->count());

        $pet = Pet::where('character_id', $character->id)->first();
        $this->assertSame(PetStatus::Idle, $pet->status);
    }

    public function test_reclamar_dos_veces_no_entrega_el_loot_dos_veces(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:00:00'));
        $this->withToken($token)->postJson('/api/v1/pet/expedition/start', ['destination_key' => 'forest']);
        Carbon::setTestNow(Carbon::parse('2026-01-01 16:00:00'));

        $this->withToken($token)->postJson('/api/v1/pet/expedition/claim')->assertOk();
        $totalAfterFirstClaim = InventoryItem::where('character_id', $character->id)->sum('quantity');

        $this->withToken($token)->postJson('/api/v1/pet/expedition/claim')->assertStatus(404);
        $totalAfterSecondAttempt = InventoryItem::where('character_id', $character->id)->sum('quantity');

        $this->assertEquals($totalAfterFirstClaim, $totalAfterSecondAttempt);
    }

    public function test_historial_solo_muestra_expediciones_reclamadas(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:00:00'));
        $this->withToken($token)->postJson('/api/v1/pet/expedition/start', ['destination_key' => 'forest']);
        Carbon::setTestNow(Carbon::parse('2026-01-01 16:00:00'));

        // Completada pero todavía sin reclamar: no debe aparecer en el
        // historial todavía -ya se muestra como acción pendiente en GET
        // /pet, listarla dos veces mostraría loot como "recibido" antes
        // de que llegue al inventario.
        $this->withToken($token)->getJson('/api/v1/pet/events')->assertOk()->assertJsonCount(0);

        $this->withToken($token)->postJson('/api/v1/pet/expedition/claim')->assertOk();

        $response = $this->withToken($token)->getJson('/api/v1/pet/events');
        $response->assertOk()->assertJsonCount(1);
        $response->assertJsonPath('0.status', 'claimed');
    }

    public function test_evento_de_ataque_reduce_hp_y_se_aplica_al_completarse(): void
    {
        PetDestination::create([
            'key' => 'test_attack_dest',
            'name' => 'Destino de prueba (ataque)',
            'difficulty' => 1,
            'duration_minutes' => 60,
            'loot_min_tier' => 'basic',
            'loot_max_tier' => 'basic',
            'loot_pool_json' => ['resource_wood'],
        ]);
        Config::set('pet_events.test_attack_dest', [
            ['key' => 'attacked', 'label' => 'Ataque de prueba.', 'type' => 'negative', 'health_delta' => -10],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));
        ['token' => $token] = $this->startUntilEventFires('test_attack_dest');
        $pet = $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        $this->withToken($token)->getJson('/api/v1/pet')->assertOk();

        $this->assertEquals(90, $pet->fresh()->health);
    }

    public function test_evento_de_atrapamiento_extiende_finishes_at(): void
    {
        PetDestination::create([
            'key' => 'test_trap_dest',
            'name' => 'Destino de prueba (trampa)',
            'difficulty' => 1,
            'duration_minutes' => 60,
            'loot_min_tier' => 'basic',
            'loot_max_tier' => 'basic',
            'loot_pool_json' => ['resource_wood'],
        ]);
        Config::set('pet_events.test_trap_dest', [
            ['key' => 'trapped', 'label' => 'Trampa de prueba.', 'type' => 'delay', 'delay_minutes' => 30],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));
        ['response' => $response] = $this->startUntilEventFires('test_trap_dest');

        // 60 min base + 30 min de retraso = 90 min -> 11:30.
        $response->assertJsonPath('finishes_at', '2026-01-01T11:30:00+00:00');
    }

    public function test_evento_positivo_entrega_loot_adicional(): void
    {
        PetDestination::create([
            'key' => 'test_bonus_dest',
            'name' => 'Destino de prueba (bonus)',
            'difficulty' => 1,
            'duration_minutes' => 60,
            'loot_min_tier' => 'basic',
            'loot_max_tier' => 'basic',
            'loot_pool_json' => ['resource_wood'],
        ]);
        Config::set('pet_events.test_bonus_dest', [
            ['key' => 'lucky_find', 'label' => 'Hallazgo de prueba.', 'type' => 'positive', 'loot_bonus_quantity' => 5],
        ]);

        ['response' => $response] = $this->startUntilEventFires('test_bonus_dest');

        $loot = $response->json('loot');
        $this->assertCount(1, $loot); // el pool solo tiene 1 item posible
        // Base es random(3,10); con +5 de bonus el mínimo posible es 8.
        $this->assertGreaterThanOrEqual(8, $loot[0]['quantity']);
    }

    public function test_no_puede_reclamar_ni_iniciar_expedicion_de_otro_usuario(): void
    {
        [, $tokenA] = $this->characterWithToken();
        [, $tokenB] = $this->characterWithToken();
        $this->petFor($tokenA);
        $this->petFor($tokenB);

        $this->withToken($tokenA)->postJson('/api/v1/pet/expedition/start', ['destination_key' => 'forest'])
            ->assertStatus(201);

        $this->app['auth']->forgetGuards();

        // B nunca pudo mandar un pet_id/expedition_id ajeno -no existe ese
        // parámetro en la API-, así que su propio start/claim solo puede
        // afectar su propia mascota, nunca la de A.
        $this->withToken($tokenB)->postJson('/api/v1/pet/expedition/claim')->assertStatus(404);
        $this->withToken($tokenB)->postJson('/api/v1/pet/expedition/start', ['destination_key' => 'mountains'])
            ->assertStatus(201);
    }
}
