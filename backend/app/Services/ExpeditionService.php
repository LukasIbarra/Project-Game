<?php

namespace App\Services;

use App\Enums\CheckpointKind;
use App\Enums\CheckpointStatus;
use App\Enums\ExpeditionEventType;
use App\Enums\PetExpeditionStatus;
use App\Enums\PetNarrativeRarity;
use App\Enums\PetStatus;
use App\Models\ExpeditionDefinition;
use App\Models\ExpeditionEventDefinition;
use App\Models\Item;
use App\Models\Pet;
use App\Models\PetExpedition;
use App\Models\PetExpeditionCheckpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// F21/F22 (docs/PETS_EXPEDITIONS_SYSTEM.md §7/§8): reemplaza PetExpeditionService.
// Diseño clave -lo opuesto al sistema anterior-: start() NUNCA rollea
// eventos/loot, solo PLANIFICA (cuántos checkpoints, cuándo, de qué kind).
// resolveDueCheckpoints() es quien RESUELVE cada checkpoint vencido en su
// momento real (elige el evento concreto recién ahí) — mismo principio de
// resolución perezosa que combat_logs (CLAUDE.md #6), pero aplicado de
// verdad al cálculo, no solo a la aplicación de un resultado ya fijado.
//
// Separación estricta (CLAUDE.md metodología + diseño §8):
//   Planificación  -> start()/planCheckpoints() (cuántos checkpoints, cuándo,
//                      de qué kind -nunca QUÉ evento ni su resultado)
//   Resolución     -> resolveDueCheckpoints()/resolveCheckpoint() (elige el
//                      evento concreto, tira los dados de su resultado)
//   Aplicación     -> resolveEventOutcome()/applyDamage()/rollEventLoot()
//                      (toma un resultado ya calculado y muta pet.health/
//                      result_data_json — nunca vuelve a tirar dados)
//   Historial      -> ActivityLogger (nunca decide ni aplica nada)
//
// F22 (docs/PETS_EXPEDITIONS_SYSTEM.md §5.7/§7/§11): agrega checkpoints
// kind=event, respaldados por ExpeditionEventDefinition (type: narrative|
// chest|enemy|help). El motor NUNCA bifurca por expedition key — solo por
// `kind`/`type`/`config_json.requires_decision`, todo dato, cero
// condicionales por destino. `requires_decision` (config_json, no el
// `type`) decide si un checkpoint pausa en awaiting_decision o se
// auto-resuelve como narrative pero con consecuencia real.
class ExpeditionService
{
    public function __construct(
        private readonly InventoryGrantService $grantService,
        private readonly ActivityLogger $activity,
    ) {
    }

    private const MIN_CHECKPOINTS = 3;

    private const MAX_CHECKPOINTS = 20;

    // Mismo criterio que la bitácora narrativa de F7.1: cuántos minutos de
    // expedición "valen" un checkpoint. Un Bosque de 30min -> 3 (el piso);
    // un Castillo de 12h -> se cae en el tope de 20.
    private const MINUTES_PER_CHECKPOINT = 12;

    // Cuántas recompensas DISTINTAS se conceden al completar -conservador
    // a propósito (mismo valor base que el sistema anterior sin ningún
    // bonus de evento). El balance fino por duración/dificultad es F23
    // (docs/PETS_EXPEDITIONS_SYSTEM.md §10), no se implementa acá.
    private const REWARD_ITEM_COUNT = 2;

    // Tipos de evento con consecuencia mecánica real (F22) — narrative
    // queda aparte porque nunca tiene consecuencia, solo texto.
    private const MECHANICAL_TYPES = [
        ExpeditionEventType::Chest->value,
        ExpeditionEventType::Enemy->value,
        ExpeditionEventType::Help->value,
    ];

    // Rango de daño por defecto cuando el config_json de un evento no trae
    // uno propio (p.ej. chest.trap_damage) -constante nombrada, nunca un
    // número mágico repetido en cada resolver.
    private const DEFAULT_DAMAGE_RANGE = [5, 15];

    // "La expedición que importa ahora mismo" -activa o completada pero
    // todavía sin reclamar-, ya resuelta contra el reloj real. Compartido
    // por PetController y PetExpeditionController para no duplicar esta
    // query+resolución en cada uno (antes vivía repetida en ambos).
    public function currentFor(Pet $pet): ?PetExpedition
    {
        $expedition = $pet->expeditions()
            ->whereIn('status', [PetExpeditionStatus::Active, PetExpeditionStatus::Completed])
            ->latest('id')
            ->first();

        if (! $expedition) {
            return null;
        }

        return $this->resolveDueCheckpoints($expedition);
    }

    public function start(Pet $pet, ExpeditionDefinition $definition): PetExpedition
    {
        $startedAt = now();
        $endsAt = $startedAt->clone()->addSeconds($definition->duration_seconds);

        $expedition = PetExpedition::create([
            'pet_id' => $pet->id,
            'expedition_definition_id' => $definition->id,
            'status' => PetExpeditionStatus::Active,
            'started_at' => $startedAt,
            'ends_at' => $endsAt,
            'result_data_json' => null,
        ]);

        $this->planCheckpoints($expedition, $definition, $startedAt, $endsAt);

        $pet->status = PetStatus::Exploring;
        $pet->save();

        $this->activity->log($pet->character, 'expedition_started', [
            'expedition_name' => $definition->name,
            'duration_seconds' => $definition->duration_seconds,
        ]);

        return $expedition;
    }

    // Planificación pura: decide CUÁNTOS checkpoints, CUÁNDO (scheduled_at
    // con jitter, mismo algoritmo que la bitácora de F7.1) y de qué KIND
    // -nunca elige el evento concreto ni calcula ningún resultado (§8: kind
    // es una decisión de planificación, no de resolución). El primer
    // checkpoint SIEMPRE es narrative (apertura de la expedición); los
    // siguientes tienen una probabilidad configurable
    // (config('expeditions.checkpoint_event_chance_pct')) de ser kind=event
    // -solo si la expedición tiene al menos un evento mecánico disponible,
    // si no, cae a narrative siempre (degradación segura, nunca un
    // checkpoint "vacío"). Cero condicionales por expedition key.
    private function planCheckpoints(
        PetExpedition $expedition,
        ExpeditionDefinition $definition,
        Carbon $startedAt,
        Carbon $endsAt
    ): void {
        $totalMinutes = $startedAt->diffInMinutes($endsAt, absolute: true);
        $count = (int) min(
            self::MAX_CHECKPOINTS,
            max(self::MIN_CHECKPOINTS, intdiv($totalMinutes, self::MINUTES_PER_CHECKPOINT))
        );

        $offsets = $this->jitteredOffsets($totalMinutes, $count);

        $hasMechanicalEvents = $this->hasMechanicalEventsAvailable($definition);
        $eventChancePct = (int) config('expeditions.checkpoint_event_chance_pct', 0);

        $rows = [];
        foreach ($offsets as $sequence => $offsetMinutes) {
            $isEvent = $sequence > 0 && $hasMechanicalEvents && random_int(1, 100) <= $eventChancePct;

            $rows[] = [
                'pet_expedition_id' => $expedition->id,
                'sequence' => $sequence,
                'scheduled_at' => $startedAt->clone()->addMinutes($offsetMinutes),
                'kind' => ($isEvent ? CheckpointKind::Event : CheckpointKind::Narrative)->value,
                'status' => CheckpointStatus::Pending->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        PetExpeditionCheckpoint::insert($rows);
    }

    private function hasMechanicalEventsAvailable(ExpeditionDefinition $definition): bool
    {
        return ExpeditionEventDefinition::query()
            ->whereIn('type', self::MECHANICAL_TYPES)
            ->where('is_active', true)
            ->where(function ($query) use ($definition) {
                $query->whereNull('expedition_definition_id')->orWhere('expedition_definition_id', $definition->id);
            })
            ->exists();
    }

    // Server-authoritative: resuelve TODOS los checkpoints vencidos de
    // esta expedición, en orden, en una sola llamada -mismo mecanismo
    // exacto cubre doble-click/dos pestañas/catch-up offline (no importa
    // si pasaron 5 minutos o 3 horas, el bucle resuelve todos los
    // vencidos). Un solo lock por operación, nunca uno por checkpoint
    // (docs/PETS_EXPEDITIONS_SYSTEM.md §12.1). F22: si un checkpoint pasa
    // a awaiting_decision, el bucle SE DETIENE ahí -los checkpoints
    // siguientes quedan pending intactos hasta que decide() los desbloquee.
    public function resolveDueCheckpoints(PetExpedition $expedition): PetExpedition
    {
        if ($expedition->status !== PetExpeditionStatus::Active) {
            return $expedition;
        }

        return DB::transaction(function () use ($expedition) {
            $locked = PetExpedition::whereKey($expedition->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PetExpeditionStatus::Active) {
                return $locked;
            }

            $due = $locked->checkpoints()
                ->where('status', CheckpointStatus::Pending->value)
                ->where('scheduled_at', '<=', now())
                ->orderBy('sequence')
                ->get();

            $this->resolveInOrder($locked, $due);

            if ($locked->fresh()->isReadyToComplete()) {
                $this->completeExpedition($locked);
            }

            return $locked->fresh();
        });
    }

    // Compartido por resolveDueCheckpoints() y decide() (tras aplicar una
    // decisión, el catch-up continúa exactamente por acá) — resuelve en
    // orden hasta agotar la lista o hasta que un checkpoint quede
    // awaiting_decision, lo que pase primero.
    private function resolveInOrder(PetExpedition $expedition, iterable $checkpoints): void
    {
        foreach ($checkpoints as $checkpoint) {
            $continue = $this->resolveCheckpoint($expedition, $checkpoint);
            if (! $continue) {
                return;
            }
        }
    }

    // Resolución de UN checkpoint: elige el evento concreto recién acá
    // (nunca antes), guarda el resultado en payload. Devuelve false si el
    // checkpoint quedó awaiting_decision (el caller debe detener el
    // catch-up ahí), true si se resolvió y el catch-up puede continuar.
    private function resolveCheckpoint(PetExpedition $expedition, PetExpeditionCheckpoint $checkpoint): bool
    {
        if ($checkpoint->kind === CheckpointKind::Narrative) {
            $event = $this->pickEvent($expedition, [ExpeditionEventType::Narrative->value]);

            $checkpoint->event_definition_id = $event?->id;
            $checkpoint->status = CheckpointStatus::Resolved;
            $checkpoint->resolved_at = now();
            $checkpoint->payload = ['text' => $event?->text ?? 'El camino sigue tranquilo.'];
            $checkpoint->save();

            return true;
        }

        // kind === Event
        $event = $this->pickEvent($expedition, self::MECHANICAL_TYPES);

        if (! $event) {
            // Planificado como event pero el catálogo mecánico quedó sin
            // contenido disponible entre la planificación y la resolución
            // (p.ej. se desactivó) -degradación segura, nunca un
            // checkpoint sin resolver ni una excepción.
            $checkpoint->status = CheckpointStatus::Resolved;
            $checkpoint->resolved_at = now();
            $checkpoint->payload = ['text' => 'El camino sigue tranquilo.'];
            $checkpoint->save();

            return true;
        }

        $checkpoint->event_definition_id = $event->id;

        $requiresDecision = (bool) ($event->config_json['requires_decision'] ?? false);

        if ($requiresDecision) {
            $checkpoint->status = CheckpointStatus::AwaitingDecision;
            $checkpoint->save();

            return false;
        }

        $outcome = $this->resolveEventOutcome($expedition, $checkpoint, $event, null);
        $checkpoint->status = CheckpointStatus::Resolved;
        $checkpoint->resolved_at = now();
        $checkpoint->payload = $outcome;
        $checkpoint->save();

        return true;
    }

    // F22: aplica la decisión del jugador sobre un checkpoint
    // awaiting_decision. Mismo lock que el resto del motor -EXPEDICIÓN
    // primero, CHECKPOINT después- para mantener el mismo orden de locks
    // que resolveDueCheckpoints()/claim() y evitar deadlocks bajo
    // concurrencia real entre distintos endpoints. Re-verifica el status
    // DENTRO de la transacción antes de aplicar -si ya no está
    // awaiting_decision (carrera perdida contra otra decide() o un catch-up
    // concurrente), responde el resultado ya existente sin volver a tirar
    // dados, idempotente (§12.1). Tras aplicar, continúa resolviendo lo que
    // siga vencido de la misma expedición, deteniéndose de nuevo si aparece
    // otro awaiting_decision -el motor soporta N decisiones por expedición
    // aunque el contenido actual normalmente tenga como mucho una.
    public function decide(PetExpeditionCheckpoint $checkpoint, string $decision): PetExpeditionCheckpoint
    {
        return DB::transaction(function () use ($checkpoint, $decision) {
            $expedition = PetExpedition::whereKey($checkpoint->pet_expedition_id)->lockForUpdate()->firstOrFail();
            $locked = PetExpeditionCheckpoint::whereKey($checkpoint->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CheckpointStatus::AwaitingDecision) {
                return $locked;
            }

            $event = $locked->eventDefinition;
            $outcome = $this->resolveEventOutcome($expedition, $locked, $event, $decision);

            $locked->decision = $decision;
            $locked->status = CheckpointStatus::Resolved;
            $locked->resolved_at = now();
            $locked->payload = $outcome;
            $locked->save();

            $remaining = $expedition->checkpoints()
                ->where('status', CheckpointStatus::Pending->value)
                ->where('scheduled_at', '<=', now())
                ->where('sequence', '>', $locked->sequence)
                ->orderBy('sequence')
                ->get();

            $this->resolveInOrder($expedition, $remaining);

            if ($expedition->fresh()->isReadyToComplete()) {
                $this->completeExpedition($expedition->fresh());
            }

            return $locked->fresh();
        });
    }

    // Interpreta el `type` de UN evento mecánico ya elegido y produce su
    // resultado concreto -nunca la key del destino, solo `type`+
    // `config_json`-. $decision es null en auto-resolución (chest/help sin
    // requires_decision) o el valor que mandó el jugador vía decide().
    private function resolveEventOutcome(
        PetExpedition $expedition,
        PetExpeditionCheckpoint $checkpoint,
        ExpeditionEventDefinition $event,
        ?string $decision
    ): array {
        return match ($event->type) {
            ExpeditionEventType::Enemy => $this->resolveEnemyOutcome($expedition, $checkpoint, $event, $decision),
            ExpeditionEventType::Chest => $this->resolveChestOutcome($expedition, $checkpoint, $event, $decision),
            ExpeditionEventType::Help => $this->resolveHelpOutcome($expedition, $checkpoint, $event, $decision),
            default => ['outcome' => 'nothing', 'decision' => $decision, 'damage' => 0, 'loot' => []],
        };
    }

    // enemy: decide(flee) evita todo -sin riesgo, sin recompensa-.
    // Cualquier otro caso (decide(fight) o auto-resolución sin decisión)
    // tira win_chance_base -victoria concede loot extra, derrota aplica
    // daño inmediato a pet.health-.
    private function resolveEnemyOutcome(
        PetExpedition $expedition,
        PetExpeditionCheckpoint $checkpoint,
        ExpeditionEventDefinition $event,
        ?string $decision
    ): array {
        $config = $event->config_json ?? [];

        if ($decision === 'flee') {
            return ['outcome' => 'fled', 'decision' => $decision, 'damage' => 0, 'loot' => []];
        }

        $winChance = (int) ($config['win_chance_base'] ?? 50);
        $won = random_int(1, 100) <= $winChance;

        if ($won) {
            $multiplier = (float) ($config['loot_on_win_multiplier'] ?? 1.0);
            $loot = $this->rollEventLoot($expedition, $checkpoint, $multiplier);

            return ['outcome' => 'win', 'decision' => $decision, 'damage' => 0, 'loot' => $loot];
        }

        $damage = $this->rollDamage($config['damage_on_loss'] ?? self::DEFAULT_DAMAGE_RANGE);
        $this->applyDamage($expedition->pet, $damage);

        return ['outcome' => 'defeat', 'decision' => $decision, 'damage' => $damage, 'loot' => []];
    }

    // chest: decide(leave) lo deja intacto. Si no, tira open_odds
    // (loot/trap/nothing, pesos data-driven vía config_json).
    private function resolveChestOutcome(
        PetExpedition $expedition,
        PetExpeditionCheckpoint $checkpoint,
        ExpeditionEventDefinition $event,
        ?string $decision
    ): array {
        $config = $event->config_json ?? [];

        if ($decision === 'leave') {
            return ['outcome' => 'left', 'decision' => $decision, 'damage' => 0, 'loot' => []];
        }

        $odds = $config['open_odds'] ?? ['loot' => 70, 'trap' => 15, 'nothing' => 15];
        $roll = $this->weightedOutcome($odds);

        if ($roll === 'loot') {
            $multiplier = (float) ($config['loot_multiplier'] ?? 1.0);
            $loot = $this->rollEventLoot($expedition, $checkpoint, $multiplier);

            return ['outcome' => 'loot', 'decision' => $decision, 'damage' => 0, 'loot' => $loot];
        }

        if ($roll === 'trap') {
            $damage = $this->rollDamage($config['trap_damage'] ?? self::DEFAULT_DAMAGE_RANGE);
            $this->applyDamage($expedition->pet, $damage);

            return ['outcome' => 'trap', 'decision' => $decision, 'damage' => $damage, 'loot' => []];
        }

        return ['outcome' => 'nothing', 'decision' => $decision, 'damage' => 0, 'loot' => []];
    }

    // help: decide(ignore) lo deja pasar. Si no, tira success_chance -éxito
    // concede loot extra, fallo aplica daño inmediato-.
    private function resolveHelpOutcome(
        PetExpedition $expedition,
        PetExpeditionCheckpoint $checkpoint,
        ExpeditionEventDefinition $event,
        ?string $decision
    ): array {
        $config = $event->config_json ?? [];

        if ($decision === 'ignore') {
            return ['outcome' => 'ignored', 'decision' => $decision, 'damage' => 0, 'loot' => []];
        }

        $successChance = (int) ($config['success_chance'] ?? 50);
        $succeeded = random_int(1, 100) <= $successChance;

        if ($succeeded) {
            $loot = $this->rollEventLoot($expedition, $checkpoint, 1.0);

            return ['outcome' => 'success', 'decision' => $decision, 'damage' => 0, 'loot' => $loot];
        }

        $damage = $this->rollDamage($config['damage_on_failure'] ?? self::DEFAULT_DAMAGE_RANGE);
        $this->applyDamage($expedition->pet, $damage);

        return ['outcome' => 'failure', 'decision' => $decision, 'damage' => $damage, 'loot' => []];
    }

    private function rollDamage(array $range): int
    {
        return random_int((int) ($range[0] ?? self::DEFAULT_DAMAGE_RANGE[0]), (int) ($range[1] ?? self::DEFAULT_DAMAGE_RANGE[1]));
    }

    private function applyDamage(Pet $pet, int $damage): void
    {
        $pet->health = max(1, min($pet->max_health, $pet->health - $damage));
        $pet->save();
    }

    // F22 (procedencia del loot, pedido explícito): el loot de un evento
    // NUNCA viene de una tabla paralela -se tira contra la MISMA
    // expedition_rewards que usa el roll final de completeExpedition(), un
    // único pick ponderado con la cantidad escalada por el multiplicador
    // del evento. Queda registrado en DOS lugares: en el payload del
    // checkpoint (historial/replay de ESE evento puntual) y en
    // result_data_json.event_loot de la expedición (para que claim() lo
    // conceda junto con expedition_loot sin perder de dónde salió cada
    // entrada — ver appendEventLoot()).
    private function rollEventLoot(PetExpedition $expedition, PetExpeditionCheckpoint $checkpoint, float $multiplier): array
    {
        $pool = $expedition->expeditionDefinition->rewards()->with('item')->get();
        if ($pool->isEmpty()) {
            return [];
        }

        $totalWeight = $pool->sum('weight');
        if ($totalWeight <= 0) {
            return [];
        }

        $roll = random_int(1, $totalWeight);
        $cumulative = 0;
        $picked = null;
        foreach ($pool as $reward) {
            $cumulative += $reward->weight;
            if ($roll <= $cumulative) {
                $picked = $reward;
                break;
            }
        }

        if (! $picked) {
            return [];
        }

        $quantity = max(1, (int) round(random_int($picked->min_qty, $picked->max_qty) * $multiplier));
        $entry = ['item_key' => $picked->item->key, 'quantity' => $quantity];

        $this->appendEventLoot($expedition, $checkpoint->id, $entry);

        return [$entry];
    }

    private function appendEventLoot(PetExpedition $expedition, int $checkpointId, array $entry): void
    {
        $data = $expedition->result_data_json ?? [];
        $data['event_loot'] = $data['event_loot'] ?? [];
        $data['event_loot'][] = array_merge(['checkpoint_id' => $checkpointId], $entry);

        $expedition->result_data_json = $data;
        $expedition->save();
    }

    // F22: procedencia preservada — expedition_loot (roll final, ya
    // existía en F21) y event_loot (acumulado por checkpoints, si los
    // hubo) quedan como claves separadas dentro de result_data_json, nunca
    // fusionadas en una sola lista sin origen. claim() concede ambas.
    private function completeExpedition(PetExpedition $expedition): void
    {
        $expeditionLoot = $this->rollRewards($expedition->expeditionDefinition);

        $data = $expedition->result_data_json ?? [];
        $data['expedition_loot'] = $expeditionLoot;
        $data['event_loot'] = $data['event_loot'] ?? [];

        $expedition->status = PetExpeditionStatus::Completed;
        $expedition->resolved_at = now();
        $expedition->result_data_json = $data;
        $expedition->save();

        $this->activity->log($expedition->pet->character, 'expedition_completed', [
            'expedition_name' => $expedition->expeditionDefinition->name,
        ]);
    }

    // Fix real del bug de doble-claim (docs/PETS_EXPEDITIONS_SYSTEM.md
    // §3.4): lockForUpdate() + re-verificación de status DENTRO de la
    // transacción, mismo patrón que resolveDueCheckpoints(). Dos requests
    // simultáneos que ambos pasan el chequeo del controller antes de que
    // cualquiera marque Claimed: el segundo espera el lock, y cuando lo
    // obtiene ya no es Completed -devuelve el loot ya registrado sin
    // volver a concederlo. F22: concede expedition_loot + event_loot
    // juntos -misma transacción, mismo criterio "todo o nada" que ya
    // tenía F21-.
    public function claim(PetExpedition $expedition): array
    {
        return DB::transaction(function () use ($expedition) {
            $locked = PetExpedition::whereKey($expedition->id)->lockForUpdate()->firstOrFail();

            $loot = $this->combinedLoot($locked);

            if ($locked->status !== PetExpeditionStatus::Completed) {
                return $loot;
            }

            $character = $locked->pet->character;

            foreach ($loot as $entry) {
                $item = Item::where('key', $entry['item_key'])->first();
                if (! $item) {
                    continue; // catálogo cambiado después de rolear -no debería pasar, no revienta el claim-.
                }
                $this->grantService->grant($character, $item, (int) $entry['quantity']);
            }

            $locked->status = PetExpeditionStatus::Claimed;
            $locked->save();

            $pet = $locked->pet;
            $pet->status = PetStatus::Idle;
            $pet->save();

            $this->activity->log($character, 'expedition_claimed', [
                'expedition_name' => $locked->expeditionDefinition->name,
                'loot' => $loot,
            ]);

            return $loot;
        });
    }

    // Unión de expedition_loot + event_loot para conceder/mostrar como
    // "loot total" -la procedencia de cada entrada sigue disponible por
    // separado en result_data_json (ver completeExpedition/appendEventLoot)
    // y en el payload de cada checkpoint individual, esto solo combina
    // cantidades del MISMO item_key para no otorgar N filas de inventario
    // sueltas por el mismo item.
    private function combinedLoot(PetExpedition $expedition): array
    {
        $data = $expedition->result_data_json ?? [];
        $expeditionLoot = $data['expedition_loot'] ?? ($data['loot'] ?? []);
        $eventLoot = $data['event_loot'] ?? [];

        $totals = [];
        foreach (array_merge($expeditionLoot, $eventLoot) as $entry) {
            $key = $entry['item_key'];
            $totals[$key] = ($totals[$key] ?? 0) + (int) $entry['quantity'];
        }

        return array_map(
            fn ($key, $quantity) => ['item_key' => $key, 'quantity' => $quantity],
            array_keys($totals),
            array_values($totals)
        );
    }

    // Loot table normalizada (expedition_rewards), reemplaza el
    // "elegí 2 al azar, uniforme" del sistema anterior por selección
    // ponderada sin reemplazo -mismo REWARD_ITEM_COUNT base, ver nota de
    // la clase sobre balance real quedando para F23.
    private function rollRewards(ExpeditionDefinition $definition): array
    {
        $pool = $definition->rewards()->with('item')->get()->all();
        if (empty($pool)) {
            return [];
        }

        $itemCount = min(self::REWARD_ITEM_COUNT, count($pool));
        $selected = [];

        for ($i = 0; $i < $itemCount; $i++) {
            $totalWeight = array_sum(array_map(fn ($reward) => $reward->weight, $pool));
            if ($totalWeight <= 0) {
                break;
            }

            $roll = random_int(1, $totalWeight);
            $cumulative = 0;

            foreach ($pool as $index => $reward) {
                $cumulative += $reward->weight;
                if ($roll <= $cumulative) {
                    $selected[] = $reward;
                    unset($pool[$index]);
                    $pool = array_values($pool);
                    break;
                }
            }
        }

        return array_map(fn ($reward) => [
            'item_key' => $reward->item->key,
            'quantity' => random_int($reward->min_qty, $reward->max_qty),
        ], $selected);
    }

    // Mismo algoritmo exacto que PetExpeditionService::jitteredOffsets
    // (F7.1) -reparte $count checkpoints en [0, $totalMinutes] con jitter
    // dentro de franjas iguales, para que se sientan orgánicos sin caer
    // pegados ni amontonarse al final.
    private function jitteredOffsets(int $totalMinutes, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $slot = $totalMinutes / $count;
        $margin = $slot * 0.15;

        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $slotStart = $i * $slot;
            $slotEnd = ($i + 1) * $slot;

            $low = (int) round($slotStart + $margin);
            $high = (int) round(max($low, $slotEnd - $margin));

            $offsets[] = random_int($low, $high);
        }

        return $offsets;
    }

    // Generaliza el antiguo pickNarrativeEvent (F7.1/F21) a cualquier
    // conjunto de `type` -narrative para checkpoints narrativos, chest/
    // enemy/help para checkpoints mecánicos-. Mismo algoritmo exacto: tira
    // la rareza primero (probabilidad fija), degrada hacia "común" si esa
    // rareza no tiene candidatos, evita los últimos 3 event_definition_id
    // ya resueltos EN ESTA EXPEDICIÓN (cualquier tipo, no solo narrativos —
    // un jugador no debería ver "otra vez el mismo cofre" inmediatamente
    // después tampoco).
    private function pickEvent(PetExpedition $expedition, array $types): ?ExpeditionEventDefinition
    {
        $definition = $expedition->expeditionDefinition;

        $candidatesByRarity = ExpeditionEventDefinition::query()
            ->whereIn('type', $types)
            ->where('is_active', true)
            ->where(function ($query) use ($definition) {
                $query->whereNull('expedition_definition_id')->orWhere('expedition_definition_id', $definition->id);
            })
            ->get()
            ->groupBy(fn (ExpeditionEventDefinition $event) => $event->rarity->value);

        if ($candidatesByRarity->isEmpty()) {
            return null;
        }

        // reorder() -no orderByDesc()- a propósito: PetExpedition::checkpoints()
        // ya aplica ->orderBy('sequence') ascendente; encadenar orderByDesc()
        // encima SUMA una segunda cláusula en vez de reemplazarla. Bug real
        // encontrado en F21 por el test de anti-repetición.
        $recentIds = $expedition->checkpoints()
            ->where('status', CheckpointStatus::Resolved->value)
            ->whereNotNull('event_definition_id')
            ->reorder('sequence', 'desc')
            ->limit(3)
            ->pluck('event_definition_id')
            ->all();

        $rarityOrder = [
            PetNarrativeRarity::VeryRare->value,
            PetNarrativeRarity::Rare->value,
            PetNarrativeRarity::Uncommon->value,
            PetNarrativeRarity::Common->value,
        ];

        $roll = random_int(1, 100);
        $rarity = match (true) {
            $roll <= 2 => PetNarrativeRarity::VeryRare->value,
            $roll <= 8 => PetNarrativeRarity::Rare->value,
            $roll <= 30 => PetNarrativeRarity::Uncommon->value,
            default => PetNarrativeRarity::Common->value,
        };

        $startIndex = array_search($rarity, $rarityOrder, true);
        $ordered = array_slice($rarityOrder, $startIndex);

        foreach ($ordered as $candidateRarity) {
            $pool = $candidatesByRarity->get($candidateRarity, collect());
            if ($pool->isEmpty()) {
                continue;
            }

            $fresh = $pool->reject(fn (ExpeditionEventDefinition $event) => in_array($event->id, $recentIds, true));
            if ($fresh->isNotEmpty()) {
                return $this->weightedRandom($fresh);
            }
        }

        $fallback = $candidatesByRarity->get($rarity, collect());

        return $fallback->isNotEmpty() ? $this->weightedRandom($fallback) : null;
    }

    private function weightedRandom($events): ?ExpeditionEventDefinition
    {
        if ($events->isEmpty()) {
            return null;
        }

        $totalWeight = $events->sum('weight');
        if ($totalWeight <= 0) {
            return $events->random();
        }

        $roll = random_int(1, $totalWeight);
        $cumulative = 0;
        foreach ($events as $event) {
            $cumulative += $event->weight;
            if ($roll <= $cumulative) {
                return $event;
            }
        }

        return $events->last();
    }

    // Weighted pick genérico sobre un mapa nombre=>peso (chest.open_odds:
    // {loot, trap, nothing}) -mismo algoritmo que weightedRandom() pero
    // sobre strings en vez de modelos, reutilizado por resolveChestOutcome.
    private function weightedOutcome(array $odds): string
    {
        $total = array_sum($odds);
        if ($total <= 0) {
            return array_key_first($odds) ?? 'nothing';
        }

        $roll = random_int(1, $total);
        $cumulative = 0;
        foreach ($odds as $key => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return $key;
            }
        }

        return array_key_last($odds);
    }
}
