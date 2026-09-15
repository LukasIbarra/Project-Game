<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Fase 13: GET /v1/ranking. Cero lógica de ranking propia acá -estos tests
// verifican que el endpoint expone correctamente lo que
// ArenaRankingService ya calculaba (y que ArenaTest ya prueba a nivel de
// servicio vía GET /arena): orden, posición, identificación del jugador
// actual, y que nunca aparece nada que no exista en `characters`.
class RankingTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(array $attrs = []): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(array_merge(['user_id' => $user->id], $attrs));
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    public function test_get_ranking_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/v1/ranking')->assertStatus(401);
    }

    public function test_un_jugador_aparece_correctamente(): void
    {
        // Determinístico frente a la DB de desarrollo compartida -mismo
        // criterio que el resto de los tests de esta clase-: sin esto, un
        // personaje nuevo de nivel bajo podría quedar fuera del TOP 10 si
        // ya existen suficientes personajes reales con más nivel/wins.
        Character::query()->delete();

        [$character, $token] = $this->characterWithToken(['name' => 'SoloRanking', 'level' => 3]);

        $response = $this->withToken($token)->getJson('/api/v1/ranking');

        $response->assertOk();
        $entry = collect($response->json('ranking'))->firstWhere('character_id', $character->id);
        $this->assertNotNull($entry, 'El personaje recién creado debe aparecer en el ranking.');
        $this->assertEquals('SoloRanking', $entry['name']);
        $this->assertEquals(3, $entry['level']);
        $this->assertEquals(0, $entry['wins']);
        $this->assertEquals(0, $entry['losses']);
    }

    public function test_varios_jugadores_aparecen_ordenados_por_victorias_y_nivel(): void
    {
        // topRanking() trae un TOP 10 -en la DB de desarrollo compartida
        // puede haber de sobra otros personajes con victorias reales que
        // saquen a estos dos del top-. Se vacía la tabla DENTRO de esta
        // transacción (mismo criterio que el test de ranking vacío) para
        // que el orden esperado sea determinístico sin importar qué haya
        // en la DB real.
        Character::query()->delete();

        [$strong, $tokenStrong] = $this->characterWithToken([
            'name' => 'RankStrong', 'level' => 1, 'strength' => 50, 'agility' => 50, 'vitality' => 50,
        ]);
        [$weak] = $this->characterWithToken([
            'name' => 'RankWeak', 'level' => 1, 'strength' => 1, 'agility' => 1, 'vitality' => 1,
        ]);

        // Fuerza una victoria real de $strong contra $weak (mismo mecanismo
        // que ArenaTest::test_el_ganador_queda_registrado_correctamente).
        $this->withToken($tokenStrong)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $weak->id,
        ])->assertStatus(201);

        $response = $this->withToken($tokenStrong)->getJson('/api/v1/ranking');
        $response->assertOk();

        $names = collect($response->json('ranking'))->pluck('name')->all();
        $strongIndex = array_search('RankStrong', $names, true);
        $weakIndex = array_search('RankWeak', $names, true);

        $this->assertNotFalse($strongIndex);
        $this->assertNotFalse($weakIndex);
        // RankStrong ganó -debe quedar antes que RankWeak en el orden real
        // devuelto por el endpoint (wins desc, mismo criterio del servicio).
        $this->assertLessThan($weakIndex, $strongIndex);
    }

    public function test_la_posicion_me_rank_corresponde_al_orden_real(): void
    {
        Character::query()->delete();

        [$weaker, $tokenWeaker] = $this->characterWithToken(['name' => 'RankPositionWeaker', 'level' => 1]);
        [, $tokenStronger] = $this->characterWithToken(['name' => 'RankPositionStronger', 'level' => 1, 'strength' => 50, 'agility' => 50, 'vitality' => 50]);

        // $weaker pierde a propósito -su rank real debe quedar en #2, no #1-.
        $this->withToken($tokenStronger)->postJson('/api/v1/arena/attack', [
            'defender_character_id' => $weaker->id,
        ])->assertStatus(201);

        // Mismo gotcha ya documentado en ArenaTest: el guard de Sanctum
        // cachea el usuario resuelto entre requests dentro de un mismo
        // test -sin esto, este segundo request seguiría autenticado como
        // $tokenStronger aunque se pida explícitamente $tokenWeaker-.
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($tokenWeaker)->getJson('/api/v1/ranking');
        $response->assertOk();

        $names = collect($response->json('ranking'))->pluck('character_id')->all();
        $expectedIndex = array_search($weaker->id, $names, true);

        $this->assertNotFalse($expectedIndex, 'El personaje debe estar en el top devuelto -la tabla se vació antes-.');
        // "me.rank" es 1-based; el índice del array es 0-based.
        $this->assertEquals($expectedIndex + 1, $response->json('me.rank'));
        $this->assertEquals(2, $response->json('me.rank'));
    }

    public function test_me_identifica_al_personaje_autenticado(): void
    {
        [$character, $token] = $this->characterWithToken(['name' => 'RankMe']);

        $response = $this->withToken($token)->getJson('/api/v1/ranking');

        $response->assertOk();
        $response->assertJsonPath('me.character_id', $character->id);
        $response->assertJsonStructure(['me' => ['character_id', 'rank', 'wins', 'losses']]);
    }

    public function test_no_aparecen_jugadores_falsos(): void
    {
        [, $token] = $this->characterWithToken();

        $response = $this->withToken($token)->getJson('/api/v1/ranking');
        $response->assertOk();

        $ids = collect($response->json('ranking'))->pluck('character_id')->all();
        $realIds = Character::whereIn('id', $ids)->pluck('id')->all();

        // Todo id devuelto tiene que existir de verdad en `characters` -ni
        // uno más, ni uno de menos-.
        sort($ids);
        sort($realIds);
        $this->assertEquals($ids, $realIds);
    }

    public function test_ranking_vacio_devuelve_array_vacio_y_me_null_sin_personaje(): void
    {
        // Vacía `characters` DENTRO de esta transacción -DatabaseTransactions
        // revierte todo al terminar el test, nunca toca datos reales fuera
        // de este proceso-. El usuario de este test nunca tiene personaje
        // (no pasa por AuthController::register, que es el único lugar que
        // lo crea automáticamente).
        Character::query()->delete();

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/ranking');

        $response->assertOk();
        $response->assertExactJson(['ranking' => [], 'me' => null]);
    }
}
