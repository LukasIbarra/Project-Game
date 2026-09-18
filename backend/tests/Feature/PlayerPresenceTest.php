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
}
