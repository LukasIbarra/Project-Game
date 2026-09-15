<?php

namespace App\Services;

use App\Models\Character;

// Fase 10, sección 7/17: "Character base stats + Equipment modifiers ->
// Final combat stats". Las columnas base (strength/agility/vitality) ya
// existían desde el Pre-flight sin usarse en ningún sistema -esta es la
// primera vez que tienen un propósito real-. Los modificadores de
// equipamiento se leen genéricamente desde `items.metadata_json.
// stat_bonus` (ver ArenaEquipmentSeeder) — nunca `if item->key === "x"`,
// así que un item nuevo con stat_bonus funciona sin tocar esta clase.
//
// Fórmula deliberadamente simple (sección 7: "tienes libertad para
// decidir... siempre que mantenga el sistema simple y extensible") y
// documentada acá mismo, no repartida en varios archivos.
class CombatStatsService
{
    private const BASE_HP = 60;

    private const HP_PER_VITALITY = 6;

    private const BASE_ATTACK = 10;

    private const ATTACK_PER_STRENGTH = 4;

    private const BASE_DEFENSE = 3;

    private const DEFENSE_PER_AGILITY = 1;

    private const DEFENSE_PER_VITALITY = 0.5;

    private const BASE_CRIT_CHANCE = 5.0;

    private const CRIT_PER_AGILITY = 0.4;

    private const MAX_CRIT_CHANCE = 50.0;

    private const BASE_DODGE_CHANCE = 5.0;

    private const DODGE_PER_AGILITY = 0.4;

    private const MAX_DODGE_CHANCE = 40.0;

    // XP requerida para pasar del nivel N al N+1. Lineal a propósito -sin
    // curva exponencial todavía, sección 8: "priorizar una progresión
    // sencilla"-.
    private const XP_PER_LEVEL = 100;

    // Subir de nivel sube las 3 stats base por igual -sección 8: "una
    // opción válida es que subir de nivel incremente automáticamente
    // estadísticas base", sin árbol de puntos de atributo todavía.
    private const STAT_GAIN_PER_LEVEL = 2;

    public function __construct(private readonly ActivityLogger $activity)
    {
    }

    /**
     * @return array{max_hp:int,attack:int,defense:int,crit_chance:float,dodge_chance:float}
     */
    public function finalStats(Character $character): array
    {
        $maxHp = self::BASE_HP + $character->vitality * self::HP_PER_VITALITY;
        $attack = self::BASE_ATTACK + $character->strength * self::ATTACK_PER_STRENGTH;
        $defense = self::BASE_DEFENSE
            + $character->agility * self::DEFENSE_PER_AGILITY
            + $character->vitality * self::DEFENSE_PER_VITALITY;
        $critChance = self::BASE_CRIT_CHANCE + $character->agility * self::CRIT_PER_AGILITY;
        $dodgeChance = self::BASE_DODGE_CHANCE + $character->agility * self::DODGE_PER_AGILITY;

        foreach ($character->equipment()->with('inventoryItem.item')->get() as $equipped) {
            $bonus = $equipped->inventoryItem?->item?->metadata_json['stat_bonus'] ?? null;
            if (! $bonus) {
                continue;
            }

            $maxHp += $bonus['hp'] ?? 0;
            $attack += $bonus['attack'] ?? 0;
            $defense += $bonus['defense'] ?? 0;
            $critChance += $bonus['crit'] ?? 0;
            $dodgeChance += $bonus['dodge'] ?? 0;
        }

        return [
            'max_hp' => max(1, (int) round($maxHp)),
            'attack' => max(1, (int) round($attack)),
            'defense' => max(0, (int) round($defense)),
            'crit_chance' => round(min(self::MAX_CRIT_CHANCE, max(0, $critChance)), 1),
            'dodge_chance' => round(min(self::MAX_DODGE_CHANCE, max(0, $dodgeChance)), 1),
        ];
    }

    public function xpToNextLevel(int $level): int
    {
        return $level * self::XP_PER_LEVEL;
    }

    /**
     * Aplica XP y resuelve todos los level-ups que correspondan -un
     * combate puede, en teoría, subir más de un nivel de una vez-.
     * Persiste el character. No calcula recompensas de combate (eso es
     * CombatService); esto es un servicio de progresión reutilizable.
     *
     * @return array{leveled_up:bool,new_level:int}
     */
    public function addExperience(Character $character, int $xp): array
    {
        $character->exp += max(0, $xp);
        $leveledUp = false;

        while ($character->exp >= $this->xpToNextLevel($character->level)) {
            $character->exp -= $this->xpToNextLevel($character->level);
            $character->level++;
            $character->strength += self::STAT_GAIN_PER_LEVEL;
            $character->agility += self::STAT_GAIN_PER_LEVEL;
            $character->vitality += self::STAT_GAIN_PER_LEVEL;
            $leveledUp = true;
        }

        $character->save();

        // Fase 12: evento neutro -sin oponente ni resultado de combate, así
        // que se loguea para CUALQUIER personaje que suba de nivel (incluido
        // un defensor de Arena), a diferencia del evento "combat" que
        // CombatService reserva solo al atacante (Fase 17 todavía no cubre
        // al defensor).
        if ($leveledUp) {
            $this->activity->log($character, 'level_up', ['new_level' => $character->level]);
        }

        return ['leveled_up' => $leveledUp, 'new_level' => $character->level];
    }
}
