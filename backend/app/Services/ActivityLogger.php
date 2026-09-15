<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\Character;

// Fase 12: único punto de escritura del feed de actividad -cualquier
// servicio que necesite registrar algo llama a esto, nunca crea
// ActivityEvent directamente (mismo criterio que InventoryGrantService para
// stack/max_stack: una sola implementación reutilizada, no duplicada por
// caller). Sin lógica de negocio propia a propósito: quien llama ya sabe
// qué pasó y arma el payload, esto solo persiste.
class ActivityLogger
{
    public function log(Character $character, string $type, array $payload = []): ActivityEvent
    {
        return ActivityEvent::create([
            'character_id' => $character->id,
            'type' => $type,
            'payload' => $payload,
        ]);
    }
}
