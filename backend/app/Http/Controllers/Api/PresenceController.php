<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendPresenceHeartbeatRequest;
use App\Models\PlayerPresence;
use Illuminate\Http\Request;

// Fase 18: presencia de jugadores -HTTP + polling, sin Reverb (ver
// docs/ROADMAP.md: el volumen de heartbeats de todos los jugadores activos
// no es comparable al de mensajes de chat, esporádicos). "Online" se
// decide siempre por ventana temporal sobre last_seen_at, nunca por
// `status` ni por un evento explícito de logout/pagehide.
class PresenceController extends Controller
{
    private const ONLINE_WINDOW_SECONDS = 60;

    // POST /v1/presence/heartbeat { current_map }
    public function heartbeat(SendPresenceHeartbeatRequest $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        PlayerPresence::updateOrCreate(
            ['character_id' => $character->id],
            [
                'last_seen_at' => now(),
                'current_map' => $request->validated()['current_map'],
                'status' => 'online',
            ]
        );

        return response()->noContent();
    }

    // GET /v1/presence -jugadores vistos en los últimos 60s. character_id
    // es unique en player_presence (ver migración Fase 18 Paso 1), así que
    // nunca puede haber duplicados acá -una fila por personaje, siempre-.
    public function index(Request $request)
    {
        $players = PlayerPresence::query()
            ->with('character:id,name,level')
            ->where('last_seen_at', '>=', now()->subSeconds(self::ONLINE_WINDOW_SECONDS))
            ->orderBy('character_id')
            ->get();

        return response()->json($players->map($this->present(...)));
    }

    // Forma mínima expuesta al cliente -nunca coins/stats/appearance_json
    // del personaje, ver ->with('character:id,name,level') arriba, que ya
    // ni siquiera los trae de la DB.
    private function present(PlayerPresence $presence): array
    {
        return [
            'character_id' => $presence->character_id,
            'name' => $presence->character->name,
            'level' => $presence->character->level,
            'current_map' => $presence->current_map,
            'status' => $presence->status,
        ];
    }
}
