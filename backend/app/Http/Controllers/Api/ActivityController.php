<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use Illuminate\Http\Request;

// Fase 12: feed de actividad reciente. Mismo patrón exacto que
// ChatController::index -sin after_id: los últimos N en orden cronológico;
// con after_id: solo los posteriores, mismo tope, para que el polling nunca
// vuelva a pedir lo que ya tiene-. El personaje SIEMPRE se deriva del
// usuario autenticado (CLAUDE.md #1): un jugador solo puede ver sus propios
// eventos, nunca los de otro.
class ActivityController extends Controller
{
    private const MAX_EVENTS = 25;

    public function index(Request $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $afterId = (int) $request->query('after_id', 0);

        $query = ActivityEvent::query()->where('character_id', $character->id);

        if ($afterId > 0) {
            $events = $query->where('id', '>', $afterId)
                ->orderBy('id')
                ->take(self::MAX_EVENTS)
                ->get();
        } else {
            $events = $query->orderByDesc('id')
                ->take(self::MAX_EVENTS)
                ->get()
                ->sortBy('id')
                ->values();
        }

        return response()->json($events->map(fn (ActivityEvent $event) => [
            'id' => $event->id,
            'type' => $event->type,
            'payload' => $event->payload,
            'created_at' => $event->created_at->toIso8601String(),
        ]));
    }
}
