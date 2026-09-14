<?php

namespace Tests\Feature;

use App\Enums\EquipmentSlot;
use App\Models\Character;
use App\Models\CharacterEquipment;
use App\Models\CombatLog;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use App\Services\CombatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Fase 10: combate asíncrono. El servidor es la única autoridad
// (CLAUDE.md #1) -estos tests existen sobre todo para probar que NINGÚN
// campo mandado por el cliente (attacker_character_id, damage, winner,
// xp) puede alterar el resultado, y que cooldown/xp/monedas/ganador se
// calculan y persisten correctamente server-side.
class ArenaTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(array $attrs = []): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(array_merge(['user_id' => $user->id], $attrs));
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    public function test_usuario_autenticado_puede_atacar(): void
    {
        [$attacker, $token] = $this->characterWithToken();
        [$defender] = $this->characterWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('combat_logs', [
            'attacker_character_id' => $attacker->id,
            'defender_character_id' => $defender->id,
        ]);
    }

    public function test_el_atacante_siempre_es_el_personaje_autenticado_no_el_del_payload(): void
    {
        [$attacker, $token] = $this->characterWithToken();
        [$defender] = $this->characterWithToken();
        [$otroPersonaje] = $this->characterWithToken();

        // El cliente intenta hacerse pasar por otro personaje -no existe
        // ese campo en AttackRequest, así que se ignora por completo.
        $response = $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
            'attacker_character_id' => $otroPersonaje->id,
        ]);

        $response->assertStatus(201);
        $this->assertEquals($attacker->id, $response->json('attacker_character_id'));
    }

    public function test_no_puede_atacarse_a_si_mismo(): void
    {
        [$attacker, $token] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $attacker->id,
        ])->assertStatus(422);
    }

    public function test_no_puede_atacar_mientras_el_cooldown_esta_activo(): void
    {
        [, $token] = $this->characterWithToken();
        [$defender] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ])->assertStatus(201);

        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ])->assertStatus(422);
    }

    public function test_puede_atacar_otro_objetivo_distinto_aunque_el_primero_este_en_cooldown(): void
    {
        [, $token] = $this->characterWithToken();
        [$defenderA] = $this->characterWithToken();
        [$defenderB] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defenderA->id,
        ])->assertStatus(201);

        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defenderB->id,
        ])->assertStatus(201);
    }

    public function test_puede_atacar_de_nuevo_despues_de_que_expire_el_cooldown(): void
    {
        [$attacker, $token] = $this->characterWithToken();
        [$defender] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ])->assertStatus(201);

        // Simula que pasaron 31 minutos -mismo approach que PetExpeditionTest:
        // desplazar el timestamp guardado, no esperar tiempo real.
        CombatLog::where('attacker_character_id', $attacker->id)
            ->where('defender_character_id', $defender->id)
            ->update(['created_at' => now()->subMinutes(31)]);

        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ])->assertStatus(201);
    }

    public function test_simulacion_pura_calcula_daño_dentro_de_rango_esperado(): void
    {
        $combat = app(CombatService::class);
        $attackerStats = ['max_hp' => 1000, 'attack' => 20, 'defense' => 0, 'crit_chance' => 0, 'dodge_chance' => 0];
        $defenderStats = ['max_hp' => 1000, 'attack' => 0, 'defense' => 0, 'crit_chance' => 0, 'dodge_chance' => 0];

        $result = $combat->simulate($attackerStats, $defenderStats);
        $attackEvents = array_filter($result['events'], fn ($e) => $e['type'] === 'attack' && $e['actor'] === 'attacker');

        $this->assertNotEmpty($attackEvents);
        foreach ($attackEvents as $event) {
            // attack=20, variance 0.85-1.15, defense=0 -> damage entre 17 y 23.
            $this->assertGreaterThanOrEqual(17, $event['damage']);
            $this->assertLessThanOrEqual(23, $event['damage']);
            $this->assertFalse($event['critical']);
        }
    }

    public function test_critico_se_aplica_cuando_crit_chance_es_100(): void
    {
        $combat = app(CombatService::class);
        $attackerStats = ['max_hp' => 1000, 'attack' => 20, 'defense' => 0, 'crit_chance' => 100, 'dodge_chance' => 0];
        $defenderStats = ['max_hp' => 1000, 'attack' => 0, 'defense' => 0, 'crit_chance' => 0, 'dodge_chance' => 0];

        $result = $combat->simulate($attackerStats, $defenderStats);
        $attackerEvents = array_values(array_filter($result['events'], fn ($e) => $e['actor'] === 'attacker'));

        $this->assertNotEmpty($attackerEvents);
        foreach ($attackerEvents as $event) {
            $this->assertEquals('critical', $event['type']);
            $this->assertTrue($event['critical']);
        }
    }

    public function test_esquive_se_aplica_cuando_dodge_chance_es_100(): void
    {
        $combat = app(CombatService::class);
        $attackerStats = ['max_hp' => 1000, 'attack' => 20, 'defense' => 0, 'crit_chance' => 0, 'dodge_chance' => 0];
        $defenderStats = ['max_hp' => 1000, 'attack' => 0, 'defense' => 0, 'crit_chance' => 0, 'dodge_chance' => 100];

        $result = $combat->simulate($attackerStats, $defenderStats);
        $eventsAgainstDefender = array_values(array_filter($result['events'], fn ($e) => $e['actor'] === 'attacker' || $e['actor'] === 'defender'));

        foreach ($result['events'] as $event) {
            if ($event['target'] === 'defender' || ($event['type'] === 'dodge' && $event['actor'] === 'defender')) {
                $this->assertEquals('dodge', $event['type']);
                $this->assertEquals(0, $event['damage']);
            }
        }
        // El defensor esquiva TODO -su HP nunca baja de max_hp-.
        $this->assertEquals(1000, $result['defender_hp_remaining']);
    }

    public function test_el_ganador_queda_registrado_correctamente(): void
    {
        [$attacker, $token] = $this->characterWithToken(['strength' => 50, 'agility' => 50, 'vitality' => 50]);
        [$defender] = $this->characterWithToken(['strength' => 1, 'agility' => 1, 'vitality' => 1]);

        $response = $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ]);

        $response->assertStatus(201);
        $this->assertEquals($attacker->id, $response->json('winner_character_id'));
        $this->assertEquals('attacker', $response->json('events_json.winner'));
    }

    public function test_xp_y_monedas_se_otorgan_a_ambos_personajes(): void
    {
        [$attacker, $token] = $this->characterWithToken(['exp' => 0, 'coins' => 0]);
        [$defender] = $this->characterWithToken(['exp' => 0, 'coins' => 0]);

        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ])->assertStatus(201);

        $attacker->refresh();
        $defender->refresh();

        // Gane o pierda, ambos deben haber ganado algo de xp/monedas
        // (ganador más que perdedor, sección 8).
        $this->assertGreaterThan(0, $attacker->exp + $attacker->coins);
        $this->assertGreaterThan(0, $defender->exp + $defender->coins);
    }

    public function test_estadisticas_de_equipamiento_se_consideran_en_el_combate(): void
    {
        [$character] = $this->characterWithToken(['strength' => 1, 'agility' => 1, 'vitality' => 1]);
        $sword = Item::where('key', 'simple_sword')->firstOrFail();
        $inventoryItem = InventoryItem::create(['character_id' => $character->id, 'item_id' => $sword->id, 'quantity' => 1]);

        $statsBefore = app(\App\Services\CombatStatsService::class)->finalStats($character->fresh());

        CharacterEquipment::create([
            'character_id' => $character->id,
            'inventory_item_id' => $inventoryItem->id,
            'slot' => EquipmentSlot::Weapon->value,
        ]);

        $statsAfter = app(\App\Services\CombatStatsService::class)->finalStats($character->fresh());

        // simple_sword da +5 attack (ArenaEquipmentSeeder).
        $this->assertEquals($statsBefore['attack'] + 5, $statsAfter['attack']);
    }

    public function test_el_combate_se_guarda_con_snapshot_de_estadisticas(): void
    {
        [, $token] = $this->characterWithToken();
        [$defender] = $this->characterWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ]);

        $events = $response->json('events_json');
        $this->assertArrayHasKey('attacker', $events);
        $this->assertArrayHasKey('stats', $events['attacker']);
        $this->assertArrayHasKey('max_hp', $events['attacker']['stats']);
        $this->assertArrayHasKey('defender', $events);
        $this->assertArrayHasKey('stats', $events['defender']);
    }

    public function test_snapshot_historico_no_cambia_si_el_personaje_sube_de_nivel_despues(): void
    {
        [$attacker, $token] = $this->characterWithToken();
        [$defender] = $this->characterWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ]);
        $combatId = $response->json('id');
        $originalSnapshot = $response->json('events_json.attacker.stats');

        // El personaje sube de nivel manualmente después del combate.
        $attacker->refresh();
        $attacker->level += 10;
        $attacker->strength += 50;
        $attacker->save();

        $replay = $this->withToken($token)->getJson("/api/v1/arena/combats/{$combatId}");
        $replay->assertOk();
        $this->assertEquals($originalSnapshot, $replay->json('events_json.attacker.stats'));
    }

    public function test_resultado_es_reproducible_sin_recalcular(): void
    {
        [, $token] = $this->characterWithToken();
        [$defender] = $this->characterWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ]);
        $combatId = $response->json('id');
        $original = $response->json();

        $replay = $this->withToken($token)->getJson("/api/v1/arena/combats/{$combatId}");
        $replay->assertOk();
        $this->assertEquals($original['events_json'], $replay->json('events_json'));
    }

    public function test_no_puede_ver_el_combate_de_otro_usuario_ajeno(): void
    {
        [, $token] = $this->characterWithToken();
        [$defender] = $this->characterWithToken();
        [, $tokenAjeno] = $this->characterWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ]);
        $combatId = $response->json('id');

        $this->app['auth']->forgetGuards();

        $this->withToken($tokenAjeno)->getJson("/api/v1/arena/combats/{$combatId}")
            ->assertStatus(403);
    }

    public function test_manipulacion_del_payload_no_altera_el_resultado(): void
    {
        [$attacker, $token] = $this->characterWithToken(['exp' => 0, 'coins' => 0]);
        [$defender] = $this->characterWithToken(['exp' => 0, 'coins' => 0]);

        $response = $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
            'damage' => 999999,
            'winner' => 'attacker',
            'xp' => 100000,
            'coins' => 999999,
        ]);

        $response->assertStatus(201);
        $attacker->refresh();
        $this->assertLessThan(100000, $attacker->exp);
        $this->assertLessThan(999999, $attacker->coins);
    }

    public function test_get_arena_devuelve_personaje_oponentes_y_ranking(): void
    {
        [, $token] = $this->characterWithToken();
        $this->characterWithToken();
        $this->characterWithToken();

        $response = $this->withToken($token)->getJson('/api/v1/arena');

        $response->assertOk();
        $response->assertJsonStructure([
            'character' => ['id', 'name', 'level', 'stats', 'wins', 'losses', 'rank'],
            'opponents',
            'ranking',
        ]);
        $this->assertGreaterThanOrEqual(2, count($response->json('opponents')));
    }

    public function test_defender_character_id_inexistente_falla(): void
    {
        [, $token] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => 999999999,
        ])->assertStatus(422);
    }
}
