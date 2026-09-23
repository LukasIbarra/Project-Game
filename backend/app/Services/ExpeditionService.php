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

// F21 (docs/PETS_EXPEDITIONS_SYSTEM.md §7/§8): reemplaza PetExpeditionService.
// Diseño clave -lo opuesto al sistema anterior-: start() NUNCA rollea
// eventos/loot, solo PLANIFICA (cuántos checkpoints, cuándo, de qué kind).
// resolveDueCheckpoints() es quien RESUELVE cada checkpoint vencido en su
// momento real (elige el evento concreto recién ahí) — mismo principio de
// resolución perezosa que combat_logs (CLAUDE.md #6), pero aplicado de
// verdad al cálculo, no solo a la aplicación de un resultado ya fijado.
//
// Separación estricta (CLAUDE.md metodología + diseño §8):
//   Planificación  -> start()
//   Resolución     -> resolveDueCheckpoints() (elige QUÉ pasa en cada
//                      checkpoint vencido, calcula, guarda payload)
//   Aplicación     -> implícita en resolveDueCheckpoints() para narrative
//                      (no hay consecuencia sobre pet.health en F21 -ver
//                      nota de alcance abajo-) y en completeExpedition()
//                      para el loot final
//   Historial      -> ActivityLogger (nunca decide ni aplica nada)
//
// Alcance F21 (explícito, no accidental): los checkpoints son 100%
// kind=narrative. El sistema mecánico anterior (config/pet_events.php:
// daño/retraso/loot-bonus aleatorio) queda retirado -sus sucesores reales
// son los checkpoints kind=event con consecuencia mecánica, que son F22
// (Fase 21 del roadmap maestro, ver ROADMAP.md). Por eso acá: ends_at NO
// se ajusta por eventos (se fija una sola vez en start(), sin retraso
// posible todavía) y pet.health no se modifica por la expedición en sí.
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
    // bonus de evento, que F21 ya no tiene). El balance fino por
    // duración/dificultad es F23 (docs/PETS_EXPEDITIONS_SYSTEM.md §10),
    // no se implementa acá.
    private const REWARD_ITEM_COUNT = 2;

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

        $this->planCheckpoints($expedition, $startedAt, $endsAt);

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
    // -nunca elige el evento concreto ni calcula ningún resultado. Todos
    // narrative en F21 (ver nota de alcance de la clase).
    private function planCheckpoints(PetExpedition $expedition, Carbon $startedAt, Carbon $endsAt): void
    {
        $totalMinutes = $startedAt->diffInMinutes($endsAt, absolute: true);
        $count = (int) min(
            self::MAX_CHECKPOINTS,
            max(self::MIN_CHECKPOINTS, intdiv($totalMinutes, self::MINUTES_PER_CHECKPOINT))
        );

        $offsets = $this->jitteredOffsets($totalMinutes, $count);

        $rows = [];
        foreach ($offsets as $sequence => $offsetMinutes) {
            $rows[] = [
                'pet_expedition_id' => $expedition->id,
                'sequence' => $sequence,
                'scheduled_at' => $startedAt->clone()->addMinutes($offsetMinutes),
                'kind' => CheckpointKind::Narrative->value,
                'status' => CheckpointStatus::Pending->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        PetExpeditionCheckpoint::insert($rows);
    }

    // Server-authoritative: resuelve TODOS los checkpoints vencidos de
    // esta expedición, en orden, en una sola llamada -mismo mecanismo
    // exacto cubre doble-click/dos pestañas/catch-up offline (no importa
    // si pasaron 5 minutos o 3 horas, el bucle resuelve todos los
    // vencidos). Un solo lock por operación, nunca uno por checkpoint
    // (docs/PETS_EXPEDITIONS_SYSTEM.md §12.1).
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

            foreach ($due as $checkpoint) {
                $this->resolveCheckpoint($locked, $checkpoint);
            }

            if ($locked->fresh()->isReadyToComplete()) {
                $this->completeExpedition($locked);
            }

            return $locked->fresh();
        });
    }

    // Resolución de UN checkpoint: elige el evento concreto recién acá
    // (nunca antes), guarda el resultado en payload. F21: kind=narrative
    // siempre auto-resuelve -no hay ningún type de evento con
    // requires_decision real todavía (eso es F22), así que nunca queda en
    // awaiting_decision.
    private function resolveCheckpoint(PetExpedition $expedition, PetExpeditionCheckpoint $checkpoint): void
    {
        $event = $this->pickNarrativeEvent($expedition);

        $checkpoint->event_definition_id = $event?->id;
        $checkpoint->status = CheckpointStatus::Resolved;
        $checkpoint->resolved_at = now();
        $checkpoint->payload = ['text' => $event?->text ?? 'El camino sigue tranquilo.'];
        $checkpoint->save();
    }

    private function completeExpedition(PetExpedition $expedition): void
    {
        $loot = $this->rollRewards($expedition->expeditionDefinition);

        $expedition->status = PetExpeditionStatus::Completed;
        $expedition->resolved_at = now();
        $expedition->result_data_json = ['loot' => $loot];
        $expedition->save();

        $this->activity->log($expedition->pet->character, 'expedition_completed', [
            'expedition_name' => $expedition->expeditionDefinition->name,
        ]);
    }

    // Para checkpoints kind=event que requieran decisión (F22) -acá queda
    // el locking/idempotencia real y probado (dos decide() simultáneos
    // sobre el mismo checkpoint conceden una sola resolución), aunque F21
    // no genera ningún checkpoint en awaiting_decision de forma natural
    // todavía (ver ExpeditionEventType, solo existe Narrative).
    public function decide(PetExpeditionCheckpoint $checkpoint, string $decision): PetExpeditionCheckpoint
    {
        return DB::transaction(function () use ($checkpoint, $decision) {
            $locked = PetExpeditionCheckpoint::whereKey($checkpoint->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CheckpointStatus::AwaitingDecision) {
                // Ya resuelto (carrera perdida) o nunca estuvo en ese
                // estado -responde el estado actual sin volver a tirar
                // dados, idempotente (§12.1).
                return $locked;
            }

            $locked->decision = $decision;
            $locked->status = CheckpointStatus::Resolved;
            $locked->resolved_at = now();
            $locked->payload = ['decision' => $decision];
            $locked->save();

            return $locked;
        });
    }

    // Fix real del bug de doble-claim (docs/PETS_EXPEDITIONS_SYSTEM.md
    // §3.4): lockForUpdate() + re-verificación de status DENTRO de la
    // transacción, mismo patrón que resolveDueCheckpoints(). Dos requests
    // simultáneos que ambos pasan el chequeo del controller antes de que
    // cualquiera marque Claimed: el segundo espera el lock, y cuando lo
    // obtiene ya no es Completed -devuelve el loot ya registrado sin
    // volver a concederlo.
    public function claim(PetExpedition $expedition): array
    {
        return DB::transaction(function () use ($expedition) {
            $locked = PetExpedition::whereKey($expedition->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PetExpeditionStatus::Completed) {
                return $locked->result_data_json['loot'] ?? [];
            }

            $character = $locked->pet->character;
            $loot = $locked->result_data_json['loot'] ?? [];

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

    // Mismo algoritmo exacto que PetExpeditionService::pickNarrativeEvent
    // (F7.1): tira la rareza primero (probabilidad fija), degrada hacia
    // "común" si esa rareza no tiene candidatos, evita los últimos 3
    // event_definition_id ya resueltos EN ESTA EXPEDICIÓN (consultado
    // desde DB, no en memoria -a diferencia de F7.1, acá los checkpoints
    // se resuelven de a uno a través de múltiples llamadas, no todos
    // juntos en un solo start()-).
    private function pickNarrativeEvent(PetExpedition $expedition): ?ExpeditionEventDefinition
    {
        $definition = $expedition->expeditionDefinition;

        $candidatesByRarity = ExpeditionEventDefinition::query()
            ->where('type', ExpeditionEventType::Narrative->value)
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
        // encima SUMA una segunda cláusula en vez de reemplazarla
        // (`ORDER BY sequence ASC, sequence DESC`, que en una columna única
        // no tiene efecto real -el ASC ya la deja sin empates que
        // desambiguar-). Bug real encontrado por el test de anti-repetición
        // -sin reorder(), "los últimos 3" eran en realidad "los primeros 3
        // para siempre", así que la exclusión dejaba de reflejar
        // recencia real después del tercer checkpoint.
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

        // Degrada por TODAS las rarezas buscando un candidato no-reciente
        // antes de resignarse a repetir uno -si el nivel de rareza elegido
        // tiene muy pocos candidatos (el pool universal real de "rare"/
        // "very_rare" tiene un solo evento cada uno), caer directo a
        // "cualquiera de este mismo nivel" podía repetir el único
        // candidato reciente en el siguiente checkpoint (bug real,
        // encontrado por el test de anti-repetición, no a ojo).
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

        // Ningún nivel de rareza tenía un candidato no-reciente -catálogo
        // extremadamente chico-. Mejor repetir algo del nivel originalmente
        // sorteado que no mostrar nada.
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
}
