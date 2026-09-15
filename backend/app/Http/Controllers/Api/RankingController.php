<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ArenaRankingService;
use Illuminate\Http\Request;

// Fase 13: /ranking necesitaba su propio endpoint liviano -reusar GET
// /v1/arena hubiera acoplado la pantalla de Ranking a la respuesta de Arena
// (oponentes, cooldowns, mis stats de combate) que no le sirven para nada-.
// Cero lógica de ranking nueva: este controller solo orquesta los mismos 3
// métodos que ya usa ArenaController (topRanking/statsFor/rankOf), fuente
// de verdad única en ArenaRankingService.
class RankingController extends Controller
{
    public function __construct(private readonly ArenaRankingService $ranking)
    {
    }

    public function index(Request $request)
    {
        $character = $request->user()->character;

        $me = null;
        if ($character) {
            $selfStats = $this->ranking->statsFor([$character->id])[$character->id] ?? ['wins' => 0, 'losses' => 0];
            $me = [
                'character_id' => $character->id,
                'rank' => $this->ranking->rankOf($character),
                'wins' => $selfStats['wins'],
                'losses' => $selfStats['losses'],
            ];
        }

        return response()->json([
            'ranking' => $this->ranking->topRanking(),
            'me' => $me,
        ]);
    }
}
