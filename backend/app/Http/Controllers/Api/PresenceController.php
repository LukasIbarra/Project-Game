<?php

namespace App\Http\Controllers\Api;

use App\Enums\GameMap;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendPresenceHeartbeatRequest;
use App\Http\Requests\SendPresencePositionRequest;
use App\Models\PlayerPresence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

// Fase 18: presencia de jugadores -HTTP + polling, sin Reverb (ver
// docs/ROADMAP.md: el volumen de heartbeats de todos los jugadores activos
// no es comparable al de mensajes de chat, esporádicos). "Online" se
// decide siempre por ventana temporal sobre last_seen_at, nunca por
// `status` ni por un evento explícito de logout/pagehide.
//
// Fase 19.2: POST /position guarda x/y/direction sobre la MISMA fila -sin
// Reverb todavía, eso es F19.3-. Nunca crea presencia (esa es
// responsabilidad exclusiva del heartbeat) y nunca actualiza si el
// personaje no está en Mundo -evita que una página como Home mande
// posiciones de Mundo arbitrarias-.
class PresenceController extends Controller
{
    private const ONLINE_WINDOW_SECONDS = 60;

    // Fase 19.2: protección básica contra teletransporte/velocidad
    // imposible -no un motor de movimiento server-authoritative completo,
    // ni una réplica de las colisiones de Tiled (eso queda para más
    // adelante, si hace falta)-. SPEED_PX_PER_SECOND replica
    // WorldPlayer.SPEED (frontend) tal cual. El presupuesto de distancia
    // permitido es `SPEED * tiempo_transcurrido * TOLERANCE_MULTIPLIER +
    // TOLERANCE_FIXED_PX`:
    //   - TOLERANCE_MULTIPLIER (50% de margen) absorbe jitter/latencia de
    //     red sin abrir la puerta a un salto claramente imposible.
    //   - TOLERANCE_FIXED_PX es un colchón fijo (medio tile) para
    //     intervalos muy cortos, donde un multiplicador solo podría ser
    //     demasiado estricto.
    //   - MIN_ELAPSED_SECONDS evita que dos envíos casi simultáneos (por
    //     reordenamiento de red, por ejemplo) calculen un presupuesto
    //     cercano a cero -coincide con el throttle de ~300ms que el
    //     frontend va a usar en F19.3-.
    private const SPEED_PX_PER_SECOND = 70.0;
    private const MOVEMENT_TOLERANCE_MULTIPLIER = 1.5;
    private const MOVEMENT_TOLERANCE_FIXED_PX = 8.0;
    private const MIN_ELAPSED_SECONDS = 0.3;

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

    // POST /v1/presence/position { x, y, direction }
    public function position(SendPresencePositionRequest $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        // Nunca crea la fila acá -eso es responsabilidad exclusiva del
        // heartbeat (objetivo #4). Sin presencia previa, no hay nada que
        // actualizar.
        $presence = PlayerPresence::where('character_id', $character->id)->first();

        if (! $presence) {
            return response()->json(['message' => 'No hay una presencia registrada para este personaje.'], 404);
        }

        // El heartbeat es el único responsable de current_map -este
        // endpoint solo lee ese valor, nunca lo toca-. Evita que una
        // página distinta a Mundo mande posiciones de Mundo arbitrarias.
        if ($presence->current_map !== GameMap::Play->value) {
            return response()->json(['message' => 'El personaje no está en Mundo.'], 409);
        }

        $validated = $request->validated();
        $x = (float) $validated['x'];
        $y = (float) $validated['y'];
        $direction = $validated['direction'];

        // Primer envío de este personaje: no hay posición anterior contra
        // la cual medir plausibilidad -se acepta si ya pasó bounds/
        // direction/mapa arriba (objetivo #5).
        if ($presence->position_x !== null && $presence->position_y !== null) {
            $distance = hypot($x - (float) $presence->position_x, $y - (float) $presence->position_y);

            // abs(): diffInMilliseconds() devuelve un valor CON signo
            // (negativo cuando el otro timestamp es pasado, que es el caso
            // normal acá) -sin abs(), max() con MIN_ELAPSED_SECONDS
            // siempre hubiera terminado usando el piso, sin importar el
            // tiempo real transcurrido. abs() también cubre el caso de
            // last_seen_at levemente en el futuro por desfasaje de reloj.
            $elapsedSeconds = max(
                self::MIN_ELAPSED_SECONDS,
                abs(now()->diffInMilliseconds($presence->last_seen_at)) / 1000
            );

            $maxDistance = self::SPEED_PX_PER_SECOND * $elapsedSeconds * self::MOVEMENT_TOLERANCE_MULTIPLIER
                + self::MOVEMENT_TOLERANCE_FIXED_PX;

            if ($distance > $maxDistance) {
                return response()->json(['message' => 'Movimiento no plausible.'], 422);
            }
        }

        // Una posición válida demuestra que el personaje sigue activo en
        // Mundo -mismo criterio que el heartbeat, se refresca last_seen_at-.
        // current_map y status NUNCA se tocan acá (objetivo #6).
        $presence->update([
            'position_x' => $x,
            'position_y' => $y,
            'direction' => $direction,
            'last_seen_at' => now(),
        ]);

        return response()->json([
            'x' => $presence->position_x,
            'y' => $presence->position_y,
            'direction' => $presence->direction,
        ]);
    }

    // GET /v1/presence[?map=play] -jugadores vistos en los últimos 60s.
    // character_id es unique en player_presence (ver migración Fase 18
    // Paso 1), así que nunca puede haber duplicados acá -una fila por
    // personaje, siempre-. `map` es opcional a propósito: sin él, se
    // mantiene el comportamiento exacto de F18 (todos los online, sin
    // filtrar por mapa).
    public function index(Request $request)
    {
        $mapFilter = $this->resolveMapFilter($request);

        $query = PlayerPresence::query()
            ->with('character:id,name,level')
            ->where('last_seen_at', '>=', now()->subSeconds(self::ONLINE_WINDOW_SECONDS));

        if ($mapFilter !== null) {
            $query->where('current_map', $mapFilter);
        }

        $players = $query->orderBy('character_id')->get();

        return response()->json($players->map($this->present(...)));
    }

    // Fase 19.2: reusa GameMap::normalize() (mismo criterio que ya usaba
    // SendPresenceHeartbeatRequest para current_map, sin tocar ese
    // archivo) + Rule::enum(GameMap::class) -misma validación, ninguna
    // lógica de GameMap duplicada-. Sin `map` en la query, devuelve null
    // (sin filtrar), que es el comportamiento de F18.
    private function resolveMapFilter(Request $request): ?string
    {
        $raw = $request->query('map');
        if ($raw === null) {
            return null;
        }

        $normalized = GameMap::normalize((string) $raw);

        $validated = Validator::make(
            ['map' => $normalized],
            ['map' => ['required', 'string', Rule::enum(GameMap::class)]]
        )->validate();

        return $validated['map'];
    }

    // Forma mínima expuesta al cliente -nunca coins/stats/appearance_json
    // del personaje, ver ->with('character:id,name,level') arriba, que ya
    // ni siquiera los trae de la DB. position_x/position_y/direction
    // pueden ser null -jugador con presencia pero que todavía nunca mandó
    // una posición real desde Mundo-.
    private function present(PlayerPresence $presence): array
    {
        return [
            'character_id' => $presence->character_id,
            'name' => $presence->character->name,
            'level' => $presence->character->level,
            'current_map' => $presence->current_map,
            'position_x' => $presence->position_x,
            'position_y' => $presence->position_y,
            'direction' => $presence->direction,
            'status' => $presence->status,
        ];
    }
}
