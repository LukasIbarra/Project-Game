<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CombatLog;
use App\Services\ArenaRankingService;
use App\Services\CombatService;
use App\Services\CombatStatsService;
use Illuminate\Http\Request;

// Fase 10, sección 24: GET /v1/arena devuelve todo lo necesario para
// pintar la pantalla principal en una sola llamada -mi personaje,
// oponentes disponibles (con su cooldown ya resuelto), y el ranking-. El
// personaje SIEMPRE se deriva del usuario autenticado (CLAUDE.md #1).
class ArenaController extends Controller
{
    private const MAX_OPPONENTS = 20;

    // Fase 17: tope de una página de Historial -mismo criterio de "pocos
    // eventos por página" que ActivityController::MAX_EVENTS, no hace
    // falta más para un MVP de historial personal.
    private const MAX_COMBATS = 15;

    public function __construct(
        private readonly CombatStatsService $stats,
        private readonly CombatService $combat,
        private readonly ArenaRankingService $ranking
    ) {
    }

    public function index(Request $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $selfStats = $this->ranking->statsFor([$character->id])[$character->id] ?? ['wins' => 0, 'losses' => 0];

        $opponents = Character::where('id', '!=', $character->id)
            ->orderBy('level')
            ->limit(self::MAX_OPPONENTS)
            ->get();

        $opponentStats = $this->ranking->statsFor($opponents->pluck('id')->all());

        return response()->json([
            'character' => [
                'id' => $character->id,
                'name' => $character->name,
                'level' => $character->level,
                'exp' => $character->exp,
                'exp_to_next_level' => $this->stats->xpToNextLevel($character->level),
                'coins' => $character->coins,
                'appearance_json' => $character->appearance_json,
                'stats' => $this->stats->finalStats($character),
                'wins' => $selfStats['wins'],
                'losses' => $selfStats['losses'],
                'rank' => $this->ranking->rankOf($character),
            ],
            'opponents' => $opponents->map(fn (Character $opponent) => [
                'character_id' => $opponent->id,
                'name' => $opponent->name,
                'level' => $opponent->level,
                'appearance_json' => $opponent->appearance_json,
                'stats' => $this->stats->finalStats($opponent),
                'wins' => $opponentStats[$opponent->id]['wins'] ?? 0,
                'losses' => $opponentStats[$opponent->id]['losses'] ?? 0,
                'cooldown_seconds' => $this->combat->cooldownRemainingSeconds($character, $opponent),
            ])->values(),
            'ranking' => $this->ranking->topRanking(),
        ]);
    }

    // Sección 15: base mínima para poder consultar un combate anterior
    // (no se construye /arena/history completo todavía, solo el
    // endpoint). Solo el atacante o el defensor pueden verlo.
    //
    // Fase 17 (botón "Repetir"): se suma la apariencia ACTUAL de ambos
    // personajes -combat_logs.events_json nunca guardó appearance_json,
    // solo character_id/name/level/stats (sección 18 lo congela a
    // propósito para stats, no para aspecto)-. No inventamos un aspecto
    // histórico: BattleScene reproduce el combate real con el aspecto que
    // cada personaje tiene HOY. Deuda técnica documentada en el reporte de
    // esta fase, no en el modelo: si el personaje ya no existe (FK
    // nullOnDelete), el campo llega en null y el frontend degrada a una
    // vista de solo texto en vez de animar con Phaser.
    public function show(Request $request, CombatLog $combatLog)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $isParticipant = $combatLog->attacker_character_id === $character->id
            || $combatLog->defender_character_id === $character->id;

        if (! $isParticipant) {
            return response()->json(['message' => 'No podés ver este combate.'], 403);
        }

        $attackerNow = $combatLog->attacker_character_id
            ? Character::find($combatLog->attacker_character_id)
            : null;
        $defenderNow = $combatLog->defender_character_id
            ? Character::find($combatLog->defender_character_id)
            : null;

        return response()->json([
            ...$combatLog->toArray(),
            'attacker_appearance_json' => $attackerNow?->appearance_json,
            'defender_appearance_json' => $defenderNow?->appearance_json,
        ]);
    }

    // Fase 17: lista paginada de MIS combates, como atacante o como
    // defensor (antes solo se veía el propio ataque en el feed de
    // Actividad). Cero tabla nueva -se deriva 100% de combat_logs, mismo
    // criterio que ArenaRankingService-. Paginación por cursor descendente
    // (before_id, "combates más viejos que este id") en vez de after_id
    // (ChatController/ActivityController): ese patrón sirve para hacer
    // polling de lo NUEVO; acá el caso de uso es navegar hacia atrás en un
    // historial que ya empieza mostrando lo más reciente.
    public function combats(Request $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $beforeId = (int) $request->query('before_id', 0);

        $query = CombatLog::where(function ($q) use ($character) {
            $q->where('attacker_character_id', $character->id)
                ->orWhere('defender_character_id', $character->id);
        });

        if ($beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }

        $logs = $query->orderByDesc('id')->take(self::MAX_COMBATS + 1)->get();

        $hasMore = $logs->count() > self::MAX_COMBATS;
        $logs = $logs->take(self::MAX_COMBATS);

        $combats = $logs->map(function (CombatLog $log) use ($character) {
            $role = $log->attacker_character_id === $character->id ? 'attacker' : 'defender';
            $opponentRole = $role === 'attacker' ? 'defender' : 'attacker';

            // Nombres/recompensas salen del snapshot congelado en
            // events_json -mismo dato que ya usa Arena en vivo-, nunca de
            // un join a characters: así el historial no cambia si el
            // rival se renombra o su personaje ya no existe.
            $events = $log->events_json;
            $myReward = $events['rewards'][$role] ?? ['xp' => 0, 'coins' => 0, 'leveled_up' => false, 'new_level' => null];

            return [
                'id' => $log->id,
                'role' => $role,
                'opponent_character_id' => $role === 'attacker' ? $log->defender_character_id : $log->attacker_character_id,
                'opponent_name' => $events[$opponentRole]['name'] ?? '???',
                'result' => $events['winner'] === $role ? 'victory' : 'defeat',
                'xp' => $myReward['xp'],
                'coins' => $myReward['coins'],
                'leveled_up' => $myReward['leveled_up'],
                'new_level' => $myReward['new_level'],
                'created_at' => $log->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'combats' => $combats,
            'has_more' => $hasMore,
        ]);
    }
}
