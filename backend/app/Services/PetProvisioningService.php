<?php

namespace App\Services;

use App\Enums\PetStatus;
use App\Models\Character;
use App\Models\Pet;
use App\Models\PetSpecies;
use Illuminate\Database\QueryException;

// Fase 7, sección 2: todo Character nuevo recibe una mascota (mismo
// principio que AuthController ya usa para crear el Character al
// registrar). También cubre personajes YA existentes sin mascota -se
// llama de forma perezosa la primera vez que se pide su Pet (GET /pet),
// mismo principio de resolución perezosa que el resto del proyecto-.
// `pets.character_id` es UNIQUE (pre-flight), así que una carrera entre
// dos requests como mucho dispara una excepción de duplicado en una de
// las dos, nunca dos mascotas.
//
// Fase 20: 'starter' pasó de ser un string suelto en `pets.key` a una
// fila real de `pet_species` (creada por la migración de backfill,
// 2026_09_19_000002) -este service ya no inventa el valor, lo busca.
class PetProvisioningService
{
    public function ensureForCharacter(Character $character): Pet
    {
        if ($character->pet) {
            return $character->pet;
        }

        try {
            return Pet::create([
                'character_id' => $character->id,
                'species_id' => PetSpecies::where('key', 'starter')->firstOrFail()->id,
                'name' => 'Compañero',
                'level' => 1,
                'exp' => 0,
                'health' => 100,
                'max_health' => 100,
                'energy' => 100,
                'max_energy' => 100,
                'status' => PetStatus::Idle,
            ]);
        } catch (QueryException $e) {
            // Carrera: otra request ya la creó entre el check y el
            // create -se relee en vez de fallar-.
            return $character->pet()->firstOrFail();
        }
    }
}
