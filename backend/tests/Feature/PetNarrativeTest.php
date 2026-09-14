<?php

namespace Tests\Feature;

use App\Enums\PetNarrativeCategory;
use App\Enums\PetNarrativeRarity;
use App\Models\Character;
use App\Models\Pet;
use App\Models\PetDestination;
use App\Models\PetExpedition;
use App\Models\PetNarrativeEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// F7.1: bitácora narrativa. Igual que PetExpeditionTest, se usa
// Carbon::setTestNow() para simular el paso del tiempo -acá además para
// simular "cerrar el navegador y volver" a mitad de una expedición larga.
class PetNarrativeTest extends TestCase
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

    private function makeDestination(string $key, int $durationMinutes = 60): PetDestination
    {
        return PetDestination::create([
            'key' => $key,
            'name' => "Destino de prueba ({$key})",
            'difficulty' => 1,
            'duration_minutes' => $durationMinutes,
            'loot_min_tier' => 'basic',
            'loot_max_tier' => 'basic',
            'loot_pool_json' => ['resource_wood'],
        ]);
    }

    public function test_la_expedicion_incluye_una_bitacora_narrativa(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));
        $response = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'forest',
        ]);
        $response->assertStatus(201);
        $this->assertArrayHasKey('narrative_log', $response->json());

        // Justo al iniciar (t=0) todavía no "ocurrió" nada -es correcto
        // que la bitácora revelada empiece vacía, ver
        // PetPresenter::visibleNarrativeLog-. Se comprueba el contenido
        // real avanzando el reloj hasta que el primer evento programado
        // ya debería haber pasado.
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:30:00'));
        $expedition = $this->withToken($token)->getJson('/api/v1/pet/expedition');
        $expedition->assertOk();
        $this->assertNotEmpty($expedition->json('narrative_log'));
        $this->assertArrayHasKey('text', $expedition->json('narrative_log.0'));
        $this->assertArrayHasKey('occurred_at', $expedition->json('narrative_log.0'));
    }

    public function test_usuario_no_puede_consultar_la_bitacora_de_otra_mascota(): void
    {
        [, $tokenA] = $this->characterWithToken();
        [, $tokenB] = $this->characterWithToken();
        $this->petFor($tokenA);
        $this->petFor($tokenB);

        $this->withToken($tokenA)->postJson('/api/v1/pet/expedition/start', ['destination_key' => 'forest'])
            ->assertStatus(201);

        $this->app['auth']->forgetGuards();

        // B nunca vio la expedición de A -no existe forma de pedir una
        // bitácora ajena, /pet/expedition siempre resuelve la propia-.
        $this->withToken($tokenB)->getJson('/api/v1/pet/expedition')->assertStatus(404);
    }

    public function test_los_eventos_narrativos_no_comparten_el_mismo_timestamp(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        $this->makeDestination('test_narrative_timestamps', 240);
        PetNarrativeEvent::create([
            'destination_id' => null,
            'category' => PetNarrativeCategory::Funny,
            'rarity' => PetNarrativeRarity::Common,
            'text' => 'Evento universal de prueba.',
        ]);

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'test_narrative_timestamps',
        ])->json('id');

        // El schedule COMPLETO vive en la DB desde start() (ver
        // PetExpeditionService::scheduleNarrativeLog); la API solo
        // revela lo que ya "ocurrió" según el reloj -acá se está
        // probando el scheduling en sí, no el filtro de revelado-.
        $log = PetExpedition::findOrFail($expeditionId)->result_data_json['narrative_log'];
        $timestamps = array_column($log, 'occurred_at');

        $this->assertGreaterThan(1, count($timestamps));
        $this->assertEquals(count($timestamps), count(array_unique($timestamps)));
        // Además deben venir en orden creciente -no solo distintos-.
        $sorted = $timestamps;
        sort($sorted);
        $this->assertEquals($sorted, $timestamps);
    }

    public function test_no_genera_mas_de_20_eventos_narrativos_sin_importar_la_duracion(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        // 1440 min (24h) muy por encima de cualquier destino real -si el
        // límite no existiera, esto generaría muchísimas más de 20.
        $this->makeDestination('test_narrative_cap', 1440);

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'test_narrative_cap',
        ])->json('id');

        $expedition = PetExpedition::findOrFail($expeditionId);
        $this->assertLessThanOrEqual(20, count($expedition->result_data_json['narrative_log']));
    }

    public function test_no_repite_el_mismo_evento_en_entradas_consecutivas(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        // Duración pensada para generar bastantes eventos (240/12=20,
        // el tope) con un catálogo reducido a propósito: si el
        // anti-repetición no funcionara, con tan pocos candidatos se
        // notaría de inmediato.
        $this->makeDestination('test_narrative_repeat', 240);
        PetNarrativeEvent::create(['destination_id' => null, 'category' => PetNarrativeCategory::Funny, 'rarity' => PetNarrativeRarity::Common, 'text' => 'Texto A.']);
        PetNarrativeEvent::create(['destination_id' => null, 'category' => PetNarrativeCategory::Funny, 'rarity' => PetNarrativeRarity::Common, 'text' => 'Texto B.']);

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'test_narrative_repeat',
        ])->json('id');

        $log = PetExpedition::findOrFail($expeditionId)->result_data_json['narrative_log'];
        $this->assertGreaterThan(2, count($log));

        for ($i = 1; $i < count($log); $i++) {
            $this->assertNotEquals(
                $log[$i - 1]['event_id'],
                $log[$i]['event_id'],
                "Se repitió el mismo evento en dos entradas consecutivas (índice {$i})."
            );
        }
    }

    public function test_evento_exclusivo_de_un_destino_no_aparece_en_otro(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        $destinationA = $this->makeDestination('test_narrative_dest_a', 240);
        $this->makeDestination('test_narrative_dest_b', 240);

        PetNarrativeEvent::create([
            'destination_id' => $destinationA->id,
            'category' => PetNarrativeCategory::Strange,
            'rarity' => PetNarrativeRarity::Common,
            'text' => 'Texto exclusivo del destino A.',
        ]);

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'test_narrative_dest_b',
        ])->json('id');

        $log = PetExpedition::findOrFail($expeditionId)->result_data_json['narrative_log'];
        $texts = array_column($log, 'text');

        $this->assertNotContains('Texto exclusivo del destino A.', $texts);
    }

    public function test_evento_universal_puede_aparecer_en_cualquier_destino(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        // Sin eventos propios: si aparece ALGO en la bitácora, solo puede
        // venir del pool universal.
        $this->makeDestination('test_narrative_universal_only', 60);

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'test_narrative_universal_only',
        ])->json('id');

        $log = PetExpedition::findOrFail($expeditionId)->result_data_json['narrative_log'];
        $this->assertNotEmpty($log);
    }

    public function test_la_bitacora_queda_persistida_en_la_expedicion(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);

        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));
        $response = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'forest',
        ]);
        $expedition = PetExpedition::findOrFail($response->json('id'));

        // Una vez terminada la expedición, todo lo programado ya
        // "ocurrió" -la bitácora revelada por la API debe coincidir
        // exactamente con lo persistido en result_data_json (Fase 7:
        // solo se guarda una vez en start(), nunca se reescribe después).
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:30:00'));
        $expeditionResponse = $this->withToken($token)->getJson('/api/v1/pet/expedition');
        $this->assertEquals(
            $expedition->fresh()->result_data_json['narrative_log'],
            $expeditionResponse->json('narrative_log')
        );
    }

    public function test_la_bitacora_se_reconstruye_correctamente_tras_cerrar_el_navegador(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $this->makeDestination('test_narrative_afk', 120);

        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));
        $started = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'test_narrative_afk',
        ]);
        $started->assertStatus(201);
        // El total programado vive en la DB desde el instante del start()
        // -la respuesta de start() en sí, a t=0, revela 0 entradas porque
        // legítimamente todavía no pasó nada, ver test de arriba-.
        $totalScheduled = count(PetExpedition::findOrFail($started->json('id'))->result_data_json['narrative_log']);
        $this->assertGreaterThan(0, $totalScheduled);

        // "Cierra el navegador" y vuelve a mitad de la expedición.
        Carbon::setTestNow(Carbon::parse('2026-01-01 11:00:00'));
        $midway = $this->withToken($token)->getJson('/api/v1/pet/expedition');
        $midway->assertOk();
        $midwayCount = count($midway->json('narrative_log'));
        $this->assertGreaterThan(0, $midwayCount);
        $this->assertLessThan($totalScheduled, $midwayCount, 'A mitad de la expedición no debería verse la bitácora completa todavía.');

        // Vuelve mucho después, ya terminada.
        Carbon::setTestNow(Carbon::parse('2026-01-01 13:00:00'));
        $after = $this->withToken($token)->getJson('/api/v1/pet/expedition');
        $after->assertOk();
        $this->assertEquals($totalScheduled, count($after->json('narrative_log')));
    }

    public function test_claim_no_altera_la_bitacora(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $this->makeDestination('test_narrative_claim', 60);

        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));
        $started = $this->withToken($token)->postJson('/api/v1/pet/expedition/start', [
            'destination_key' => 'test_narrative_claim',
        ]);
        // Ground truth = lo persistido, no la respuesta de start() (que a
        // t=0 revela 0 entradas legítimamente).
        $originalLog = PetExpedition::findOrFail($started->json('id'))->result_data_json['narrative_log'];

        Carbon::setTestNow(Carbon::parse('2026-01-01 11:30:00'));
        $claimed = $this->withToken($token)->postJson('/api/v1/pet/expedition/claim');

        $claimed->assertOk();
        $this->assertEquals($originalLog, $claimed->json('expedition.narrative_log'));
    }
}
