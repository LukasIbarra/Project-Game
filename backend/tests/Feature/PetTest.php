<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// F23: GET /pet ya NO auto-crea ninguna mascota -el registro tampoco. Un
// personaje sin mascota activa debe pasar por selección inicial
// (POST /pet/adopt, ver PetAdoptionTest.php para el flujo completo de
// adopción/compra/colección).
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

    public function test_personaje_nuevo_no_recibe_mascota_automatica(): void
    {
        [$character, $token] = $this->characterWithToken();
        $this->assertNull($character->fresh()->activePet);

        $response = $this->withToken($token)->getJson('/api/v1/pet');

        $response->assertStatus(404);
        $this->assertNull($character->fresh()->activePet);
        $this->assertSame(0, $character->fresh()->pets()->count());
    }

    public function test_usuario_solo_puede_consultar_su_propia_mascota(): void
    {
        [, $tokenA] = $this->characterWithToken();
        [, $tokenB] = $this->characterWithToken();

        $petAId = $this->withToken($tokenA)->postJson('/api/v1/pet/adopt', ['species_key' => 'kitsu'])->json('id');

        $this->app['auth']->forgetGuards();

        $petBId = $this->withToken($tokenB)->postJson('/api/v1/pet/adopt', ['species_key' => 'lumio'])->json('id');

        $this->assertNotEquals($petAId, $petBId);

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenA)->getJson('/api/v1/pet')->assertJsonPath('id', $petAId);
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
