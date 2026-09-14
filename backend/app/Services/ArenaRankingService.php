<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CombatLog;
use Illuminate\Support\Facades\DB;

// Fase 10, sección 16: ranking simple (victorias, luego nivel como
// desempate) -sin ELO, sin tabla propia-. `wins`/`losses` se DERIVAN de
// `combat_logs` con agregados (no se guardan como columnas redundantes en
// `characters`): a esta escala de MVP tres queries agregadas son más que
// suficientes y evitan que un contador pueda desincronizarse del
// historial real, que es la fuente de verdad.
class ArenaRankingService
{
    /**
     * @param  array<int, int>  $characterIds
     * @return array<int, array{wins:int, losses:int}>
     */
    public function statsFor(array $characterIds): array
    {
        $ids = array_values(array_unique($characterIds));
        if (empty($ids)) {
            return [];
        }

        $wins = CombatLog::whereIn('winner_character_id', $ids)
            ->select('winner_character_id', DB::raw('count(*) as c'))
            ->groupBy('winner_character_id')
            ->pluck('c', 'winner_character_id');

        $asAttacker = CombatLog::whereIn('attacker_character_id', $ids)
            ->select('attacker_character_id', DB::raw('count(*) as c'))
            ->groupBy('attacker_character_id')
            ->pluck('c', 'attacker_character_id');

        $asDefender = CombatLog::whereIn('defender_character_id', $ids)
            ->select('defender_character_id', DB::raw('count(*) as c'))
            ->groupBy('defender_character_id')
            ->pluck('c', 'defender_character_id');

        $result = [];
        foreach ($ids as $id) {
            $total = (int) ($asAttacker[$id] ?? 0) + (int) ($asDefender[$id] ?? 0);
            $wonCount = (int) ($wins[$id] ?? 0);
            $result[$id] = ['wins' => $wonCount, 'losses' => max(0, $total - $wonCount)];
        }

        return $result;
    }

    /**
     * Top $limit personajes, ordenados por victorias desc, nivel desc.
     *
     * @return array<int, array{character_id:int, name:string, level:int, wins:int, losses:int}>
     */
    public function topRanking(int $limit = 10): array
    {
        $characters = Character::query()->select('id', 'name', 'level')->get();
        $stats = $this->statsFor($characters->pluck('id')->all());

        $ranked = $characters->map(fn (Character $c) => [
            'character_id' => $c->id,
            'name' => $c->name,
            'level' => $c->level,
            'wins' => $stats[$c->id]['wins'] ?? 0,
            'losses' => $stats[$c->id]['losses'] ?? 0,
        ])->sort(fn ($a, $b) => [$b['wins'], $b['level']] <=> [$a['wins'], $a['level']])
            ->values();

        return $ranked->take($limit)->all();
    }

    // Posición (1-based) de $character en el ranking completo -recorre
    // todos los personajes en memoria; a la escala esperada del MVP esto
    // es más simple y transparente que una window function SQL, y evita
    // otra query especial solo para esto.
    public function rankOf(Character $character): int
    {
        $characters = Character::query()->select('id', 'level')->get();
        $stats = $this->statsFor($characters->pluck('id')->all());

        $ordered = $characters->map(fn (Character $c) => [
            'id' => $c->id,
            'level' => $c->level,
            'wins' => $stats[$c->id]['wins'] ?? 0,
        ])->sort(fn ($a, $b) => [$b['wins'], $b['level']] <=> [$a['wins'], $a['level']])
            ->values();

        $index = $ordered->search(fn ($row) => $row['id'] === $character->id);

        return $index === false ? $ordered->count() + 1 : $index + 1;
    }
}
