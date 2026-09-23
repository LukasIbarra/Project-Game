<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PetTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    public function test_personaje_sin_mascota_recibe_una_al_consultar_get_pet(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->assertNull($character->fresh()->pet);

        $response = $this->withToken($token)->getJson('/api/v1/pet');

        $response->assertOk();
        $response->assertJsonPath('name', 'Compañero');
        $response->assertJsonPath('species', 'starter');
        $response->assertJsonPath('status', 'idle');
        $response->assertJsonPath('health', 100);
        $this->assertNotNull($character->fresh()->pet);
    }

    public function test_consultar_get_pet_dos_veces_no_duplica_la_mascota(): void
    {
        [$character, $token] = $this->characterWithToken();

        $first = $this->withToken($token)->getJson('/api/v1/pet')->json('id');
        $second = $this->withToken($token)->getJson('/api/v1/pet')->json('id');

        $this->assertEquals($first, $second);
        $this->assertEquals(1, \App\Models\Pet::where('character_id', $character->id)->count());
    }

    public function test_usuario_solo_puede_consultar_su_propia_mascota(): void
    {
        [$characterA, $tokenA] = $this->characterWithToken();
        [$characterB, $tokenB] = $this->characterWithToken();

        $petAId = $this->withToken($tokenA)->getJson('/api/v1/pet')->json('id');

        $this->app['auth']->forgetGuards();

        $petBId = $this->withToken($tokenB)->getJson('/api/v1/pet')->json('id');

        $this->assertNotEquals($petAId, $petBId);
    }

    // F21: reemplaza /pet/destinations -catálogo de expediciones definitivo
    // (6, ver docs/PETS_EXPEDITIONS_SYSTEM.md §9.1 y ExpeditionDefinitionSeeder).
    public function test_definiciones_de_expedicion_vienen_del_catalogo_sembrado(): void
    {
        [, $token] = $this->characterWithToken();

        $response = $this->withToken($token)->getJson('/api/v1/pet/expeditions/definitions');

        $response->assertOk();
        $keys = array_column($response->json(), 'key');
        $this->assertEqualsCanonicalizing(
            ['forest', 'windy_hills', 'mountains', 'ancient_ruins', 'cursed_swamp', 'blood_castle'],
            $keys
        );
    }
}
