<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Pet;

// F23: reemplaza el viejo comportamiento de "asegurar" una mascota
// (auto-creaba "Compañero" si no existía, en AuthController::register() y
// perezosamente en cada request a /pet*). Ya NO se auto-crea ninguna
// mascota -el usuario elige explícitamente vía PetAdoptionService::adoptStarter()-.
// Este service ahora solo RESUELVE la mascota activa, sin crear nada;
// puede devolver null (sin mascota activa: usuario nuevo, o legacy
// retirado, ver migración 2026_09_23_000004).
class PetProvisioningService
{
    public function activePetFor(Character $character): ?Pet
    {
        return $character->activePet;
    }
}
