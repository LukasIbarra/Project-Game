<?php

namespace App\Events;

use App\Models\Character;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// Fase 19.3: mismo patrón que ChatMessageCreated (ShouldBroadcastNow
// síncrono -esta VPS no tiene queue worker, ver ese evento-), pero sobre
// un canal PÚBLICO: a diferencia del chat, "quién puede ver que alguien se
// movió" no es información privada de una conversación -es exactamente lo
// mismo que ya expone GET /v1/presence?map=play sin autenticación de canal
// alguna-, así que no hace falta autorización en routes/channels.php.
//
// Recibe el Character ya cargado (nunca vuelve a consultarlo) más
// x/y/direction ya validados y persistidos por
// PresenceController::position() -este evento nunca decide si algo es
// válido, solo transmite lo que el controller ya aceptó-.
class PlayerMoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        private readonly Character $character,
        private readonly float $x,
        private readonly float $y,
        private readonly string $direction,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('world');
    }

    public function broadcastAs(): string
    {
        return 'PlayerMoved';
    }

    // Solo lo mínimo para dibujar a otro jugador -nunca coins/stats/
    // appearance/inventory/equipment/last_seen_at/status/current_map ni
    // ningún dato privado (objetivo #3).
    public function broadcastWith(): array
    {
        return [
            'character_id' => $this->character->id,
            'name' => $this->character->name,
            'level' => $this->character->level,
            'x' => $this->x,
            'y' => $this->y,
            'direction' => $this->direction,
        ];
    }
}
