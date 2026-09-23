<?php

namespace App\Services;

use App\Enums\PetStatus;
use App\Models\Character;
use App\Models\Pet;
use App\Models\PetSpecies;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// F23: adopción/compra/cambio de mascota activa. Mismo patrón que
// EconomyService::buy() (transacción, precio siempre server-side) pero
// además con lockForUpdate() sobre Character -pedido explícito de esta
// fase ("segura frente a doble click/dos pestañas"), a diferencia de
// buy() que documentó esa ventana de carrera como deuda conocida sin
// resolverla (ROADMAP.md, cierre de Fase 16).
class PetAdoptionService
{
    public function __construct(
        private readonly ActivityLogger $activity,
    ) {
    }

    // Primera mascota, siempre gratis. Válida SOLO si el personaje no
    // posee ninguna mascota jugable todavía (ni activa ni retirada cuenta
    // como "ya tuvo su starter" -una vez usada, se usó-). lockForUpdate()
    // sobre Character serializa dos requests simultáneas: la segunda
    // espera el lock y, al obtenerlo, ya ve la Pet que la primera acaba de
    // crear -rechaza en vez de duplicar-.
    public function adoptStarter(Character $character, PetSpecies $species): Pet
    {
        return DB::transaction(function () use ($character, $species) {
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();

            // notRetired() a propósito -un personaje legacy cuya única Pet
            // ya fue retirada (ver 2026_09_23_000004) NO cuenta como "ya
            // tuvo su starter": debe poder elegir una de las 5 nuevas
            // igual que un usuario nuevo, exactamente lo que pide F23-.
            if ($lockedCharacter->active_pet_id !== null || $lockedCharacter->pets()->notRetired()->exists()) {
                throw ValidationException::withMessages([
                    'species_key' => ['Ya adoptaste tu primera mascota.'],
                ]);
            }

            if (! $species->is_active || ! $species->is_starter_option) {
                throw ValidationException::withMessages([
                    'species_key' => ['Esa especie no está disponible para la adopción inicial.'],
                ]);
            }

            $pet = $this->createPet($lockedCharacter, $species);

            $lockedCharacter->active_pet_id = $pet->id;
            $lockedCharacter->save();

            $this->activity->log($lockedCharacter, 'pet_adopted', [
                'species_key' => $species->key,
                'species_name' => $species->name,
            ]);

            return $pet;
        });
    }

    // Mascota adicional -el precio SIEMPRE sale de species.adoption_price
    // (nunca del cliente, mismo criterio que EconomyService::buy()).
    // Máximo una por especie: chequeo explícito + unique(character_id,
    // species_id) en DB como red de seguridad ante una carrera real (ver
    // catch de QueryException, mismo patrón que PetProvisioningService
    // usaba en F20 para su propia constraint). NO cambia active_pet_id
    // -pedido explícito, la mascota recién comprada no se vuelve activa
    // automáticamente-.
    public function purchase(Character $character, PetSpecies $species): Pet
    {
        return DB::transaction(function () use ($character, $species) {
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();

            if (! $species->is_active) {
                throw ValidationException::withMessages([
                    'species_key' => ['Esa especie no está disponible.'],
                ]);
            }

            if ($lockedCharacter->pets()->notRetired()->where('species_id', $species->id)->exists()) {
                throw ValidationException::withMessages([
                    'species_key' => ['Ya tenés una mascota de esa especie.'],
                ]);
            }

            $price = $species->adoption_price;
            if ($lockedCharacter->coins < $price) {
                throw ValidationException::withMessages([
                    'species_key' => ["No tenés suficientes monedas ({$lockedCharacter->coins} disponibles, necesitás {$price})."],
                ]);
            }

            $lockedCharacter->decrement('coins', $price);

            try {
                $pet = $this->createPet($lockedCharacter, $species);
            } catch (QueryException $e) {
                // Carrera real contra el unique(character_id, species_id)
                // -no debería pasar dado el chequeo de arriba bajo el
                // mismo lock, pero es la misma red de seguridad que ya usa
                // el resto del proyecto (p.ej. PetProvisioningService en
                // F20) para esta clase de constraint.
                throw ValidationException::withMessages([
                    'species_key' => ['Ya tenés una mascota de esa especie.'],
                ]);
            }

            $this->activity->log($lockedCharacter, 'pet_purchased', [
                'species_key' => $species->key,
                'species_name' => $species->name,
                'coins_spent' => $price,
            ]);

            return $pet;
        });
    }

    // Cambiar la mascota activa -operación de servicio, nunca directo
    // desde el controller, para que el lock/la validación de ownership
    // vivan en un solo lugar. No valida nada sobre expediciones en curso
    // -cambiar la activa no reasigna ni modifica ninguna PetExpedition
    // existente, ExpeditionService siempre trabaja con la Pet concreta que
    // se le pasa, nunca con "la activa" leída por su cuenta (ver
    // docs/PETS_EXPEDITIONS_SYSTEM.md, nota de F23)-.
    public function setActive(Character $character, Pet $pet): Character
    {
        return DB::transaction(function () use ($character, $pet) {
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();

            if ($pet->character_id !== $lockedCharacter->id || $pet->retired_at !== null) {
                throw ValidationException::withMessages([
                    'pet_id' => ['Esa mascota no está disponible.'],
                ]);
            }

            $lockedCharacter->active_pet_id = $pet->id;
            $lockedCharacter->save();

            return $lockedCharacter;
        });
    }

    private function createPet(Character $character, PetSpecies $species): Pet
    {
        return Pet::create([
            'character_id' => $character->id,
            'species_id' => $species->id,
            'name' => $species->name,
            'level' => 1,
            'exp' => 0,
            'health' => 100,
            'max_health' => 100,
            'energy' => 100,
            'max_energy' => 100,
            'status' => PetStatus::Idle,
        ]);
    }
}
