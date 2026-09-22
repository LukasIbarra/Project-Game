<?php

namespace App\Support;

use App\Enums\PetExpeditionStatus;
use App\Models\Pet;
use App\Models\PetExpedition;
use Illuminate\Support\Carbon;

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
            // Fase 20: antes era $pet->key (string suelto); ahora sale de
            // la relación real a pet_species. 'species' se mantiene con
            // el mismo nombre/forma que ya consumía el frontend (la
            // key técnica); 'species_name' es nuevo, para mostrar un
            // nombre amigable sin que el frontend tenga que resolverlo.
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
            'narrative_log' => self::visibleNarrativeLog($expedition),
        ];
    }

    // F7.1: la bitácora completa ya está decidida desde start() (ver
    // PetExpeditionService::scheduleNarrativeLog) — "revelarla" es solo
    // filtrar por lo que ya debería haber ocurrido según el reloj del
    // servidor, nunca recalcular nada. Una expedición ya Completed/
    // Claimed muestra la bitácora completa (todo "ya ocurrió").
    private static function visibleNarrativeLog(PetExpedition $expedition): array
    {
        $log = $expedition->result_data_json['narrative_log'] ?? [];

        if ($expedition->status !== PetExpeditionStatus::Active) {
            return $log;
        }

        $now = now();

        return array_values(array_filter(
            $log,
            fn (array $entry) => Carbon::parse($entry['occurred_at'])->lte($now)
        ));
    }
}
