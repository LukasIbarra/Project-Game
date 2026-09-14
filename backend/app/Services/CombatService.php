<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CombatLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Fase 10. Principio no negociable (CLAUDE.md #1 + sección "Principio
// arquitectónico" de la fase): el cliente NUNCA decide daño/crítico/
// esquive/ganador/xp/monedas. Este servicio calcula TODO de una sola vez
// (mismo principio que PetExpeditionService::start / CLAUDE.md #6) y
// guarda el resultado completo en `combat_logs.events_json` — Phaser solo
// reproduce lo ya decidido acá, nunca vuelve a tirar un dado.
class CombatService
{
    private const COOLDOWN_MINUTES = 30;

    // 6 rondas x 2 turnos = hasta 12 eventos de attack/critical/dodge —
    // sección 10: "aproximadamente 6-12 eventos significativos", nunca
    // 50-100.
    private const MAX_ROUNDS = 6;

    private const WINNER_XP = 30;

    private const WINNER_COINS = 15;

    private const LOSER_XP = 10;

    private const LOSER_COINS = 3;

    public function __construct(private readonly CombatStatsService $stats)
    {
    }

    // Segundos restantes de cooldown de $attacker contra ESTE $defender en
    // particular (el cooldown es por objetivo, sección 3: "Lukas puede
    // atacar a otros jugadores que estén disponibles"). Se deriva del
    // último combate_log entre este par -no existe (ni hace falta) una
    // tabla de cooldowns aparte-.
    public function cooldownRemainingSeconds(Character $attacker, Character $defender): int
    {
        $last = CombatLog::where('attacker_character_id', $attacker->id)
            ->where('defender_character_id', $defender->id)
            ->orderByDesc('created_at')
            ->first();

        if (! $last) {
            return 0;
        }

        $unlocksAt = $last->created_at->clone()->addMinutes(self::COOLDOWN_MINUTES);
        $remaining = (int) now()->diffInSeconds($unlocksAt, false);

        return max(0, $remaining);
    }

    public function attack(Character $attacker, Character $defender): CombatLog
    {
        if ($attacker->id === $defender->id) {
            throw ValidationException::withMessages([
                'defender_character_id' => ['No podés atacarte a vos mismo.'],
            ]);
        }

        if ($this->cooldownRemainingSeconds($attacker, $defender) > 0) {
            throw ValidationException::withMessages([
                'defender_character_id' => ['Todavía estás en cooldown contra este objetivo.'],
            ]);
        }

        $attackerStats = $this->stats->finalStats($attacker);
        $defenderStats = $this->stats->finalStats($defender);

        // Puramente informativo/auditoría (la migración ya preveía un
        // string, no necesariamente numérico) — la simulación usa
        // random_int() sin sembrar el generador global a propósito: el
        // resultado completo ya queda persistido verbatim en events_json
        // (CLAUDE.md #6), así que no hace falta poder "re-tirar" los mismos
        // dados a partir del seed para que el combate sea reproducible.
        $seed = (string) random_int(100000, PHP_INT_MAX);

        $simulation = $this->simulate($attackerStats, $defenderStats);
        $winnerIsAttacker = $simulation['winner'] === 'attacker';

        return DB::transaction(function () use (
            $attacker,
            $defender,
            $attackerStats,
            $defenderStats,
            $simulation,
            $seed,
            $winnerIsAttacker
        ) {
            $attackerXp = $winnerIsAttacker ? self::WINNER_XP : self::LOSER_XP;
            $defenderXp = $winnerIsAttacker ? self::LOSER_XP : self::WINNER_XP;
            $attackerCoins = $winnerIsAttacker ? self::WINNER_COINS : self::LOSER_COINS;
            $defenderCoins = $winnerIsAttacker ? self::LOSER_COINS : self::WINNER_COINS;

            $attackerProgress = $this->stats->addExperience($attacker, $attackerXp);
            $defenderProgress = $this->stats->addExperience($defender, $defenderXp);

            $attacker->coins += $attackerCoins;
            $attacker->save();
            $defender->coins += $defenderCoins;
            $defender->save();

            return CombatLog::create([
                'attacker_character_id' => $attacker->id,
                'defender_character_id' => $defender->id,
                'winner_character_id' => $winnerIsAttacker ? $attacker->id : $defender->id,
                'seed' => $seed,
                'status' => 'completed',
                'started_at' => now(),
                'finished_at' => now(),
                'events_json' => [
                    'version' => 1,
                    // Snapshot congelado en el momento del combate (sección
                    // 18): si $attacker/$defender suben de nivel o cambian
                    // equipo después, este combate histórico no cambia.
                    'attacker' => [
                        'character_id' => $attacker->id,
                        'name' => $attacker->name,
                        'level' => $attacker->level,
                        'stats' => $attackerStats,
                    ],
                    'defender' => [
                        'character_id' => $defender->id,
                        'name' => $defender->name,
                        'level' => $defender->level,
                        'stats' => $defenderStats,
                    ],
                    'events' => $simulation['events'],
                    'winner' => $simulation['winner'],
                    'attacker_hp_remaining' => $simulation['attacker_hp_remaining'],
                    'defender_hp_remaining' => $simulation['defender_hp_remaining'],
                    'rewards' => [
                        'attacker' => [
                            'xp' => $attackerXp,
                            'coins' => $attackerCoins,
                            'leveled_up' => $attackerProgress['leveled_up'],
                            'new_level' => $attackerProgress['new_level'],
                        ],
                        'defender' => [
                            'xp' => $defenderXp,
                            'coins' => $defenderCoins,
                            'leveled_up' => $defenderProgress['leveled_up'],
                            'new_level' => $defenderProgress['new_level'],
                        ],
                    ],
                ],
            ]);
        });
    }

    /**
     * Simulación pura -no toca DB, no conoce Character-, para que sea
     * directamente testeable con stats fabricados a mano (ej. crit_chance
     * = 100 para forzar un crítico determinístico en un test). Turnos
     * alternados, atacante siempre primero en cada ronda -no hay stat de
     * "velocidad" en el MVP (sección 7 no la pide)-.
     *
     * @param  array{max_hp:int,attack:int,defense:int,crit_chance:float,dodge_chance:float}  $attackerStats
     * @param  array{max_hp:int,attack:int,defense:int,crit_chance:float,dodge_chance:float}  $defenderStats
     * @return array{events:array,winner:string,attacker_hp_remaining:int,defender_hp_remaining:int}
     */
    public function simulate(array $attackerStats, array $defenderStats): array
    {
        $attackerHp = $attackerStats['max_hp'];
        $defenderHp = $defenderStats['max_hp'];
        $events = [];

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $defenderHp = $this->resolveTurn('attacker', $attackerStats, $defenderStats, $defenderHp, $events);
            if ($defenderHp <= 0) {
                break;
            }

            $attackerHp = $this->resolveTurn('defender', $defenderStats, $attackerStats, $attackerHp, $events);
            if ($attackerHp <= 0) {
                break;
            }
        }

        if ($defenderHp <= 0 && $attackerHp > 0) {
            $winner = 'attacker';
        } elseif ($attackerHp <= 0 && $defenderHp > 0) {
            $winner = 'defender';
        } else {
            // Se acabaron las rondas sin un KO claro (o ambos llegaron a 0
            // en el mismo intercambio, caso límite) -gana quien conserve
            // mayor porcentaje de vida; empate exacto favorece al
            // atacante, decisión arbitraria mínima y documentada acá-.
            $attackerPct = $attackerHp / max(1, $attackerStats['max_hp']);
            $defenderPct = $defenderHp / max(1, $defenderStats['max_hp']);
            $winner = $attackerPct >= $defenderPct ? 'attacker' : 'defender';
        }

        return [
            'events' => $events,
            'winner' => $winner,
            'attacker_hp_remaining' => max(0, $attackerHp),
            'defender_hp_remaining' => max(0, $defenderHp),
        ];
    }

    /**
     * Resuelve un turno: $actingRole ataca al otro rol. Devuelve el HP
     * restante del objetivo después del turno (o el mismo HP si esquivó).
     *
     * @param  array{max_hp:int,attack:int,defense:int,crit_chance:float,dodge_chance:float}  $actingStats
     * @param  array{max_hp:int,attack:int,defense:int,crit_chance:float,dodge_chance:float}  $targetStats
     */
    private function resolveTurn(string $actingRole, array $actingStats, array $targetStats, int $targetHpBefore, array &$events): int
    {
        $targetRole = $actingRole === 'attacker' ? 'defender' : 'attacker';

        $dodgeRoll = random_int(0, 10000) / 100;
        if ($dodgeRoll <= $targetStats['dodge_chance']) {
            $events[] = [
                'type' => 'dodge',
                'actor' => $targetRole,
                'target' => null,
                'damage' => 0,
                'critical' => false,
                'target_hp_after' => $targetHpBefore,
            ];

            return $targetHpBefore;
        }

        $critRoll = random_int(0, 10000) / 100;
        $isCritical = $critRoll <= $actingStats['crit_chance'];

        $variance = random_int(85, 115) / 100;
        $rawDamage = ($actingStats['attack'] * $variance) - ($targetStats['defense'] * 0.5);
        $damage = max(1, (int) round($rawDamage * ($isCritical ? 1.5 : 1)));

        $targetHpAfter = max(0, $targetHpBefore - $damage);

        $events[] = [
            'type' => $isCritical ? 'critical' : 'attack',
            'actor' => $actingRole,
            'target' => $targetRole,
            'damage' => $damage,
            'critical' => $isCritical,
            'target_hp_after' => $targetHpAfter,
        ];

        return $targetHpAfter;
    }
}
