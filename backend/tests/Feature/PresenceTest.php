<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PlayerPresence;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Fase 18, Paso 2: API de presencia -HTTP + polling, sin Reverb-. "Online"
// se decide siempre por la ventana de 60s sobre last_seen_at, nunca por
// `status` -varios tests de abajo lo verifican explícitamente-.
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
        $this->assertEqualsCanonicalizing(
            ['character_id', 'name', 'level', 'current_map', 'status'],
            array_keys($entry)
        );
    }
}
