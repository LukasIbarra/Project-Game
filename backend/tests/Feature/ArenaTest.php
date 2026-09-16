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

    // Fase 17: Historial de Combates + Ataques Recibidos.

    public function test_get_arena_combats_incluye_combates_como_atacante_y_como_defensor(): void
    {
        [$me, $tokenMe] = $this->characterWithToken();
        [$otro, $tokenOtro] = $this->characterWithToken();

        $this->withToken($tokenMe)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $otro->id,
        ])->assertStatus(201);

        $this->app['auth']->forgetGuards();

        // Cooldown es por PAR ordenado (attacker->defender), así que "otro"
        // atacando de vuelta a "me" no choca con el cooldown recién creado.
        $this->withToken($tokenOtro)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $me->id,
        ])->assertStatus(201);

        $this->app['auth']->forgetGuards();

        $response = $this->withToken($tokenMe)->getJson('/api/v1/arena/combats');

        $response->assertOk();
        $roles = collect($response->json('combats'))->pluck('role')->all();
        $this->assertContains('attacker', $roles);
        $this->assertContains('defender', $roles);
        $this->assertCount(2, $roles);
    }

    public function test_get_arena_combats_no_incluye_combates_ajenos(): void
    {
        [$a, $tokenA] = $this->characterWithToken();
        [$b] = $this->characterWithToken();
        [, $tokenC] = $this->characterWithToken();

        $this->withToken($tokenA)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $b->id,
        ])->assertStatus(201);

        $this->app['auth']->forgetGuards();

        $response = $this->withToken($tokenC)->getJson('/api/v1/arena/combats');

        $response->assertOk();
        $this->assertCount(0, $response->json('combats'));
    }

    public function test_get_arena_combats_pagina_con_before_id_sin_duplicar_ni_perder_filas(): void
    {
        [$me, $token] = $this->characterWithToken();

        // 17 combates > MAX_COMBATS (15) para forzar dos páginas -un
        // objetivo distinto por combate: el cooldown es por par
        // atacante-objetivo, no importa reusar objetivos entre sí.
        $ids = [];
        for ($i = 0; $i < 17; $i++) {
            [$defender] = $this->characterWithToken();
            $ids[] = $defender->id;
        }
        foreach ($ids as $defenderId) {
            $this->withToken($token)->postJson('/api/v1/arena/attack', [
                'defender_character_id' => $defenderId,
            ])->assertStatus(201);
        }

        $page1 = $this->withToken($token)->getJson('/api/v1/arena/combats');
        $page1->assertOk();
        $this->assertCount(15, $page1->json('combats'));
        $this->assertTrue($page1->json('has_more'));

        $lastId = collect($page1->json('combats'))->last()['id'];
        $page2 = $this->withToken($token)->getJson("/api/v1/arena/combats?before_id={$lastId}");
        $page2->assertOk();
        $this->assertCount(2, $page2->json('combats'));
        $this->assertFalse($page2->json('has_more'));

        $allIds = collect($page1->json('combats'))->pluck('id')
            ->merge(collect($page2->json('combats'))->pluck('id'));
        $this->assertCount(17, $allIds->unique());
    }

    public function test_combat_attacked_y_combat_defended_quedan_en_la_actividad_de_cada_uno(): void
    {
        [$attacker, $tokenAttacker] = $this->characterWithToken();
        [$defender, $tokenDefender] = $this->characterWithToken(['strength' => 1, 'agility' => 1, 'vitality' => 1]);

        $combat = $this->withToken($tokenAttacker)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ]);
        $combat->assertStatus(201);
        $winnerIsAttacker = $combat->json('events_json.winner') === 'attacker';

        $this->app['auth']->forgetGuards();

        $attackerActivity = $this->withToken($tokenAttacker)->getJson('/api/v1/activity');
        $attackedEvent = collect($attackerActivity->json())->firstWhere('type', 'combat_attacked');
        $this->assertNotNull($attackedEvent, 'El atacante debe tener un evento combat_attacked.');
        $this->assertEquals($defender->name, $attackedEvent['payload']['opponent_name']);
        $this->assertEquals($winnerIsAttacker ? 'victory' : 'defeat', $attackedEvent['payload']['result']);

        $this->app['auth']->forgetGuards();

        $defenderActivity = $this->withToken($tokenDefender)->getJson('/api/v1/activity');
        $defendedEvent = collect($defenderActivity->json())->firstWhere('type', 'combat_defended');
        $this->assertNotNull($defendedEvent, 'El defensor debe enterarse vía combat_defended sin atacar él mismo.');
        $this->assertEquals($attacker->name, $defendedEvent['payload']['opponent_name']);
        $this->assertEquals($winnerIsAttacker ? 'defeat' : 'victory', $defendedEvent['payload']['result']);
    }

    public function test_show_combate_incluye_apariencia_actual_para_el_boton_repetir(): void
    {
        [, $token] = $this->characterWithToken(['appearance_json' => ['body' => 'base']]);
        [$defender] = $this->characterWithToken(['appearance_json' => ['body' => 'alt']]);

        $response = $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ]);
        $combatId = $response->json('id');

        $replay = $this->withToken($token)->getJson("/api/v1/arena/combats/{$combatId}");
        $replay->assertOk();
        $this->assertEquals(['body' => 'base'], $replay->json('attacker_appearance_json'));
        $this->assertEquals(['body' => 'alt'], $replay->json('defender_appearance_json'));
    }

    // Fase 17, deuda documentada: si el personaje ya no existe (FK
    // nullOnDelete), no se inventa una apariencia -el campo llega en null y
    // el frontend degrada a la vista de solo texto (ver arena.astro,
    // mountBattle/canAnimate).
    public function test_show_combate_devuelve_apariencia_null_si_el_personaje_ya_no_existe(): void
    {
        [, $token] = $this->characterWithToken();
        [$defender] = $this->characterWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $defender->id,
        ]);
        $combatId = $response->json('id');

        $defender->delete();

        $replay = $this->withToken($token)->getJson("/api/v1/arena/combats/{$combatId}");
        $replay->assertOk();
        $this->assertNotNull($replay->json('attacker_appearance_json'));
        $this->assertNull($replay->json('defender_appearance_json'));
    }
}
