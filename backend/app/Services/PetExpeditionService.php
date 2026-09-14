<?php

namespace App\Services;

use App\Enums\PetExpeditionStatus;
use App\Enums\PetNarrativeRarity;
use App\Enums\PetStatus;
use App\Models\Item;
use App\Models\Pet;
use App\Models\PetDestination;
use App\Models\PetExpedition;
use App\Models\PetNarrativeEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Fase 7. Diseño clave: TODO el resultado de una expedición (eventos,
// retraso, loot, delta de vida) se decide UNA VEZ, en start(), y se
// guarda en result_data_json junto con el ends_at ya ajustado -mismo
// principio que combat_logs (CLAUDE.md #6): el servidor calcula todo de
// una sola vez, lo demás es solo "reproducir"/revelar ese resultado ya
// fijado. Por eso resolveIfDue() nunca tiera dados: solo compara
// timestamps, así que consultarla 1 o 100 veces da el mismo resultado
// (idempotente) sin locks ni jobs (CLAUDE.md #2, resolución perezosa).
class PetExpeditionService
{
    public function __construct(private readonly InventoryGrantService $grantService)
    {
    }

    public function start(Pet $pet, PetDestination $destination): PetExpedition
    {
        $startedAt = now();
        $events = $this->rollEvents($destination);

        $delayMinutes = 0;
        $healthDelta = 0;
        $bonusItems = 0;
        $bonusQuantity = 0;

        foreach ($events as $event) {
            $delayMinutes += $event['delay_minutes'] ?? 0;
            $healthDelta += $event['health_delta'] ?? 0;
            $bonusItems += $event['loot_bonus_items'] ?? 0;
            $bonusQuantity += $event['loot_bonus_quantity'] ?? 0;
        }

        $endsAt = $startedAt->clone()->addMinutes($destination->duration_minutes + $delayMinutes);
        $loot = $this->rollLoot($destination, $bonusItems, $bonusQuantity);

        // F7.1: la duración FINAL (ya con cualquier retraso de evento
        // mecánico incluido) es la que se reparte entre eventos
        // narrativos -mismo principio que todo lo demás acá: se decide
        // una sola vez, en start(), nunca se recalcula después-.
        // absolute: true a propósito -Carbon 3 devuelve la diferencia con
        // signo según el orden de la llamada; un signo invertido acá
        // hacía que intdiv() diera negativo y el cupo de eventos cayera
        // siempre al piso de 3 (bug real, encontrado por los tests).
        $totalMinutes = $startedAt->diffInMinutes($endsAt, absolute: true);
        $narrativeLog = $this->scheduleNarrativeLog($destination, $startedAt, $totalMinutes);

        $expedition = PetExpedition::create([
            'pet_id' => $pet->id,
            'destination_id' => $destination->id,
            'status' => PetExpeditionStatus::Active,
            'started_at' => $startedAt,
            'ends_at' => $endsAt,
            'result_data_json' => [
                'events' => $events,
                'loot' => $loot,
                'health_delta' => $healthDelta,
                'narrative_log' => $narrativeLog,
            ],
        ]);

        $pet->status = PetStatus::Exploring;
        $pet->save();

        return $expedition;
    }

    // Server-authoritative: si ya pasó ends_at, la marca completed (y
    // aplica el delta de vida ya calculado) la primera vez que alguien la
    // consulta -consultarla de nuevo después no vuelve a aplicar nada
    // porque el status ya no es Active-.
    public function resolveIfDue(PetExpedition $expedition): PetExpedition
    {
        if (! $expedition->isDue()) {
            return $expedition;
        }

        return DB::transaction(function () use ($expedition) {
            $fresh = PetExpedition::whereKey($expedition->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== PetExpeditionStatus::Active || now()->lt($fresh->ends_at)) {
                return $fresh;
            }

            $healthDelta = (int) ($fresh->result_data_json['health_delta'] ?? 0);
            if ($healthDelta !== 0) {
                $pet = $fresh->pet;
                $pet->health = max(1, min($pet->max_health, $pet->health + $healthDelta));
                $pet->save();
            }

            $fresh->status = PetExpeditionStatus::Completed;
            $fresh->resolved_at = now();
            $fresh->save();

            return $fresh;
        });
    }

    // Asume que el caller ya verificó que $expedition->status ===
    // Completed (el controller decide el código HTTP para "todavía
    // activa" / "ya reclamada"; acá solo se hace la mutación).
    public function claim(PetExpedition $expedition): array
    {
        $character = $expedition->pet->character;
        $loot = $expedition->result_data_json['loot'] ?? [];

        DB::transaction(function () use ($expedition, $character, $loot) {
            foreach ($loot as $entry) {
                $item = Item::where('key', $entry['item_key'])->first();
                if (! $item) {
                    continue; // catálogo cambiado después de rolear -no debería pasar, no revienta el claim-.
                }
                $this->grantService->grant($character, $item, (int) $entry['quantity']);
            }

            $expedition->status = PetExpeditionStatus::Claimed;
            $expedition->save();

            $pet = $expedition->pet;
            $pet->status = PetStatus::Idle;
            $pet->save();
        });

        return $loot;
    }

    private function rollEvents(PetDestination $destination): array
    {
        $pool = config("pet_events.{$destination->key}", []);
        if (empty($pool)) {
            return [];
        }

        $count = random_int(0, min(2, count($pool)));
        if ($count === 0) {
            return [];
        }

        $shuffled = $pool;
        shuffle($shuffled);

        return array_slice($shuffled, 0, $count);
    }

    private function rollLoot(PetDestination $destination, int $bonusItems, int $bonusQuantity): array
    {
        $pool = $destination->loot_pool_json;
        if (empty($pool)) {
            return [];
        }

        $itemCount = min(2 + $bonusItems, count($pool));
        $shuffled = $pool;
        shuffle($shuffled);
        $selectedKeys = array_slice($shuffled, 0, $itemCount);

        $loot = [];
        foreach ($selectedKeys as $itemKey) {
            $loot[] = [
                'item_key' => $itemKey,
                'quantity' => random_int(3, 10) + $bonusQuantity,
            ];
        }

        return $loot;
    }

    // F7.1 — bitácora narrativa. Puramente cosmética: no toca health,
    // loot, ni ends_at (a diferencia de rollEvents/rollLoot de arriba).
    // Todo se decide acá, una sola vez, para la expedición completa;
    // "revelar" la bitácora después es solo filtrar por occurred_at <=
    // now() (ver PetPresenter::expedition), nunca volver a tirar dados.

    private const MAX_NARRATIVE_EVENTS = 20;

    private const MIN_NARRATIVE_EVENTS = 3;

    // Cuántos minutos de expedición "valen" un evento narrativo. Un
    // Bosque de 120min -> 10 eventos; un Castillo de 720min -> se cae en
    // el tope de 20 (no "50 mensajes" para una expedición de 12h, sección
    // 10 de la fase).
    private const MINUTES_PER_EVENT = 12;

    private function scheduleNarrativeLog(PetDestination $destination, Carbon $startedAt, int $totalMinutes): array
    {
        $count = (int) min(
            self::MAX_NARRATIVE_EVENTS,
            max(self::MIN_NARRATIVE_EVENTS, intdiv($totalMinutes, self::MINUTES_PER_EVENT))
        );

        $candidates = PetNarrativeEvent::query()
            ->where('is_active', true)
            ->where(function ($query) use ($destination) {
                $query->whereNull('destination_id')->orWhere('destination_id', $destination->id);
            })
            ->get()
            ->groupBy(fn (PetNarrativeEvent $event) => $event->rarity->value);

        if ($candidates->isEmpty()) {
            return [];
        }

        $offsets = $this->jitteredOffsets($totalMinutes, $count);

        $log = [];
        $recentIds = [];

        foreach ($offsets as $offsetMinutes) {
            $event = $this->pickNarrativeEvent($candidates, $recentIds);
            if (! $event) {
                continue; // catálogo demasiado chico para llenar el cupo -no rompe nada, solo hay menos entradas-.
            }

            $recentIds[] = $event->id;
            if (count($recentIds) > 3) {
                array_shift($recentIds);
            }

            $log[] = [
                'event_id' => $event->id,
                'category' => $event->category->value,
                'rarity' => $event->rarity->value,
                'text' => $event->text,
                'occurred_at' => $startedAt->clone()->addMinutes($offsetMinutes)->toIso8601String(),
            ];
        }

        return $log;
    }

    // Reparte $count eventos en [0, $totalMinutes] con jitter dentro de
    // franjas iguales -"orgánico" sin permitir que dos eventos caigan
    // pegados ni que todos se amontonen al final (sección 8 de la fase).
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

    // Tira la rareza primero (probabilidad fija, no depende de cuántas
    // filas haya en el catálogo por rareza) y recién después elige un
    // evento concreto dentro de esa rareza, evitando los últimos usados
    // en esta misma expedición (sección 11 de la fase). Si esa rareza no
    // tiene candidatos disponibles, degrada a la rareza más común
    // siguiente en vez de no mostrar nada.
    private function pickNarrativeEvent($candidatesByRarity, array $recentIds): ?PetNarrativeEvent
    {
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

        // Empieza en la rareza elegida y degrada hacia "común" si hace
        // falta -nunca hacia arriba, para no inflar accidentalmente algo
        // raro solo porque lo común ya se usó recientemente-.
        $startIndex = array_search($rarity, $rarityOrder, true);
        $ordered = array_slice($rarityOrder, $startIndex);

        foreach ($ordered as $candidateRarity) {
            $pool = $candidatesByRarity->get($candidateRarity, collect());
            if ($pool->isEmpty()) {
                continue;
            }

            $fresh = $pool->reject(fn (PetNarrativeEvent $event) => in_array($event->id, $recentIds, true));
            $chosen = $fresh->isNotEmpty() ? $fresh : $pool;

            return $this->weightedRandom($chosen);
        }

        return null;
    }

    private function weightedRandom($events): ?PetNarrativeEvent
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
