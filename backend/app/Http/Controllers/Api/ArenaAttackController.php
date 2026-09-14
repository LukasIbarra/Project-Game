<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttackRequest;
use App\Models\Character;
use App\Services\CombatService;

// Fase 10, sección 4-6: POST /v1/arena/attack. El atacante SIEMPRE es
// `auth()->user()->character` -nunca un campo del payload (CLAUDE.md
// #1)-; el defensor se resuelve por id desde el body (único dato que el
// cliente decide: A QUIÉN atacar, nunca el resultado). Todo lo demás
// -daño, crítico, esquive, ganador, xp, monedas, cooldown- lo calcula
// CombatService de punta a punta.
class ArenaAttackController extends Controller
{
    public function __construct(private readonly CombatService $combat)
    {
    }

    public function attack(AttackRequest $request)
    {
        $attacker = $request->user()->character;

        if (! $attacker) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $defender = Character::findOrFail($request->integer('defender_character_id'));

        $combatLog = $this->combat->attack($attacker, $defender);

        return response()->json($combatLog, 201);
    }
}
