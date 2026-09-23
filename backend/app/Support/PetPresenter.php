<?php

namespace App\Support;

use App\Enums\CheckpointStatus;
use App\Models\ExpeditionDefinition;
use App\Models\ExpeditionReward;
use App\Models\Pet;
use App\Models\PetExpedition;
use App\Models\PetExpeditionCheckpoint;

// Fase 7: PetController y PetExpeditionController devuelven la misma
// forma de expedición -se centraliza acá en vez de duplicar el array en
// los dos controllers.
class PetPresenter
{
    public static function pet(Pet $pet, ?PetExpedition $expedition): array
    {
        $pet->loadMissing('species');

        return [
            'id' => $pet->id,
            'name' => $pet->name,
            'species' => $pet->species->key,
            'species_name' => $pet->species->name,
            'level' => $pet->level,
            'experience' => $pet->exp,
            'health' => $pet->health,
            'max_health' => $pet->max_health,
            'energy' => $pet->energy,
            'max_energy' => $pet->max_energy,
            'status' => $pet->status->value,
            'expedition' => $expedition ? self::expedition($expedition) : null,
        ];
    }

    // F21: reemplaza `events`/`narrative_log` inline (F7/F7.1, todo
    // precalculado en start()) por `checkpoints` -cada uno ya resuelto
    // (con payload) o todavía no (payload null, incluso si ya está
    // awaiting_decision: el jugador ve QUE hay que decidir, no un
    // resultado que todavía no existe). `loot` sigue viniendo de
    // result_data_json -ahí queda como resumen/caché desde que la
    // expedición se completa, ver ExpeditionService::completeExpedition-.
    public static function expedition(PetExpedition $expedition): array
    {
        $expedition->loadMissing('expeditionDefinition', 'checkpoints');

        return [
            'id' => $expedition->id,
            'expedition' => $expedition->expeditionDefinition->key,
            'expedition_name' => $expedition->expeditionDefinition->name,
            'status' => $expedition->status->value,
            'started_at' => $expedition->started_at->toIso8601String(),
            'finishes_at' => $expedition->ends_at->toIso8601String(),
            'resolved_at' => $expedition->resolved_at?->toIso8601String(),
            'checkpoints' => $expedition->checkpoints->map(self::checkpoint(...))->all(),
            'loot' => $expedition->result_data_json['loot'] ?? [],
        ];
    }

    private static function checkpoint(PetExpeditionCheckpoint $checkpoint): array
    {
        return [
            'id' => $checkpoint->id,
            'sequence' => $checkpoint->sequence,
            'scheduled_at' => $checkpoint->scheduled_at->toIso8601String(),
            'kind' => $checkpoint->kind->value,
            'status' => $checkpoint->status->value,
            'payload' => $checkpoint->status === CheckpointStatus::Resolved ? $checkpoint->payload : null,
        ];
    }

    // F21: catálogo de expediciones -reward_preview ya viene con % real
    // calculado server-side (weight / SUM(weight) * 100), nunca se expone
    // el weight/rareza crudos de expedition_rewards tal cual.
    public static function expeditionDefinition(ExpeditionDefinition $definition): array
    {
        $definition->loadMissing('rewards.item');

        return [
            'key' => $definition->key,
            'name' => $definition->name,
            'description' => $definition->description,
            'difficulty' => $definition->difficulty,
            'duration_seconds' => $definition->duration_seconds,
            'min_pet_level' => $definition->min_pet_level,
            'reward_preview' => self::rewardPreview($definition),
        ];
    }

    private static function rewardPreview(ExpeditionDefinition $definition): array
    {
        $totalWeight = $definition->rewards->sum('weight');
        if ($totalWeight <= 0) {
            return [];
        }

        return $definition->rewards
            ->map(fn (ExpeditionReward $reward) => [
                'item_name' => $reward->item->name,
                'percent' => round($reward->weight / $totalWeight * 100, 1),
                'rarity_tier' => $reward->rarity_tier?->value,
            ])
            ->all();
    }
}
