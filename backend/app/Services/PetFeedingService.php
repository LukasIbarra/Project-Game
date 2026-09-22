<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Pet;
use App\Models\PetFoodItem;
use Illuminate\Support\Facades\DB;

// Fase 20: alimentar una mascota -consumir un item de comida, otorgar
// EXP, resolver level-ups. Mismo patrón de fórmula que
// CombatStatsService::xpToNextLevel/applyExperience (level * constante,
// while-loop para resolver más de un nivel de una sola vez).
//
// Idempotencia/concurrencia (pedido explícito de la fase): un único
// lockForUpdate() sobre la fila de Pet, ADEMÁS del lockForUpdate() que
// InventoryGrantService::consume() ya aplica sobre las filas de
// inventario -mismo criterio que PetExpeditionService::resolveIfDue()-.
// Dos requests de feed simultáneos para la misma mascota: el segundo
// espera el lock, y para cuando lo obtiene, o bien ya no queda comida
// suficiente (InventoryGrantService::consume() tira RuntimeException,
// nunca duplica el consumo) o el EXP ya fue aplicado por el primero antes
// de que el segundo lea `exp`/`level` -nunca hay una lectura-luego-escritura
// sin lock de por medio-.
class PetFeedingService
{
    // Mismo criterio que CombatStatsService::XP_PER_LEVEL (level *
    // constante) -mitad de valor porque alimentar es una acción barata y
    // repetible, no una recompensa de combate puntual-.
    private const EXP_PER_LEVEL = 50;

    public function __construct(
        private readonly InventoryGrantService $inventory,
        private readonly ActivityLogger $activity,
    ) {
    }

    public function feed(Character $character, Pet $pet, PetFoodItem $food): array
    {
        return DB::transaction(function () use ($character, $pet, $food) {
            // Lockea la mascota ANTES de tocar el inventario -mismo orden
            // de locks en todos los llamados a este método, sin
            // posibilidad real de deadlock cruzado con otro flujo (ningún
            // otro service lockea pets, ver auditoría de F20)-.
            $lockedPet = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();

            // Tira RuntimeException si no alcanza -InventoryGrantService
            // ya usa lockForUpdate() internamente sobre las filas de
            // inventario, no hay carrera posible de doble-consumo acá-.
            $this->inventory->consume($character, $food->item, 1);

            $lockedPet->exp += $food->exp_value;
            $leveledUp = $this->applyLevelUps($lockedPet);
            $lockedPet->save();

            $this->activity->log($character, 'pet_fed', [
                'food_item' => $food->item->key,
                'exp_gained' => $food->exp_value,
            ]);

            if ($leveledUp) {
                $this->activity->log($character, 'pet_leveled_up', [
                    'new_level' => $lockedPet->level,
                ]);
            }

            return ['pet' => $lockedPet->fresh(), 'leveled_up' => $leveledUp];
        });
    }

    private function expToNextLevel(int $level): int
    {
        return $level * self::EXP_PER_LEVEL;
    }

    // Mismo patrón que CombatStatsService::applyExperience -un while, no
    // un if, porque una sola alimentación con suficiente EXP puede subir
    // más de un nivel de una vez-.
    private function applyLevelUps(Pet $pet): bool
    {
        $leveledUp = false;

        while ($pet->exp >= $this->expToNextLevel($pet->level)) {
            $pet->exp -= $this->expToNextLevel($pet->level);
            $pet->level++;
            $leveledUp = true;
        }

        return $leveledUp;
    }
}
