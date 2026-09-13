<?php

namespace App\Services;

use App\Enums\PetExpeditionStatus;
use App\Enums\PetStatus;
use App\Models\Item;
use App\Models\Pet;
use App\Models\PetDestination;
use App\Models\PetExpedition;
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
}
