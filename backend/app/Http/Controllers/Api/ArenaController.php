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

        return response()->json($combatLog);
    }
}
