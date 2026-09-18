<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PlayerPresence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Fase 18, Paso 1: solo DB + modelo -sin controller/rutas todavía-. No hay
// heartbeat real acá, solo se verifica que la tabla/modelo se comporten
// como exige el diseño (una fila por personaje, cascade, persistencia de
// los 3 campos que importan).
class PlayerPresenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_un_personaje_solo_puede_tener_una_fila_de_presencia(): void
    {
        $character = Character::factory()->create();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
        ]);
    }

    public function test_la_relacion_con_character_funciona_en_ambos_sentidos(): void
    {
        $character = Character::factory()->create();
        $presence = PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
        ]);

        $this->assertTrue($character->presence->is($presence));
        $this->assertTrue($presence->character->is($character));
    }

    public function test_cascade_delete_borra_la_presencia_al_borrar_el_personaje(): void
    {
        $character = Character::factory()->create();
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
        ]);

        $character->delete();

        $this->assertDatabaseMissing('player_presence', ['character_id' => $character->id]);
    }

    public function test_last_seen_at_current_map_y_status_se_persisten_correctamente(): void
    {
        $character = Character::factory()->create();
        $lastSeen = Carbon::parse('2026-09-18 12:00:00');

        $presence = PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => $lastSeen,
            'current_map' => 'arena',
            'status' => 'online',
        ]);

        $this->assertDatabaseHas('player_presence', [
            'character_id' => $character->id,
            'current_map' => 'arena',
            'status' => 'online',
        ]);

        $fresh = $presence->fresh();
        $this->assertTrue($fresh->last_seen_at->equalTo($lastSeen));
        $this->assertSame('arena', $fresh->current_map);
        $this->assertSame('online', $fresh->status);
    }

    public function test_status_tiene_default_online_si_no_se_manda(): void
    {
        $character = Character::factory()->create();

        $presence = PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
        ]);

        $this->assertSame('online', $presence->fresh()->status);
    }

    // Fase 19.1: solo columnas + modelo -sin endpoint todavía (ese es
    // F19.2)-. position_x/position_y usan spawn real de Mundo (470/560,
    // ver WorldScene.ts) para que el test documente un valor con sentido,
    // no un número cualquiera.

    public function test_una_presencia_puede_tener_position_x(): void
    {
        $character = Character::factory()->create();

        $presence = PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'position_x' => 470,
        ]);

        $this->assertSame(470.0, $presence->fresh()->position_x);
    }

    public function test_una_presencia_puede_tener_position_y(): void
    {
        $character = Character::factory()->create();

        $presence = PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'position_y' => 560,
        ]);

        $this->assertSame(560.0, $presence->fresh()->position_y);
    }

    public function test_una_presencia_puede_tener_direction(): void
    {
        $character = Character::factory()->create();

        $presence = PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'direction' => 'down',
        ]);

        $this->assertSame('down', $presence->fresh()->direction);
    }

    public function test_position_x_position_y_y_direction_aceptan_null(): void
    {
        $character = Character::factory()->create();

        // Ni siquiera se mandan -mismo caso real: una presencia creada por
        // un heartbeat fuera de Mundo, que todavía nunca mandó posición.
        $presence = PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
        ]);

        $fresh = $presence->fresh();
        $this->assertNull($fresh->position_x);
        $this->assertNull($fresh->position_y);
        $this->assertNull($fresh->direction);
    }

    public function test_position_x_position_y_y_direction_se_persisten_juntos_y_la_relacion_sigue_funcionando(): void
    {
        $character = Character::factory()->create();

        $presence = PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
            'current_map' => 'play',
            'position_x' => 470,
            'position_y' => 560,
            'direction' => 'down',
        ]);

        $this->assertDatabaseHas('player_presence', [
            'character_id' => $character->id,
            'current_map' => 'play',
            'position_x' => 470,
            'position_y' => 560,
            'direction' => 'down',
        ]);

        // Fase 19.1 no debe romper nada de lo que F18 ya garantizaba: la
        // relación en ambos sentidos y el unique de character_id.
        $this->assertTrue($character->presence->is($presence));
        $this->assertTrue($presence->character->is($character));

        $this->expectException(QueryException::class);
        PlayerPresence::create([
            'character_id' => $character->id,
            'last_seen_at' => now(),
        ]);
    }
}
