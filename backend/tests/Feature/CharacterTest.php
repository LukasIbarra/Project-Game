<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CharacterTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(array $overrides = []): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(array_merge(['user_id' => $user->id], $overrides));
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    public function test_get_character_incluye_exp_to_next_level(): void
    {
        [$character, $token] = $this->characterWithToken(['level' => 1, 'exp' => 40]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/character');

        $response->assertOk();
        $response->assertJson([
            'id' => $character->id,
            'level' => 1,
            'exp' => 40,
            'exp_to_next_level' => 100,
        ]);
    }

    public function test_exp_to_next_level_escala_con_el_nivel(): void
    {
        [, $token] = $this->characterWithToken(['level' => 3, 'exp' => 10]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/character');

        $response->assertOk();
        $response->assertJsonPath('exp_to_next_level', 300);
    }

    public function test_get_character_sin_personaje_devuelve_404(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/character');

        $response->assertStatus(404);
    }

    public function test_get_character_sin_token_devuelve_401(): void
    {
        $response = $this->getJson('/api/v1/character');

        $response->assertStatus(401);
    }
}
