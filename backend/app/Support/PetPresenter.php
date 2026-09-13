<?php

namespace App\Support;

use App\Models\Pet;
use App\Models\PetExpedition;

// Fase 7: PetController y PetExpeditionController devuelven la misma
// forma de expedición -se centraliza acá en vez de duplicar el array en
// los dos controllers.
class PetPresenter
{
    public static function pet(Pet $pet, ?PetExpedition $expedition): array
    {
        return [
            'id' => $pet->id,
            'name' => $pet->name,
            'species' => $pet->key,
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

    public static function expedition(PetExpedition $expedition): array
    {
        $expedition->loadMissing('destination');

        return [
            'id' => $expedition->id,
            'destination' => $expedition->destination->key,
            'destination_name' => $expedition->destination->name,
            'status' => $expedition->status->value,
            'started_at' => $expedition->started_at->toIso8601String(),
            'finishes_at' => $expedition->ends_at->toIso8601String(),
            'resolved_at' => $expedition->resolved_at?->toIso8601String(),
            'events' => $expedition->result_data_json['events'] ?? [],
            'loot' => $expedition->result_data_json['loot'] ?? [],
        ];
    }
}
