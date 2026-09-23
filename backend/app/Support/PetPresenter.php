<?php

namespace App\Support;

use App\Enums\CheckpointStatus;
use App\Models\ExpeditionDefinition;
use App\Models\ExpeditionReward;
use App\Models\Pet;
use App\Models\PetExpedition;
use App\Models\PetExpeditionCheckpoint;
use App\Models\PetSpecies;

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
            'sprite' => $pet->species->sprite_meta_json,
            'expedition' => $expedition ? self::expedition($expedition) : null,
        ];
    }

    // F23: catálogo de especies para el flujo de adopción/colección.
    // `owned` se calcula server-side (nunca confiado del cliente) -ver
    // PetController::species()-. Sigue sin exponer modifiers_json/
    // level_modifiers_json crudo.
    public static function species(PetSpecies $species, bool $owned): array
    {
        return [
            'key' => $species->key,
            'name' => $species->name,
            'description' => $species->description,
            'rarity' => $species->rarity->value,
            'sprite' => $species->sprite_meta_json,
            'is_starter_option' => $species->is_starter_option,
            'adoption_price' => $species->adoption_price,
            'owned' => $owned,
        ];
    }

    // F23: fila resumida para "mis mascotas" (colección) -no trae
    // expedición/checkpoints, eso solo importa para la mascota ACTIVA
    // (ver pet()).
    public static function petSummary(Pet $pet): array
    {
        $pet->loadMissing('species');

        return [
            'id' => $pet->id,
            'name' => $pet->name,
            'species' => $pet->species->key,
            'species_name' => $pet->species->name,
            'level' => $pet->level,
            'status' => $pet->status->value,
            'sprite' => $pet->species->sprite_meta_json,
        ];
    }

    // F21/F22: `checkpoints` -cada uno ya resuelto (con payload) o
    // todavía no (payload null, incluso si ya está awaiting_decision: acá
    // se expone por separado en `event` la info que el jugador necesita
    // para decidir, nunca en `payload` -eso sigue siendo estrictamente "el
    // resultado ya calculado", nunca un adelanto-). `loot` es el total
    // combinado (expedition_loot + event_loot) para no romper el contrato
    // que ya consume el frontend; `expedition_loot`/`event_loot` quedan
    // expuestos aparte para no perder la procedencia (pedido explícito de
    // F22) — ver ExpeditionService::completeExpedition/appendEventLoot.
    public static function expedition(PetExpedition $expedition): array
    {
        $expedition->loadMissing('expeditionDefinition', 'checkpoints.eventDefinition');

        $data = $expedition->result_data_json ?? [];
        $expeditionLoot = $data['expedition_loot'] ?? ($data['loot'] ?? []);
        $eventLoot = $data['event_loot'] ?? [];

        return [
            'id' => $expedition->id,
            'expedition' => $expedition->expeditionDefinition->key,
            'expedition_name' => $expedition->expeditionDefinition->name,
            'status' => $expedition->status->value,
            'started_at' => $expedition->started_at->toIso8601String(),
            'finishes_at' => $expedition->ends_at->toIso8601String(),
            'resolved_at' => $expedition->resolved_at?->toIso8601String(),
            'checkpoints' => $expedition->checkpoints->map(self::checkpoint(...))->all(),
            'loot' => self::mergeLoot($expeditionLoot, $eventLoot),
            'expedition_loot' => $expeditionLoot,
            'event_loot' => $eventLoot,
        ];
    }

    private static function mergeLoot(array $expeditionLoot, array $eventLoot): array
    {
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

    private static function checkpoint(PetExpeditionCheckpoint $checkpoint): array
    {
        return [
            'id' => $checkpoint->id,
            'sequence' => $checkpoint->sequence,
            'scheduled_at' => $checkpoint->scheduled_at->toIso8601String(),
            'kind' => $checkpoint->kind->value,
            'status' => $checkpoint->status->value,
            'payload' => $checkpoint->status === CheckpointStatus::Resolved ? $checkpoint->payload : null,
            'event' => self::checkpointEvent($checkpoint),
        ];
    }

    // F22: solo se completa cuando el jugador necesita decidir -título/
    // texto/opciones salen del catálogo (expedition_event_definitions),
    // nunca inventados acá. Fuera de awaiting_decision queda null: no hay
    // nada que decidir, no hay nada que mostrar de antemano.
    private static function checkpointEvent(PetExpeditionCheckpoint $checkpoint): ?array
    {
        if ($checkpoint->status !== CheckpointStatus::AwaitingDecision || ! $checkpoint->eventDefinition) {
            return null;
        }

        $event = $checkpoint->eventDefinition;

        return [
            'title' => $event->title,
            'text' => $event->text,
            'options' => $event->config_json['options'] ?? [],
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

    // F22 (UX destinos): ordenado de mayor a menor probabilidad -el
    // frontend ya no reordena, solo pinta tal cual llega-. Empate
    // determinista por `id` de la recompensa (orden de siembra), nunca por
    // orden de iteración incidental de la colección.
    private static function rewardPreview(ExpeditionDefinition $definition): array
    {
        $totalWeight = $definition->rewards->sum('weight');
        if ($totalWeight <= 0) {
            return [];
        }

        return $definition->rewards
            ->sortBy([
                fn (ExpeditionReward $a, ExpeditionReward $b) => $b->weight <=> $a->weight,
                fn (ExpeditionReward $a, ExpeditionReward $b) => $a->id <=> $b->id,
            ])
            ->map(fn (ExpeditionReward $reward) => [
                'item_name' => $reward->item->name,
                'percent' => round($reward->weight / $totalWeight * 100, 1),
                'rarity_tier' => $reward->rarity_tier?->value,
            ])
            ->values()
            ->all();
    }
}
