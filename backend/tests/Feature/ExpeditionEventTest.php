<?php

namespace Tests\Feature;

use App\Enums\CheckpointStatus;
use App\Models\Character;
use App\Models\ExpeditionDefinition;
use App\Models\ExpeditionEventDefinition;
use App\Models\ExpeditionReward;
use App\Models\Item;
use App\Models\Pet;
use App\Models\PetExpedition;
use App\Models\User;
use App\Services\ExpeditionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

// F22 (docs/PETS_EXPEDITIONS_SYSTEM.md §5.7/§7/§11): checkpoints kind=event
// (chest/enemy/help) con consecuencia mecánica real -planificación sin RNG
// anticipado, resolución perezosa que se detiene en awaiting_decision,
// decide() idempotente que continúa el catch-up, loot con procedencia
// (expedition_loot vs event_loot). Fuerza 100% de checkpoints kind=event
// (cuando hay contenido mecánico disponible) para que las pruebas sean
// deterministas, no dependan de la probabilidad real de producción.
class ExpeditionEventTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('expeditions.checkpoint_event_chance_pct', 100);

        // Los 12 eventos mecánicos reales de ExpeditionMechanicalEventSeeder
        // ya están commiteados en la DB de desarrollo (fuera de la
        // transacción de este test) -universales, así que compiten con los
        // eventos que cada test crea a propósito para ser deterministas.
        // Se desactivan acá (dentro de la transacción, se revierte solo al
        // terminar el test) para que pickEvent() solo vea el contenido que
        // cada test arma explícitamente.
        ExpeditionEventDefinition::whereIn('type', ['chest', 'enemy', 'help'])->update(['is_active' => false]);
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

    private function petFor(string $token): Pet
    {
        $petId = $this->withToken($token)->getJson('/api/v1/pet')->json('id');

        return Pet::findOrFail($petId);
    }

    private function makeDefinition(string $key, int $durationSeconds = 3600): ExpeditionDefinition
    {
        $definition = ExpeditionDefinition::create([
            'key' => $key,
            'name' => "Definición de prueba ({$key})",
            'description' => 'Definición de prueba.',
            'difficulty' => 1,
            'duration_seconds' => $durationSeconds,
            'min_pet_level' => 1,
            'is_active' => true,
        ]);

        // Reusa un item real ya sembrado (EconomyItemSeeder) -sin esto
        // rollRewards()/rollEventLoot() no tienen nada contra qué tirar.
        $item = Item::where('key', 'wood')->firstOrFail();
        ExpeditionReward::create([
            'expedition_definition_id' => $definition->id,
            'item_id' => $item->id,
            'weight' => 100,
            'min_qty' => 1,
            'max_qty' => 1,
            'rarity_tier' => 'common',
        ]);

        return $definition;
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

    private function makeEnemyEvent(?int $definitionId, bool $requiresDecision, int $winChance, array $damageOnLoss = [10, 10]): ExpeditionEventDefinition
    {
        return ExpeditionEventDefinition::create([
            'expedition_definition_id' => $definitionId,
            'type' => 'enemy',
            'rarity' => 'common',
            'weight' => 1,
            'is_active' => true,
            'title' => 'Enemigo de prueba',
            'text' => 'Un enemigo de prueba aparece en el camino.',
            'config_json' => [
                'requires_decision' => $requiresDecision,
                'options' => ['fight', 'flee'],
                'win_chance_base' => $winChance,
                'loot_on_win_multiplier' => 1.0,
                'damage_on_loss' => $damageOnLoss,
            ],
        ]);
    }

    private function makeChestEvent(?int $definitionId, array $openOdds): ExpeditionEventDefinition
    {
        return ExpeditionEventDefinition::create([
            'expedition_definition_id' => $definitionId,
            'type' => 'chest',
            'rarity' => 'common',
            'weight' => 1,
            'is_active' => true,
            'title' => 'Cofre de prueba',
            'text' => 'Un cofre de prueba aparece en el camino.',
            'config_json' => [
                'requires_decision' => false,
                'open_odds' => $openOdds,
                'loot_multiplier' => 1.0,
                'trap_damage' => [7, 7],
            ],
        ]);
    }

    // ===================== Planificación (sin RNG anticipado) =====================

    public function test_planifica_checkpoints_narrative_y_event_cuando_hay_contenido_mecanico(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_plan_mixed', 240 * 60); // 20 checkpoints
        $this->makeEnemyEvent($definition->id, true, 50);

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_plan_mixed',
        ])->json('id');

        $kinds = PetExpedition::findOrFail($expeditionId)->checkpoints()->orderBy('sequence')->get()->map(fn ($c) => $c->kind->value)->all();

        $this->assertSame('narrative', $kinds[0], 'El primer checkpoint siempre es narrative.');
        $this->assertContains('event', $kinds, 'Con 100% de chance y contenido mecánico disponible, debe haber al menos un event.');
    }

    public function test_planifica_100pct_narrative_si_no_hay_contenido_mecanico_disponible(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_plan_fallback', 240 * 60);
        $this->makeNarrativeEvent($definition->id, 'Único evento disponible, puramente narrativo.');
        // Sin ningún chest/enemy/help propio ni universal en este entorno
        // de test -DatabaseTransactions revierte cualquier fila de otro
        // test, así que el pool mecánico real de F22 no está presente acá.

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_plan_fallback',
        ])->json('id');

        $kinds = PetExpedition::findOrFail($expeditionId)->checkpoints()->pluck('kind');
        foreach ($kinds as $kind) {
            $this->assertSame('narrative', $kind->value, 'Sin contenido mecánico disponible, todo cae a narrative (degradación segura).');
        }
    }

    public function test_ningun_checkpoint_trae_evento_elegido_ni_payload_recien_planificado(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_no_rng_anticipado', 240 * 60);
        $this->makeEnemyEvent($definition->id, true, 50);

        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_no_rng_anticipado',
        ])->json('id');

        $checkpoints = PetExpedition::findOrFail($expeditionId)->checkpoints;
        foreach ($checkpoints as $checkpoint) {
            $this->assertNull($checkpoint->event_definition_id, 'start() nunca elige el evento concreto, sea cual sea el kind.');
            $this->assertNull($checkpoint->payload);
            $this->assertSame('pending', $checkpoint->status->value);
        }
    }

    // ===================== awaiting_decision / decide =====================

    public function test_checkpoint_enemy_con_requires_decision_pasa_a_awaiting_decision_y_expone_el_evento(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_awaiting', 3600); // 3 checkpoints (piso)
        $this->makeEnemyEvent($definition->id, true, 50);

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_awaiting',
        ])->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-01 09:30:00'));
        $response = $this->withToken($token)->getJson('/api/v1/pet/expeditions/current');
        $response->assertOk();

        $checkpoints = $response->json('checkpoints');
        $awaiting = collect($checkpoints)->firstWhere('status', 'awaiting_decision');

        $this->assertNotNull($awaiting, 'Con requires_decision=true, algún checkpoint debe quedar awaiting_decision.');
        $this->assertNull($awaiting['payload'], 'payload sigue null mientras se espera la decisión.');
        $this->assertSame('Enemigo de prueba', $awaiting['event']['title']);
        $this->assertEqualsCanonicalizing(['fight', 'flee'], $awaiting['event']['options']);
    }

    public function test_decide_aplica_la_consecuencia_y_resuelve_el_checkpoint(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_decide_win', 3600);
        $this->makeEnemyEvent($definition->id, true, 100); // gana siempre

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_decide_win',
        ])->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-01 09:30:00'));
        $current = $this->withToken($token)->getJson('/api/v1/pet/expeditions/current');
        $checkpointId = collect($current->json('checkpoints'))->firstWhere('status', 'awaiting_decision')['id'];

        $response = $this->withToken($token)->postJson("/api/v1/pet/expeditions/checkpoints/{$checkpointId}/decide", [
            'decision' => 'fight',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'resolved');
        $response->assertJsonPath('payload.outcome', 'win');
        $response->assertJsonPath('payload.decision', 'fight');
        $this->assertNotEmpty($response->json('payload.loot'));
    }

    public function test_decide_con_opcion_invalida_devuelve_422(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_decide_invalid', 3600);
        $this->makeEnemyEvent($definition->id, true, 50);

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_decide_invalid',
        ])->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-01 09:30:00'));
        $current = $this->withToken($token)->getJson('/api/v1/pet/expeditions/current');
        $checkpointId = collect($current->json('checkpoints'))->firstWhere('status', 'awaiting_decision')['id'];

        $this->withToken($token)->postJson("/api/v1/pet/expeditions/checkpoints/{$checkpointId}/decide", [
            'decision' => 'dance',
        ])->assertStatus(422);
    }

    public function test_decide_flee_evita_dano_y_no_otorga_loot(): void
    {
        [, $token] = $this->characterWithToken();
        $pet = $this->petFor($token);
        $definition = $this->makeDefinition('test_decide_flee', 3600);
        $this->makeEnemyEvent($definition->id, true, 0, [50, 50]); // perdería siempre si peleara

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_decide_flee',
        ])->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-01 09:30:00'));
        $current = $this->withToken($token)->getJson('/api/v1/pet/expeditions/current');
        $checkpointId = collect($current->json('checkpoints'))->firstWhere('status', 'awaiting_decision')['id'];

        $healthBefore = $pet->fresh()->health;
        $response = $this->withToken($token)->postJson("/api/v1/pet/expeditions/checkpoints/{$checkpointId}/decide", [
            'decision' => 'flee',
        ]);

        $response->assertOk();
        $response->assertJsonPath('payload.outcome', 'fled');
        $this->assertEmpty($response->json('payload.loot'));
        $this->assertSame($healthBefore, $pet->fresh()->health, 'flee no debe aplicar ningún daño.');
    }

    // ===================== Catch-up (detenido / continuado) =====================

    public function test_catch_up_se_detiene_en_el_primer_checkpoint_awaiting_decision(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_catchup_stop', 240 * 60); // 20 checkpoints
        $this->makeEnemyEvent($definition->id, true, 50);

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_catchup_stop',
        ])->json('id');

        // Un único salto grande -simula volver varias horas después-.
        Carbon::setTestNow(Carbon::parse('2026-01-03 08:00:00'));
        $expedition = app(ExpeditionService::class)->resolveDueCheckpoints(PetExpedition::findOrFail($expeditionId));

        $this->assertSame('active', $expedition->status->value, 'No puede completarse mientras haya un awaiting_decision.');

        $checkpoints = $expedition->checkpoints()->orderBy('sequence')->get();
        $firstAwaitingIndex = $checkpoints->search(fn ($c) => $c->status === CheckpointStatus::AwaitingDecision);
        $this->assertNotFalse($firstAwaitingIndex, 'Debe existir un checkpoint awaiting_decision.');

        // Todo lo posterior al primer awaiting_decision debe seguir
        // pending -el motor NO debe saltárselo ni resolver de más.
        foreach ($checkpoints as $index => $checkpoint) {
            if ($index > $firstAwaitingIndex) {
                $this->assertSame(
                    CheckpointStatus::Pending,
                    $checkpoint->status,
                    "El checkpoint {$index} debería seguir pending -el catch-up debe detenerse en el primer awaiting_decision."
                );
            }
        }
    }

    public function test_catch_up_continua_despues_de_decidir_y_se_detiene_de_nuevo_si_hay_otro(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_catchup_continue', 240 * 60); // 20 checkpoints
        // Solo eventos que requieren decisión disponibles -maximiza la
        // chance de encontrar una SEGUNDA pausa tras resolver la primera.
        $this->makeEnemyEvent($definition->id, true, 100);

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_catchup_continue',
        ])->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-03 08:00:00'));
        $service = app(ExpeditionService::class);
        $expedition = $service->resolveDueCheckpoints(PetExpedition::findOrFail($expeditionId));

        $resolvedBefore = $expedition->checkpoints()->where('status', 'resolved')->count();
        $firstAwaiting = $expedition->checkpoints()->where('status', 'awaiting_decision')->orderBy('sequence')->first();
        $this->assertNotNull($firstAwaiting);

        $service->decide($firstAwaiting, 'fight');

        $expeditionAfter = $expedition->fresh();
        $resolvedAfter = $expeditionAfter->checkpoints()->where('status', 'resolved')->count();

        $this->assertGreaterThan($resolvedBefore, $resolvedAfter, 'decide() debe continuar resolviendo lo que siga vencido.');

        // Si el motor encontró otro checkpoint que requiere decisión más
        // adelante, la expedición debe seguir activa (detenida de nuevo);
        // si no había ninguno más, es válido que ya haya completado. Lo
        // que NUNCA es válido es que queden checkpoints "salteados" (un
        // pending vencido con un resolved DESPUÉS en la secuencia).
        $ordered = $expeditionAfter->checkpoints()->orderBy('sequence')->get();
        $sawUnresolvedDue = false;
        foreach ($ordered as $checkpoint) {
            if ($checkpoint->status->value === 'pending' && $checkpoint->scheduled_at->lte(now())) {
                $sawUnresolvedDue = true;
                continue;
            }
            if ($sawUnresolvedDue) {
                $this->assertNotSame(
                    'resolved',
                    $checkpoint->status->value,
                    'No puede haber un checkpoint resuelto después de uno vencido sin resolver -el orden se rompió.'
                );
            }
        }
    }

    // ===================== Loot: procedencia y combinación =====================

    public function test_chest_auto_resuelve_sin_pausar_y_registra_loot_con_procedencia(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_chest_loot', 3600);
        $this->makeChestEvent($definition->id, ['loot' => 100, 'trap' => 0, 'nothing' => 0]); // siempre loot

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_chest_loot',
        ])->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-01 09:30:00'));
        $expedition = app(ExpeditionService::class)->resolveDueCheckpoints(PetExpedition::findOrFail($expeditionId));

        $chestCheckpoint = $expedition->checkpoints()
            ->whereHas('eventDefinition', fn ($q) => $q->where('type', 'chest'))
            ->where('status', 'resolved')
            ->first();

        $this->assertNotNull($chestCheckpoint, 'El chest no requiere decisión -debe auto-resolverse durante el catch-up.');
        $this->assertSame('loot', $chestCheckpoint->payload['outcome']);
        $this->assertNotEmpty($chestCheckpoint->payload['loot']);

        $eventLoot = $expedition->fresh()->result_data_json['event_loot'] ?? [];
        $this->assertNotEmpty($eventLoot);
        $this->assertSame($chestCheckpoint->id, $eventLoot[0]['checkpoint_id'], 'La procedencia debe apuntar al checkpoint concreto que lo generó.');
    }

    public function test_dano_de_enemy_perdido_se_aplica_a_la_mascota_inmediatamente(): void
    {
        // 0% acá a propósito: con el piso de 3 checkpoints, dos de ellos
        // (no solo uno) podrían volverse event con 100% de chance,
        // aplicando daño más de una vez y rompiendo la aritmética exacta
        // que este test necesita -se fuerza UN solo checkpoint a
        // kind=event a mano, para un escenario 100% determinista.
        Config::set('expeditions.checkpoint_event_chance_pct', 0);

        [, $token] = $this->characterWithToken();
        $pet = $this->petFor($token);
        $definition = $this->makeDefinition('test_enemy_damage', 3600);
        $this->makeEnemyEvent($definition->id, false, 0, [15, 15]); // auto-resuelve, pierde siempre

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_enemy_damage',
        ])->json('id');

        $expedition = PetExpedition::findOrFail($expeditionId);
        $expedition->checkpoints()->where('sequence', 1)->update(['kind' => 'event']);

        $healthBefore = $pet->fresh()->health;

        Carbon::setTestNow(Carbon::parse('2026-01-01 09:30:00'));
        app(ExpeditionService::class)->resolveDueCheckpoints($expedition->fresh());

        $this->assertSame($healthBefore - 15, $pet->fresh()->health);
    }

    public function test_narrative_sigue_funcionando_junto_a_checkpoints_event(): void
    {
        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_mixed_narrative', 3600);
        $this->makeNarrativeEvent($definition->id, 'Texto narrativo de prueba.');
        $this->makeChestEvent($definition->id, ['loot' => 0, 'trap' => 0, 'nothing' => 100]);

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_mixed_narrative',
        ])->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-01 09:30:00'));
        $expedition = app(ExpeditionService::class)->resolveDueCheckpoints(PetExpedition::findOrFail($expeditionId));

        $narrativeCheckpoint = $expedition->checkpoints()->where('kind', 'narrative')->first();
        $this->assertNotNull($narrativeCheckpoint);
        $this->assertArrayHasKey('text', $narrativeCheckpoint->payload);
    }

    public function test_claim_combina_expedition_loot_y_event_loot_y_es_idempotente(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_combined_claim', 3600);
        $this->makeChestEvent($definition->id, ['loot' => 100, 'trap' => 0, 'nothing' => 0]);

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));
        $expeditionId = $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => 'test_combined_claim',
        ])->json('id');

        Carbon::setTestNow(Carbon::parse('2026-01-01 09:30:00'));
        $expedition = app(ExpeditionService::class)->resolveDueCheckpoints(PetExpedition::findOrFail($expeditionId));
        $this->assertSame('completed', $expedition->status->value);

        $data = $expedition->fresh()->result_data_json;
        $this->assertNotEmpty($data['expedition_loot'], 'Debe existir el roll final de expedition_rewards.');
        $this->assertNotEmpty($data['event_loot'], 'Debe existir loot proveniente del chest.');

        $first = $this->withToken($token)->postJson("/api/v1/pet/expeditions/{$expeditionId}/claim");
        $first->assertOk();
        $totalAfterFirst = \App\Models\InventoryItem::where('character_id', $character->id)->sum('quantity');
        $this->assertGreaterThan(0, $totalAfterFirst);

        $second = $this->withToken($token)->postJson("/api/v1/pet/expeditions/{$expeditionId}/claim");
        $second->assertOk();
        $totalAfterSecond = \App\Models\InventoryItem::where('character_id', $character->id)->sum('quantity');

        $this->assertSame($totalAfterFirst, $totalAfterSecond, 'Reclamar dos veces no debe duplicar loot combinado.');
    }
}
