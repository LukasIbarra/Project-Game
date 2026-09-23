<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\ExpeditionDefinition;
use App\Models\ExpeditionEventDefinition;
use App\Models\ExpeditionReward;
use App\Models\Item;
use App\Models\Pet;
use App\Models\PetExpedition;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

// Ajuste post-F22 (garantía mínima de checkpoints kind=event, separada de
// checkpoint_event_chance_pct): sin esto, una expedición corta (piso de 3
// checkpoints, 2 elegibles) tenía ~49% de chance real de completarse sin
// tocar el sistema de eventos interactivos ni una vez -matemáticamente
// válido, mala sensación de gameplay-. Ver ExpeditionService::planCheckpoints()
// y config('expeditions.min_event_checkpoints').
//
// Este archivo prueba SOLO la planificación (items 1-8 del ajuste). El
// resto de la maquinaria de eventos (awaiting_decision, catch-up, decide()
// idempotente, loot con procedencia -items 9-12-) no se tocó y sigue
// cubierto por ExpeditionEventTest.php sin cambios de comportamiento.
class ExpeditionPlanningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Mismo criterio que ExpeditionEventTest.php: los eventos mecánicos
        // universales reales (ExpeditionMechanicalEventSeeder) compiten con
        // el contenido que cada test arma a propósito -se desactivan acá
        // (dentro de la transacción) para que cada test controle
        // exactamente qué contenido mecánico existe.
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

    // F23: GET /pet ya no auto-crea nada -adopta un starter fijo-.
    // forgetGuards(): artefacto del entorno de test (mismo objeto User
    // reutilizado entre requests dentro de un test), nunca en producción.
    private function petFor(string $token): Pet
    {
        $petId = $this->withToken($token)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->json('id');
        $this->app['auth']->forgetGuards();

        return Pet::findOrFail($petId);
    }

    private function makeDefinition(string $key, int $durationSeconds): ExpeditionDefinition
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

    private function makeEnemyEvent(?int $definitionId): ExpeditionEventDefinition
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
                'requires_decision' => true,
                'options' => ['fight', 'flee'],
                'win_chance_base' => 50,
                'loot_on_win_multiplier' => 1.0,
                'damage_on_loss' => [10, 10],
            ],
        ]);
    }

    private function checkpointKinds(int $expeditionId): array
    {
        return PetExpedition::findOrFail($expeditionId)
            ->checkpoints()
            ->orderBy('sequence')
            ->get()
            ->map(fn ($c) => $c->kind->value)
            ->all();
    }

    private function startExpedition(string $token, string $key): int
    {
        return $this->withToken($token)->postJson('/api/v1/pet/expeditions/start', [
            'expedition_key' => $key,
        ])->json('id');
    }

    // 1/2/3. Expedición corta (30 min, piso de 3 checkpoints): primero
    // siempre narrative, al menos 1 narrative, al menos 1 event -incluso
    // con checkpoint_event_chance_pct en 0 (la garantía es lo único que
    // puede producir el event acá, el % de arriba nunca lo haría-.
    public function test_expedicion_corta_primero_narrative_y_garantiza_al_menos_un_narrative_y_un_event(): void
    {
        Config::set('expeditions.checkpoint_event_chance_pct', 0);
        Config::set('expeditions.min_event_checkpoints', 1);

        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_short_guarantee', 30 * 60);
        $this->makeEnemyEvent($definition->id);

        $expeditionId = $this->startExpedition($token, 'test_short_guarantee');
        $kinds = $this->checkpointKinds($expeditionId);

        $this->assertCount(3, $kinds, 'El piso de MIN_CHECKPOINTS es 3.');
        $this->assertSame('narrative', $kinds[0], 'El primer checkpoint siempre es narrative.');
        $this->assertGreaterThanOrEqual(1, count(array_filter($kinds, fn ($k) => $k === 'narrative')));
        $this->assertGreaterThanOrEqual(
            1,
            count(array_filter($kinds, fn ($k) => $k === 'event')),
            'Con checkpoint_event_chance_pct=0, el único event posible es el forzado por la garantía mínima.'
        );
    }

    // 4. La garantía funciona incluso cuando el RNG adicional produce 0
    // events "de forma natural" -mismo escenario que el test anterior pero
    // sobre una duración distinta (1h/5 checkpoints), para no depender de
    // que el piso de 3 sea el único caso cubierto-.
    public function test_garantia_funciona_aunque_el_rng_adicional_no_produzca_ningun_event(): void
    {
        Config::set('expeditions.checkpoint_event_chance_pct', 0);
        Config::set('expeditions.min_event_checkpoints', 1);

        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_1h_guarantee', 60 * 60);
        $this->makeEnemyEvent($definition->id);

        $expeditionId = $this->startExpedition($token, 'test_1h_guarantee');
        $kinds = $this->checkpointKinds($expeditionId);

        $this->assertCount(5, $kinds);
        $this->assertContains('event', $kinds);
    }

    // 5. checkpoint_event_chance_pct sigue pudiendo generar events
    // ADICIONALES por encima del mínimo garantizado -100% de chance sobre
    // una expedición de 2h (10 checkpoints, 9 elegibles) debe producir
    // bastantes más que 1-.
    public function test_chance_pct_sigue_generando_events_adicionales_por_encima_del_minimo(): void
    {
        Config::set('expeditions.checkpoint_event_chance_pct', 100);
        Config::set('expeditions.min_event_checkpoints', 1);

        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_2h_high_chance', 120 * 60);
        $this->makeEnemyEvent($definition->id);

        $expeditionId = $this->startExpedition($token, 'test_2h_high_chance');
        $kinds = $this->checkpointKinds($expeditionId);
        $eventCount = count(array_filter($kinds, fn ($k) => $k === 'event'));

        $this->assertCount(10, $kinds);
        $this->assertSame(9, $eventCount, 'Los 9 checkpoints elegibles (sequence>0) deberían ser event con 100% de chance.');
    }

    // 6/7. start() sigue sin pre-rollear nada -ni siquiera en el checkpoint
    // que la garantía mínima fuerza a kind=event-: sin event_definition_id,
    // sin payload, todavía pending. Elegir el EventDefinition concreto y
    // resolver siguen siendo trabajo exclusivo de resolveDueCheckpoints().
    public function test_la_garantia_minima_no_preselecciona_event_definition_ni_preresuelve_nada(): void
    {
        Config::set('expeditions.checkpoint_event_chance_pct', 0);
        Config::set('expeditions.min_event_checkpoints', 1);

        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_no_prerroll', 30 * 60);
        $this->makeEnemyEvent($definition->id);

        $expeditionId = $this->startExpedition($token, 'test_no_prerroll');
        $checkpoints = PetExpedition::findOrFail($expeditionId)->checkpoints()->orderBy('sequence')->get();

        $eventCheckpoint = $checkpoints->firstWhere('kind', \App\Enums\CheckpointKind::Event);
        $this->assertNotNull($eventCheckpoint, 'Debe existir el checkpoint event forzado por la garantía.');
        $this->assertNull($eventCheckpoint->event_definition_id, 'planCheckpoints() nunca elige el EventDefinition concreto.');
        $this->assertNull($eventCheckpoint->payload, 'planCheckpoints() nunca calcula ningún resultado.');
        $this->assertSame('pending', $eventCheckpoint->status->value);

        foreach ($checkpoints as $checkpoint) {
            $this->assertNull($checkpoint->event_definition_id);
            $this->assertNull($checkpoint->payload);
            $this->assertSame('pending', $checkpoint->status->value);
        }
    }

    // 8. Expediciones largas (4h, tope de 20 checkpoints) conservan una
    // mezcla razonable -al menos 1 narrative (el primero) y al menos 1
    // event (garantizado), sin que la garantía ni el % infle el total por
    // encima del tope existente (MAX_CHECKPOINTS ya cubría esto, la
    // garantía no agrega checkpoints nuevos, solo cambia el kind de los
    // que ya existían).
    public function test_expedicion_larga_conserva_mezcla_narrative_event_sin_pasar_el_tope(): void
    {
        Config::set('expeditions.checkpoint_event_chance_pct', 30);
        Config::set('expeditions.min_event_checkpoints', 1);

        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_4h_mix', 240 * 60);
        $this->makeEnemyEvent($definition->id);

        $expeditionId = $this->startExpedition($token, 'test_4h_mix');
        $kinds = $this->checkpointKinds($expeditionId);

        $this->assertCount(20, $kinds, 'MAX_CHECKPOINTS=20 sigue siendo el tope -la garantía no agrega checkpoints-.');
        $this->assertSame('narrative', $kinds[0]);
        $this->assertGreaterThanOrEqual(1, count(array_filter($kinds, fn ($k) => $k === 'narrative')));
        $this->assertGreaterThanOrEqual(1, count(array_filter($kinds, fn ($k) => $k === 'event')));
    }

    // Degradación segura: sin contenido mecánico disponible, la garantía
    // nunca fuerza un event "vacío" -mismo criterio que
    // checkpoint_event_chance_pct, cae 100% narrative-.
    public function test_sin_contenido_mecanico_disponible_la_garantia_no_fuerza_nada(): void
    {
        Config::set('expeditions.checkpoint_event_chance_pct', 0);
        Config::set('expeditions.min_event_checkpoints', 1);

        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        // Sin makeEnemyEvent()/makeChestEvent()/makeHelpEvent(): esta
        // definición no tiene NINGÚN evento mecánico disponible.
        $this->makeDefinition('test_no_mechanical_content', 30 * 60);

        $expeditionId = $this->startExpedition($token, 'test_no_mechanical_content');
        $kinds = $this->checkpointKinds($expeditionId);

        $this->assertSame(['narrative', 'narrative', 'narrative'], $kinds);
    }

    // min_event_checkpoints=0 (config explícito) deshabilita la garantía
    // por completo -mismo comportamiento que antes de este ajuste-.
    public function test_min_event_checkpoints_en_cero_deshabilita_la_garantia(): void
    {
        Config::set('expeditions.checkpoint_event_chance_pct', 0);
        Config::set('expeditions.min_event_checkpoints', 0);

        [, $token] = $this->characterWithToken();
        $this->petFor($token);
        $definition = $this->makeDefinition('test_guarantee_disabled', 30 * 60);
        $this->makeEnemyEvent($definition->id);

        $expeditionId = $this->startExpedition($token, 'test_guarantee_disabled');
        $kinds = $this->checkpointKinds($expeditionId);

        $this->assertSame(['narrative', 'narrative', 'narrative'], $kinds);
    }
}
