<?php

namespace Tests\Feature;

use App\Enums\CheckpointStatus;
use App\Models\Character;
use App\Models\ExpeditionDefinition;
use App\Models\ExpeditionEventDefinition;
use App\Models\Pet;
use App\Models\PetExpedition;
use App\Models\PetExpeditionCheckpoint;
use App\Models\User;
use App\Services\ExpeditionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

// F21: cubre el corazón del rediseño -planificación de checkpoints,
// resolución perezosa de verdad (no solo de la aplicación de un resultado
// ya calculado), catch-up offline, anti-repetición, e idempotencia de
// decide() bajo concurrencia (docs/PETS_EXPEDITIONS_SYSTEM.md §20).
// Carbon::setTestNow() en vez de esperar tiempo real -"pasar tiempo" acá
// es solo mover el reloj del servidor, nunca sleep().
class ExpeditionCheckpointTest extends TestCase
{
    use DatabaseTransactions;

    // F22: este archivo prueba la maquinaria de checkpoints en general
    // (planificación/catch-up/anti-repetición narrativa/decide legacy) —
    // fuerza 0% de checkpoints kind=event para que sea determinista. El
    // comportamiento con eventos reales (incluido catch-up deteniéndose en
    // awaiting_decision) vive en ExpeditionEventTest.php.
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

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    // F23: GET /pet ya no auto-crea nada -adopta un starter fijo (la
    // especie en sí no importa para estos tests, ninguno depende de cuál).
    // forgetGuards(): evita que la próxima request del propio test vea el
    // Character desactualizado de antes de adoptar -artefacto del entorno
    // de test (el mismo objeto User se reutiliza entre requests dentro de
    // un test), nunca ocurre en producción-.
    private function petFor(string $token): Pet
    {
        $petId = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->json('id');
        $this->app['auth']->forgetGuards();

        return Pet::findOrFail($petId);
    }

    private function makeDefinition(string $key, int $durationSeconds, int $minLevel = 1): ExpeditionDefinition
    {
        return ExpeditionDefinition::create([
            'key' => $key,
            'name' => "Definición de prueba ({$key})",
            'description' => 'Definición de prueba.',
            'difficulty' => 1,
            'duration_seconds' => $durationSeconds,
            'min_pet_level' => $minLevel,
            'is_active' => true,
        ]);
    }

    private function makeNarrativeEvent(?int $definitionId, string $text): ExpeditionEventDefinition
    {
        return ExpeditionEventDefinition::create([
            'expedition_definition_id' => $definitionId,
            'type' => 'narrative',
            'rarity' => 'common',
            'weight' => 1,
            'is_active' => true,
            'title' => null,
            'text' => $text,
            'config_json' => null,
        ]);
    }

    public function test_la_cantidad_de_checkpoints_respeta_el_minimo_y_el_maximo(): void
    {
        [, $tokenShort] = $this->characterWithToken();
        $this->petFor($tokenShort);
        $this->makeDefinition('test_checkpoints_short', 60); // 1 min -> muy por debajo del piso

        $shortId = $this->withToken($tokenShort)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_checkpoints_short',
        ])->json('id');

        $this->assertSame(3, PetExpedition::findOrFail($shortId)->checkpoints()->count());

        $this->app['auth']->forgetGuards();

        [, $tokenLong] = $this->characterWithToken();
        $this->petFor($tokenLong);
        $this->makeDefinition('test_checkpoints_long', 1440 * 60); // 24h -> muy por encima del tope

        $longId = $this->withToken($tokenLong)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_checkpoints_long',
        ])->json('id');

        $this->assertSame(20, PetExpedition::findOrFail($longId)->checkpoints()->count());
    }

    public function test_los_checkpoints_no_comparten_el_mismo_scheduled_at_y_quedan_en_orden(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $this->makeDefinition('test_checkpoints_spread', 240 * 60); // 4h -> 20 checkpoints (tope)

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_checkpoints_spread',
        ])->json('id');

        $timestamps = PetExpedition::findOrFail($expeditionId)->checkpoints()->pluck('scheduled_at')->all();

        $this->assertGreaterThan(1, count($timestamps));
        $strings = array_map(fn ($t) => $t->toIso8601String(), $timestamps);
        $this->assertEquals(count($strings), count(array_unique($strings)));

        $sorted = $strings;
        sort($sorted);
        $this->assertEquals($sorted, $strings);
    }

    // Server-authoritative de verdad (a diferencia de F7): acá el CÁLCULO
    // en sí se retrasa hasta el momento de la resolución, no solo su
    // aplicación. Un gap largo sin ninguna consulta de por medio se
    // resuelve TODO en una sola llamada, en orden, sin importar cuánto
    // tiempo pasó -mismo mecanismo exacto para doble-click y catch-up
    // offline (docs/PETS_EXPEDITIONS_SYSTEM.md §12.1).
    public function test_catch_up_resuelve_todos_los_checkpoints_vencidos_de_una_sola_vez_tras_un_gap_largo(): void
    {
        [, $token] = $this->characterWithToken();
        $pet = $this->petFor($token);
        $this->makeDefinition('test_catchup', 240 * 60); // 4h -> 20 checkpoints

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_catchup',
        ])->json('id');

        // Un único salto grande (nunca incremental) -simula "cerrar el
        // navegador" mucho después de que todo ya venció.
        Carbon::setTestNow(Carbon::parse('2026-01-02 12:00:00'));

        $expedition = app(ExpeditionService::class)->resolveDueCheckpoints(PetExpedition::findOrFail($expeditionId));

        $this->assertSame('completed', $expedition->status->value);
        $checkpoints = $expedition->checkpoints()->get();
        $this->assertCount(20, $checkpoints);
        foreach ($checkpoints as $checkpoint) {
            $this->assertSame(CheckpointStatus::Resolved, $checkpoint->status);
            $this->assertNotNull($checkpoint->resolved_at);
        }
    }

    public function test_checkpoint_ya_resuelto_no_cambia_al_volver_a_resolver(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_stable_resolution', 120 * 60); // 2h -> 10 checkpoints
        $this->makeNarrativeEvent($definition->id, 'Evento de prueba único.');

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_stable_resolution',
        ])->json('id');

        $service = app(ExpeditionService::class);

        // Resuelve lo que ya venció a mitad de camino.
        Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));
        $service->resolveDueCheckpoints(PetExpedition::findOrFail($expeditionId));
        $firstCheckpoint = PetExpedition::findOrFail($expeditionId)->checkpoints()->where('status', 'resolved')->first();
        $this->assertNotNull($firstCheckpoint);
        $originalPayload = $firstCheckpoint->payload;
        $originalResolvedAt = $firstCheckpoint->resolved_at;

        // Avanza más y vuelve a resolver -el checkpoint ya resuelto no
        // debe tocarse de nuevo.
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:30:00'));
        $service->resolveDueCheckpoints(PetExpedition::findOrFail($expeditionId));

        $sameCheckpoint = PetExpeditionCheckpoint::findOrFail($firstCheckpoint->id);
        $this->assertEquals($originalPayload, $sameCheckpoint->payload);
        $this->assertEquals($originalResolvedAt->toIso8601String(), $sameCheckpoint->resolved_at->toIso8601String());
    }

    // Anti-repetición: mismo criterio que F7.1 (evita los últimos 3
    // event_definition_id ya resueltos en ESTA expedición), ahora
    // consultado desde DB en cada resolución individual en vez de en
    // memoria dentro de un único start() -acá se ejercita justamente esa
    // diferencia, resolviendo de a poco en vez de todo junto.
    public function test_no_repite_el_mismo_evento_en_checkpoints_consecutivos(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_no_repeat', 240 * 60); // 4h -> 20 checkpoints
        $this->makeNarrativeEvent($definition->id, 'Texto A.');
        $this->makeNarrativeEvent($definition->id, 'Texto B.');

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_no_repeat',
        ])->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-02 12:00:00'));
        app(ExpeditionService::class)->resolveDueCheckpoints(PetExpedition::findOrFail($expeditionId));

        $eventIds = PetExpedition::findOrFail($expeditionId)->checkpoints()->orderBy('sequence')->pluck('event_definition_id')->all();
        $this->assertGreaterThan(2, count($eventIds));

        for ($i = 1; $i < count($eventIds); $i++) {
            $this->assertNotEquals(
                $eventIds[$i - 1],
                $eventIds[$i],
                "Se repitió el mismo evento en dos checkpoints consecutivos (índice {$i})."
            );
        }
    }

    public function test_evento_universal_puede_aparecer_en_una_expedicion_sin_eventos_propios(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $this->makeDefinition('test_universal_only', 60 * 60); // sin ningún evento propio
        $this->makeNarrativeEvent(null, 'Evento universal de prueba.');

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_universal_only',
        ])->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-01 09:30:00'));
        app(ExpeditionService::class)->resolveDueCheckpoints(PetExpedition::findOrFail($expeditionId));

        $resolvedCount = PetExpedition::findOrFail($expeditionId)->checkpoints()->where('status', 'resolved')->count();
        $this->assertGreaterThan(0, $resolvedCount);
    }

    // Fuerza el estado directamente (en vez de depender de la probabilidad
    // de planCheckpoints()) para probar el locking/idempotencia de
    // decide() de forma determinista -win_chance_base=100 hace que
    // decide('fight') gane siempre, sin flaky-ness. El comportamiento
    // "natural" (F22 realmente planifica/resuelve hasta llegar acá) se
    // cubre en ExpeditionEventTest.php.
    private function forceAwaitingDecisionCheckpoint(int $expeditionId): PetExpeditionCheckpoint
    {
        $event = ExpeditionEventDefinition::create([
            'expedition_definition_id' => null,
            'type' => 'enemy',
            'rarity' => 'common',
            'weight' => 1,
            'is_active' => true,
            'title' => 'Evento de prueba forzado',
            'text' => 'Un enemigo de prueba bloquea el camino.',
            'config_json' => [
                'requires_decision' => true,
                'options' => ['fight', 'flee'],
                'win_chance_base' => 100,
                'loot_on_win_multiplier' => 1.0,
                'damage_on_loss' => [1, 1],
            ],
        ]);

        return PetExpeditionCheckpoint::create([
            'pet_expedition_id' => $expeditionId,
            'sequence' => 999,
            'scheduled_at' => now(),
            'kind' => 'event',
            'status' => 'awaiting_decision',
            'event_definition_id' => $event->id,
            'payload' => null,
        ]);
    }

    public function test_decide_resuelve_un_checkpoint_awaiting_decision(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $this->makeDefinition('test_decide_ok', 60 * 60);

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_decide_ok',
        ])->json('id');
        $checkpoint = $this->forceAwaitingDecisionCheckpoint($expeditionId);

        $response = $this->withToken($token)->postJson("/api/v1/pet/expeditions/checkpoints/{$checkpoint->id}/decide", [
            'decision' => 'fight',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'resolved');
        $this->assertSame('fight', $checkpoint->fresh()->decision);
    }

    public function test_decide_sobre_un_checkpoint_que_no_requiere_decision_falla(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $this->makeDefinition('test_decide_not_awaiting', 60 * 60);

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_decide_not_awaiting',
        ])->json('id');
        $checkpoint = PetExpedition::findOrFail($expeditionId)->checkpoints()->first();

        $this->withToken($token)->postJson("/api/v1/pet/expeditions/checkpoints/{$checkpoint->id}/decide", [
            'decision' => 'fight',
        ])->assertStatus(409);
    }

    // Test de concurrencia explícito (docs/PETS_EXPEDITIONS_SYSTEM.md §20):
    // dos decide() sobre el MISMO checkpoint -el segundo debe encontrarlo
    // ya resuelto (gracias a lockForUpdate()+re-chequeo dentro de la
    // transacción) y devolver el resultado existente sin volver a
    // aplicarlo, nunca fallar ni sobrescribir la decisión ya tomada.
    public function test_dos_decide_simultaneos_sobre_el_mismo_checkpoint_conceden_una_sola_resolucion(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $this->makeDefinition('test_decide_race', 60 * 60);

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_decide_race',
        ])->json('id');
        $checkpoint = $this->forceAwaitingDecisionCheckpoint($expeditionId);

        $first = $this->withToken($token)->postJson("/api/v1/pet/expeditions/checkpoints/{$checkpoint->id}/decide", [
            'decision' => 'fight',
        ]);
        $second = $this->withToken($token)->postJson("/api/v1/pet/expeditions/checkpoints/{$checkpoint->id}/decide", [
            'decision' => 'flee',
        ]);

        $first->assertOk();
        $second->assertOk();

        // La segunda decisión ("flee") NUNCA se aplica -el checkpoint ya
        // estaba resuelto cuando la segunda transacción obtuvo el lock.
        $this->assertSame('fight', $checkpoint->fresh()->decision);
        $this->assertEquals($first->json(), $second->json());
    }

    public function test_no_puede_decidir_un_checkpoint_de_otro_usuario(): void
    {
        [, $tokenA] = $this->characterWithToken();
        [, $tokenB] = $this->characterWithToken();
        $this->petFor($tokenA);
        $this->petFor($tokenB);
        $this->makeDefinition('test_decide_ownership', 60 * 60);

        $expeditionId = $this->withToken($tokenA)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_decide_ownership',
        ])->json('id');
        $checkpoint = $this->forceAwaitingDecisionCheckpoint($expeditionId);

        $this->app['auth']->forgetGuards();

        $this->withToken($tokenB)->postJson("/api/v1/pet/expeditions/checkpoints/{$checkpoint->id}/decide", [
            'decision' => 'fight',
        ])->assertStatus(404);
    }
}
