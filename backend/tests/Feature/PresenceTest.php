<?php

namespace Tests\Feature;

use App\Events\PlayerMoved;
use App\Models\Character;
use App\Models\PlayerPresence;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// Fase 18, Paso 2: API de presencia -HTTP + polling, sin Reverb-. "Online"
// se decide siempre por la ventana de 60s sobre last_seen_at, nunca por
// `status` -varios tests de abajo lo verifican explícitamente-.
//
// Fase 19.3: PlayerMoved (Reverb, canal público "world") se agrega acá
// abajo con Event::fake() -nunca conecta a un Reverb real en los tests,
// solo verifica que el controller lo dispare (o no) en el momento correcto
// y con el payload correcto-.
class PresenceTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(array $attrs = []): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(array_merge(['user_id' => $user->id], $attrs));
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    // --- auth:sanctum ---

    public function test_heartbeat_sin_token_devuelve_401(): void
    {
        $this->postJson('/api/v1/presence/heartbeat', ['current_map' => 'home'])
            ->assertStatus(401);
    }

    public function test_get_presence_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/v1/presence')->assertStatus(401);
    }

    // --- Heartbeat ---

    public function test_usuario_autenticado_puede_enviar_heartbeat(): void
    {
        [, $token] = $this->characterWithToken();

        $this->withToken($token)
            ->postJson('/api/v1/presence/heartbeat', ['current_map' => 'home'])
            ->assertNoContent();
    }

    public function test_heartbeat_crea_la_presencia(): void
    {
        [$character, $token] = $this->characterWithToken();

        $this->withToken($token)
            ->postJson('/api/v1/presence/heartbeat', ['current_map' => 'arena'])
            ->assertNoContent();

        $this->assertDatabaseHas('player_presence', [
            'character_id' => $character->id,
            'current_map' => 'arena',
            'status' => 'online',
        ]);
    }

    public function test_segundo_heartbeat_actualiza_la_misma_fila_sin_duplicar(): void
    {
        [$character, $token] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/presence/heartbeat', ['current_map' => 'home'])
            ->assertNoContent();
        $this->withToken($token)->postJson('/api/v1/presence/heartbeat', ['current_map' => 'arena'])
            ->assertNoContent();

        $this->assertDatabaseCount('player_presence', 1);
        $this->assertDatabaseHas('player_presence', [
            'character_id' => $character->id,
            'current_map' => 'arena',
        ]);
    }

    public function test_character_id_siempre_deriva_del_usuario_autenticado(): void
    {
        [$character, $token] = $this->characterWithToken();
        $otro = Character::factory()->create();

        // Un character_id ajeno en el body nunca debe tener efecto -el
        // controller ni siquiera lo lee, siempre usa $request->user()->character.
        $this->withToken($token)->postJson('/api/v1/presence/heartbeat', [
            'current_map' => 'home',
            'character_id' => $otro->id,
        ])->assertNoContent();

        $this->assertDatabaseHas('player_presence', ['character_id' => $character->id]);
        $this->assertDatabaseMissing('player_presence', ['character_id' => $otro->id]);
    }

    public function test_current_map_se_guarda_correctamente(): void
    {
        [$character, $token] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/presence/heartbeat', ['current_map' => 'pet'])
            ->assertNoContent();

        $this->assertDatabaseHas('player_presence', [
            'character_id' => $character->id,
            'current_map' => 'pet',
        ]);
    }

    public function test_current_map_se_normaliza_antes_de_guardar(): void
    {
        [$character, $token] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/presence/heartbeat', ['current_map' => '/Arena/'])
            ->assertNoContent();

        $this->assertDatabaseHas('player_presence', [
            'character_id' => $character->id,
            'current_map' => 'arena',
        ]);
    }

    public function test_current_map_invalido_es_rechazado(): void
    {
        [, $token] = $this->characterWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/presence/heartbeat', [
            'current_map' => 'inventado',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('current_map');
        $this->assertDatabaseCount('player_presence', 0);
    }

    public function test_status_queda_en_online(): void
    {
        [$character, $token] = $this->characterWithToken();

        $this->withToken($token)->postJson('/api/v1/presence/heartbeat', ['current_map' => 'home'])
            ->assertNoContent();

        $this->assertDatabaseHas('player_presence', [
            'character_id' => $character->id,
            'status' => 'online',
        ]);
    }

    // --- GET /presence ---

    public function test_get_devuelve_jugadores_dentro_de_los_60_segundos(): void
    {
        [$character, $token] = $this->characterWithToken(['name' => 'PresenceFresh']);
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now()->subSeconds(30),
            'current_map' => 'home',
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/presence');

        $response->assertOk();
        $entry = collect($response->json())->firstWhere('character_id', $character->id);
        $this->assertNotNull($entry);
        $this->assertEquals('PresenceFresh', $entry['name']);
    }

    public function test_get_excluye_presencia_expirada(): void
    {
        [$character, $token] = $this->characterWithToken(['name' => 'PresenceStale']);
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now()->subSeconds(61),
            'current_map' => 'home',
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/presence');

        $response->assertOk();
        $ids = collect($response->json())->pluck('character_id')->all();
        $this->assertNotContains($character->id, $ids);
    }

    public function test_get_no_usa_status_para_decidir_si_esta_online(): void
    {
        // status='online' pero last_seen_at vencido -no debe aparecer. La
        // ventana temporal es la única fuente de verdad, nunca `status`.
        [$character, $token] = $this->characterWithToken(['name' => 'PresenceOnlineButStale']);
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now()->subSeconds(120),
            'current_map' => 'home',
            'status' => 'online',
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/presence');

        $ids = collect($response->json())->pluck('character_id')->all();
        $this->assertNotContains($character->id, $ids);
    }

    public function test_get_incluye_al_propio_jugador(): void
    {
        [$character, $token] = $this->characterWithToken(['name' => 'PresenceSelf']);
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'current_map' => 'home',
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/presence');

        $ids = collect($response->json())->pluck('character_id')->all();
        $this->assertContains($character->id, $ids);
    }

    public function test_otro_usuario_puede_ver_a_un_jugador_distinto_en_la_lista(): void
    {
        [, $viewerToken] = $this->characterWithToken(['name' => 'PresenceViewer']);
        [$other] = $this->characterWithToken(['name' => 'PresenceOther']);
        PlayerPresence::create([
            'character_id' => $other->id,
            'last_seen_at' => now(),
            'current_map' => 'arena',
        ]);

        $response = $this->withToken($viewerToken)->getJson('/api/v1/presence');

        $response->assertOk();
        $entry = collect($response->json())->firstWhere('character_id', $other->id);
        $this->assertNotNull($entry, 'Un jugador distinto al autenticado debe poder verse en la lista.');
        $this->assertEquals('PresenceOther', $entry['name']);
    }

    public function test_no_hay_duplicados_para_un_mismo_personaje(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'current_map' => 'home',
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/presence');

        $ids = collect($response->json())->pluck('character_id')->all();
        $this->assertSame(1, count(array_keys($ids, $character->id, true)));
    }

    public function test_respuesta_no_expone_campos_adicionales_del_personaje(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'current_map' => 'home',
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/presence');

        $entry = collect($response->json())->firstWhere('character_id', $character->id);
        $this->assertNotNull($entry);
        // Fase 19.2: la forma creció con position_x/position_y/direction
        // (objetivo #9) -sigue sin coins/stats/appearance/inventory.
        $this->assertEqualsCanonicalizing(
            ['character_id', 'name', 'level', 'current_map', 'position_x', 'position_y', 'direction', 'status'],
            array_keys($entry)
        );
    }

    // ============================================================
    // Fase 19.2: POST /v1/presence/position
    // ============================================================

    public function test_post_position_sin_token_devuelve_401(): void
    {
        $this->postJson('/api/v1/presence/position', ['x' => 470, 'y' => 560, 'direction' => 'down'])
            ->assertStatus(401);
    }

    // --- Mapa (objetivo #4) ---

    public function test_position_sin_presencia_devuelve_error_y_no_crea_nada(): void
    {
        [$character, $token] = $this->characterWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 470, 'y' => 560, 'direction' => 'down',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('player_presence', ['character_id' => $character->id]);
        $this->assertDatabaseCount('player_presence', 0);
    }

    public function test_jugador_en_play_puede_actualizar_posicion(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $response = $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 470, 'y' => 560, 'direction' => 'down',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('player_presence', [
            'character_id' => $character->id,
            'position_x' => 470,
            'position_y' => 560,
            'direction' => 'down',
        ]);
    }

    public function test_jugador_fuera_de_play_no_puede_actualizar_posicion(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'home']);

        $response = $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 470, 'y' => 560, 'direction' => 'down',
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseHas('player_presence', [
            'character_id' => $character->id,
            'current_map' => 'home',
            'position_x' => null,
        ]);
    }

    // --- Posición válida (objetivo: guardado + last_seen_at) ---

    public function test_direction_valida_se_guarda_y_responde_solo_xyz_direction(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $response = $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 100, 'y' => 100, 'direction' => 'up',
        ]);

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['x', 'y', 'direction'], array_keys($response->json()));
        $this->assertDatabaseHas('player_presence', ['character_id' => $character->id, 'direction' => 'up']);
    }

    public function test_last_seen_at_se_actualiza_con_una_posicion_valida(): void
    {
        [$character, $token] = $this->characterWithToken();
        $presence = PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now()->subSeconds(50),
            'current_map' => 'play',
        ]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 100, 'y' => 100, 'direction' => 'up',
        ])->assertOk();

        $this->assertTrue($presence->fresh()->last_seen_at->greaterThan(now()->subSeconds(5)));
    }

    // --- Bounds (0<=x<=1280, 0<=y<=800) ---

    public function test_x_negativo_es_rechazado(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $this->withToken($token)->postJson('/api/v1/presence/position', ['x' => -10, 'y' => 560, 'direction' => 'left'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('x');
    }

    public function test_x_mayor_al_ancho_del_mapa_es_rechazado(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $this->withToken($token)->postJson('/api/v1/presence/position', ['x' => 1300, 'y' => 560, 'direction' => 'right'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('x');
    }

    public function test_y_negativo_es_rechazado(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $this->withToken($token)->postJson('/api/v1/presence/position', ['x' => 470, 'y' => -5, 'direction' => 'up'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('y');
    }

    public function test_y_mayor_al_alto_del_mapa_es_rechazado(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $this->withToken($token)->postJson('/api/v1/presence/position', ['x' => 470, 'y' => 900, 'direction' => 'down'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('y');
    }

    // --- Direction ---

    public function test_las_4_direcciones_cardinales_son_validas(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        foreach (['up', 'down', 'left', 'right'] as $direction) {
            $this->withToken($token)->postJson('/api/v1/presence/position', [
                'x' => 100, 'y' => 100, 'direction' => $direction,
            ])->assertOk();
        }
    }

    public function test_direction_diagonal_es_rechazada(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 100, 'y' => 100, 'direction' => 'up-right',
        ])->assertUnprocessable()->assertJsonValidationErrors('direction');
    }

    public function test_direction_arbitraria_es_rechazada(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 100, 'y' => 100, 'direction' => 'norte',
        ])->assertUnprocessable()->assertJsonValidationErrors('direction');
    }

    // --- Seguridad ---

    public function test_character_id_en_body_no_modifica_la_presencia_de_otro_personaje(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        [$other] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $other->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 200, 'y' => 200, 'direction' => 'up',
            'character_id' => $other->id,
        ])->assertOk();

        $this->assertDatabaseHas('player_presence', [
            'character_id' => $character->id,
            'position_x' => 200,
            'position_y' => 200,
        ]);
        $this->assertDatabaseMissing('player_presence', [
            'character_id' => $other->id,
            'position_x' => 200,
        ]);
    }

    // --- Movimiento plausible (objetivo #5) ---
    // last_seen_at es TIMESTAMP sin fracción de segundo en MySQL -se usan
    // offsets de segundos enteros a propósito, nunca milisegundos ni sleep
    // real, para que el cálculo sea 100% determinístico-.

    public function test_primer_envio_sin_posicion_previa_es_valido(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'current_map' => 'play',
            // position_x/position_y quedan null a propósito: sin
            // posición anterior no hay nada contra qué medir plausibilidad.
        ]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 700, 'y' => 300, 'direction' => 'down',
        ])->assertOk();
    }

    public function test_movimiento_pequeno_en_tiempo_razonable_es_valido(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now()->subSecond(),
            'current_map' => 'play',
            'position_x' => 470,
            'position_y' => 560,
        ]);

        // Presupuesto a ~1s: 70*1*1.5+8=113px. Moverse 60px es holgadamente
        // plausible (un paso normal a SPEED=70px/s en un segundo).
        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 530, 'y' => 560, 'direction' => 'right',
        ])->assertOk();
    }

    public function test_salto_claramente_imposible_es_rechazado(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now()->subSecond(),
            'current_map' => 'play',
            'position_x' => 100,
            'position_y' => 100,
        ]);

        // 900px en ~1s -muy por encima del presupuesto (~113px) a esa
        // velocidad, sin importar el margen de tolerancia.
        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 1000, 'y' => 100, 'direction' => 'right',
        ])->assertStatus(422);
    }

    public function test_un_intervalo_mayor_permite_una_distancia_proporcionalmente_mayor(): void
    {
        // Misma distancia (300px), dos intervalos distintos: a ~1s debe
        // rechazarse (presupuesto ~113px), a ~5s debe aceptarse
        // (presupuesto 70*5*1.5+8=533px) -prueba directa de la
        // proporcionalidad pedida.
        [$rapido, $tokenRapido] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $rapido->id,
            'last_seen_at' => now()->subSecond(),
            'current_map' => 'play',
            'position_x' => 100,
            'position_y' => 100,
        ]);

        $this->withToken($tokenRapido)->postJson('/api/v1/presence/position', [
            'x' => 400, 'y' => 100, 'direction' => 'right',
        ])->assertStatus(422);

        // El guard de Sanctum cachea el usuario resuelto entre requests
        // dentro de un mismo test -mismo gotcha ya documentado en
        // RankingTest-: sin esto, el segundo request seguiría autenticado
        // como $tokenRapido aunque se pida $tokenLento.
        $this->app['auth']->forgetGuards();

        [$lento, $tokenLento] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $lento->id,
            'last_seen_at' => now()->subSeconds(5),
            'current_map' => 'play',
            'position_x' => 100,
            'position_y' => 100,
        ]);

        $this->withToken($tokenLento)->postJson('/api/v1/presence/position', [
            'x' => 400, 'y' => 100, 'direction' => 'right',
        ])->assertOk();
    }

    public function test_pequena_tolerancia_de_latencia_no_produce_falso_positivo(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now()->subSecond(),
            'current_map' => 'play',
            'position_x' => 100,
            'position_y' => 100,
        ]);

        // Distancia teórica exacta a 70px/s en 1s = 70px. Un jugador
        // legítimo con jitter de red puede reportar algo más (90px, +28%)
        // sin que sea un salto imposible -el margen (x1.5 + 8px) debe
        // absorber esto, no rechazarlo.
        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 190, 'y' => 100, 'direction' => 'right',
        ])->assertOk();
    }

    // ============================================================
    // Fase 19.2: GET /v1/presence?map=
    // ============================================================

    public function test_get_map_play_devuelve_solo_jugadores_en_mundo(): void
    {
        [$character, $token] = $this->characterWithToken(['name' => 'EnMundo']);
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        [$other] = $this->characterWithToken(['name' => 'EnHome']);
        PlayerPresence::create(['character_id' => $other->id, 'last_seen_at' => now(), 'current_map' => 'home']);

        $response = $this->withToken($token)->getJson('/api/v1/presence?map=play');

        $response->assertOk();
        $ids = collect($response->json())->pluck('character_id')->all();
        $this->assertContains($character->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_get_map_play_mantiene_el_filtro_de_60_segundos(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now()->subSeconds(61),
            'current_map' => 'play',
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/presence?map=play');

        $ids = collect($response->json())->pluck('character_id')->all();
        $this->assertNotContains($character->id, $ids);
    }

    public function test_get_map_play_incluye_al_propio_jugador(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $response = $this->withToken($token)->getJson('/api/v1/presence?map=play');

        $ids = collect($response->json())->pluck('character_id')->all();
        $this->assertContains($character->id, $ids);
    }

    public function test_get_devuelve_position_x_position_y_y_direction(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'current_map' => 'play',
            'position_x' => 470,
            'position_y' => 560,
            'direction' => 'down',
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/presence?map=play');

        // assertEquals (no assertSame): 470.0 sin parte fraccionaria viaja
        // por JSON como el entero 470 -mismo valor numérico, JS/JSON no
        // distingue int de float-, assertSame exigiría el tipo PHP exacto.
        $entry = collect($response->json())->firstWhere('character_id', $character->id);
        $this->assertEquals(470.0, $entry['position_x']);
        $this->assertEquals(560.0, $entry['position_y']);
        $this->assertSame('down', $entry['direction']);
    }

    public function test_jugadores_con_posicion_null_siguen_siendo_representables(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'current_map' => 'play',
            // Nunca mandó una posición real todavía.
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/presence?map=play');

        $response->assertOk();
        $entry = collect($response->json())->firstWhere('character_id', $character->id);
        $this->assertNotNull($entry);
        $this->assertNull($entry['position_x']);
        $this->assertNull($entry['position_y']);
        $this->assertNull($entry['direction']);
    }

    public function test_get_sin_map_mantiene_el_comportamiento_de_f18(): void
    {
        [$character, $token] = $this->characterWithToken(['name' => 'SinFiltro']);
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        [$other] = $this->characterWithToken(['name' => 'SinFiltroHome']);
        PlayerPresence::create(['character_id' => $other->id, 'last_seen_at' => now(), 'current_map' => 'home']);

        $response = $this->withToken($token)->getJson('/api/v1/presence');

        $ids = collect($response->json())->pluck('character_id')->all();
        $this->assertContains($character->id, $ids);
        $this->assertContains($other->id, $ids);
    }

    public function test_get_con_map_invalido_devuelve_422(): void
    {
        [, $token] = $this->characterWithToken();

        $this->withToken($token)->getJson('/api/v1/presence?map=inventado')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('map');
    }

    public function test_get_map_con_mayusculas_y_espacios_se_normaliza_igual_que_f18(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $response = $this->withToken($token)->getJson('/api/v1/presence?map=' . urlencode('/Play/'));

        $response->assertOk();
        $ids = collect($response->json())->pluck('character_id')->all();
        $this->assertContains($character->id, $ids);
    }

    // ============================================================
    // Fase 19.3: PlayerMoved (Reverb, canal público "world")
    // ============================================================

    public function test_posicion_valida_dispara_playermoved_con_el_payload_correcto(): void
    {
        Event::fake([PlayerMoved::class]);

        [$character, $token] = $this->characterWithToken(['name' => 'Lukas', 'level' => 27]);
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 470, 'y' => 560, 'direction' => 'down',
        ])->assertOk();

        Event::assertDispatched(PlayerMoved::class, function (PlayerMoved $event) use ($character) {
            // Objetivo #3: exactamente estas 6 claves -nunca coins/stats/
            // appearance/inventory/equipment/last_seen_at/status/current_map.
            return $event->broadcastWith() === [
                'character_id' => $character->id,
                'name' => 'Lukas',
                'level' => 27,
                'x' => 470.0,
                'y' => 560.0,
                'direction' => 'down',
            ];
        });
    }

    public function test_playermoved_usa_shouldbroadcastnow_nombre_explicito_y_canal_publico_world(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        Event::fake([PlayerMoved::class]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 1, 'y' => 1, 'direction' => 'up',
        ])->assertOk();

        Event::assertDispatched(PlayerMoved::class, function (PlayerMoved $event) {
            $this->assertInstanceOf(\Illuminate\Contracts\Broadcasting\ShouldBroadcastNow::class, $event);
            $this->assertSame('PlayerMoved', $event->broadcastAs());

            $channel = $event->broadcastOn();
            // Channel público real -ni PrivateChannel ni PresenceChannel
            // (ambas EXTIENDEN Channel, por eso el chequeo negativo además
            // del positivo), sin entrada en routes/channels.php.
            $this->assertInstanceOf(Channel::class, $channel);
            $this->assertNotInstanceOf(PrivateChannel::class, $channel);
            $this->assertNotInstanceOf(PresenceChannel::class, $channel);
            $this->assertSame('world', $channel->name);

            return true;
        });
    }

    public function test_no_emite_playermoved_si_x_esta_fuera_de_bounds(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        Event::fake([PlayerMoved::class]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => -10, 'y' => 560, 'direction' => 'left',
        ])->assertUnprocessable();

        Event::assertNotDispatched(PlayerMoved::class);
    }

    public function test_no_emite_playermoved_si_y_esta_fuera_de_bounds(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        Event::fake([PlayerMoved::class]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 470, 'y' => 900, 'direction' => 'down',
        ])->assertUnprocessable();

        Event::assertNotDispatched(PlayerMoved::class);
    }

    public function test_no_emite_playermoved_si_direction_es_invalida(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

        Event::fake([PlayerMoved::class]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 100, 'y' => 100, 'direction' => 'up-right',
        ])->assertUnprocessable();

        Event::assertNotDispatched(PlayerMoved::class);
    }

    public function test_no_emite_playermoved_si_no_existe_presencia(): void
    {
        [, $token] = $this->characterWithToken();

        Event::fake([PlayerMoved::class]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 100, 'y' => 100, 'direction' => 'up',
        ])->assertStatus(404);

        Event::assertNotDispatched(PlayerMoved::class);
    }

    public function test_no_emite_playermoved_si_current_map_no_es_play(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'home']);

        Event::fake([PlayerMoved::class]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 100, 'y' => 100, 'direction' => 'up',
        ])->assertStatus(409);

        Event::assertNotDispatched(PlayerMoved::class);
    }

    public function test_no_emite_playermoved_si_el_movimiento_no_es_plausible(): void
    {
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now()->subSecond(),
            'current_map' => 'play',
            'position_x' => 100,
            'position_y' => 100,
        ]);

        Event::fake([PlayerMoved::class]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 1000, 'y' => 100, 'direction' => 'right',
        ])->assertStatus(422);

        Event::assertNotDispatched(PlayerMoved::class);
    }

    public function test_posicion_identica_actualiza_last_seen_at_pero_no_emite_evento_redundante(): void
    {
        [$character, $token] = $this->characterWithToken();
        $presence = PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now()->subSeconds(10),
            'current_map' => 'play',
            'position_x' => 470,
            'position_y' => 560,
            'direction' => 'down',
        ]);

        Event::fake([PlayerMoved::class]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 470, 'y' => 560, 'direction' => 'down',
        ])->assertOk();

        Event::assertNotDispatched(PlayerMoved::class);
        // La respuesta HTTP y last_seen_at siguen siendo correctos aunque
        // no haya broadcast (objetivo #8).
        $this->assertTrue($presence->fresh()->last_seen_at->greaterThan(now()->subSeconds(5)));
    }

    public function test_cambiar_solo_direction_sin_mover_x_y_si_dispara_playermoved(): void
    {
        // El objetivo #8 dice explícitamente "x, y, direction" -cambiar
        // SOLO la dirección (girar sin moverse) es un cambio real, debe
        // emitir igual.
        [$character, $token] = $this->characterWithToken();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'current_map' => 'play',
            'position_x' => 470,
            'position_y' => 560,
            'direction' => 'down',
        ]);

        Event::fake([PlayerMoved::class]);

        $this->withToken($token)->postJson('/api/v1/presence/position', [
            'x' => 470, 'y' => 560, 'direction' => 'up',
        ])->assertOk();

        Event::assertDispatched(PlayerMoved::class);
    }

    // --- Rate limit dedicado (objetivo #9) ---
    // Override determinístico del limiter con un número chico -nunca
    // cientos de requests reales-, restaurado en un finally para no
    // afectar otros tests de esta clase (RateLimiter::for() vive en el
    // mismo proceso de PHPUnit, no se resetea solo entre tests).

    private function overridePresencePositionLimiter(int $perMinute): void
    {
        RateLimiter::for('presence.position', function (Request $request) use ($perMinute) {
            return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
        });
    }

    private function restorePresencePositionLimiter(): void
    {
        $this->overridePresencePositionLimiter(240);
    }

    public function test_un_usuario_puede_hacer_el_numero_esperado_de_requests_y_el_exceso_devuelve_429(): void
    {
        $this->overridePresencePositionLimiter(2);

        try {
            [$character, $token] = $this->characterWithToken();
            PlayerPresence::create(['character_id' => $character->id, 'last_seen_at' => now(), 'current_map' => 'play']);

            $this->withToken($token)->postJson('/api/v1/presence/position', ['x' => 10, 'y' => 10, 'direction' => 'up'])
                ->assertOk();
            $this->withToken($token)->postJson('/api/v1/presence/position', ['x' => 11, 'y' => 10, 'direction' => 'up'])
                ->assertOk();
            $this->withToken($token)->postJson('/api/v1/presence/position', ['x' => 12, 'y' => 10, 'direction' => 'up'])
                ->assertStatus(429);
        } finally {
            $this->restorePresencePositionLimiter();
        }
    }

    public function test_el_rate_limit_no_se_comparte_entre_usuarios(): void
    {
        $this->overridePresencePositionLimiter(1);

        try {
            [$characterA, $tokenA] = $this->characterWithToken();
            PlayerPresence::create(['character_id' => $characterA->id, 'last_seen_at' => now(), 'current_map' => 'play']);

            $this->withToken($tokenA)->postJson('/api/v1/presence/position', ['x' => 10, 'y' => 10, 'direction' => 'up'])
                ->assertOk();
            $this->withToken($tokenA)->postJson('/api/v1/presence/position', ['x' => 11, 'y' => 10, 'direction' => 'up'])
                ->assertStatus(429);

            $this->app['auth']->forgetGuards();

            [$characterB, $tokenB] = $this->characterWithToken();
            PlayerPresence::create(['character_id' => $characterB->id, 'last_seen_at' => now(), 'current_map' => 'play']);

            // El cupo de A está agotado, pero B tiene el suyo propio -no
            // debe compartir el mismo contador (objetivo #9).
            $this->withToken($tokenB)->postJson('/api/v1/presence/position', ['x' => 20, 'y' => 20, 'direction' => 'up'])
                ->assertOk();
        } finally {
            $this->restorePresencePositionLimiter();
        }
    }
}
