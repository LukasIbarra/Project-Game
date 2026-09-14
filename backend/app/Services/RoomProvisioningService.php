<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Room;
use Illuminate\Database\QueryException;

// Mismo patrón que PetProvisioningService (F7): resolución perezosa, la
// habitación se crea la primera vez que alguien la pide, no al registrar
// al usuario -a diferencia de Character/Pet, una habitación vacía no
// aporta nada hasta que exista al menos un mueble, así que no hace falta
// crearla por adelantado-. `rooms.character_id` es UNIQUE (pre-flight),
// así que una carrera entre dos requests como mucho dispara un duplicado
// en una de las dos, nunca dos habitaciones.
class RoomProvisioningService
{
    public function ensureForCharacter(Character $character): Room
    {
        if ($character->room) {
            return $character->room;
        }

        try {
            $room = Room::create(['character_id' => $character->id]);
        } catch (QueryException $e) {
            $room = $character->room()->firstOrFail();
        }

        // Sin esto, cualquier código que siga usando esta MISMA instancia
        // de $character (p.ej. dos llamadas dentro del mismo request, o
        // -como reveló un test- el mismo objeto reutilizado entre
        // requests) seguiría viendo el `null` que el chequeo de arriba ya
        // cacheó en la relación, aunque la Room recién se haya creado.
        $character->setRelation('room', $room);

        return $room;
    }
}
