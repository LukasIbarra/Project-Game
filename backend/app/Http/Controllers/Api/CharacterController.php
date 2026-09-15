<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CombatStatsService;
use Illuminate\Http\Request;

class CharacterController extends Controller
{
    public function __construct(private readonly CombatStatsService $stats)
    {
    }

    // Fase 11: agrega exp_to_next_level (reusa la misma fórmula que ya
    // expone GET /v1/arena, ver CombatStatsService::xpToNextLevel) para que
    // el HUD/Inicio puedan pintar la barra de experiencia real sin
    // duplicar la fórmula en el frontend.
    public function show(Request $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        return response()->json([
            ...$character->toArray(),
            'exp_to_next_level' => $this->stats->xpToNextLevel($character->level),
        ]);
    }
}
