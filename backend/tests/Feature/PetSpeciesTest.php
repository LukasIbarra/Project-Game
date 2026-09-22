<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Pet;
use App\Models\PetSpecies;
use App\Models\User;
use App\Services\PetModifierResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Fase 20: pet_species + migración de pets.key -> pets.species_id +
// PetModifierResolver. Nada de expediciones/checkpoints acá (eso es F21).
class PetSpeciesTest extends TestCase
{
    use DatabaseTransactions;

    private function characterWithToken(): array
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$character, $token];
    }

    // --- Migración / integridad referencial ---

    public function test_la_especie_starter_existe_y_esta_activa(): void
    {
        $starter = PetSpecies::where('key', 'starter')->first();

        $this->assertNotNull($starter, 'La migración de backfill debe haber creado la especie starter.');
        $this->assertTrue($starter->is_active);
    }

    public function test_una_mascota_nueva_queda_asociada_a_la_especie_starter(): void
    {
        [, $token] = $this->characterWithToken();

        $petId = $this->withToken($token)->getJson('/api/v1/pet')->json('id');
        $pet = Pet::findOrFail($petId);

        $this->assertNotNull($pet->species_id);
        $this->assertSame('starter', $pet->species->key);
    }

    public function test_relacion_pet_species_funciona_en_ambos_sentidos(): void
    {
        $starter = PetSpecies::where('key', 'starter')->firstOrFail();
        [$character] = $this->characterWithToken();
        $pet = Pet::create([
            'character_id' => $character->id,
            'species_id' => $starter->id,
            'name' => 'Test',
            'level' => 1,
            'exp' => 0,
            'health' => 100,
            'max_health' => 100,
            'energy' => 100,
            'max_energy' => 100,
            'status' => 'idle',
        ]);

        $this->assertTrue($pet->species->is($starter));
        $this->assertTrue($starter->pets->contains(fn (Pet $p) => $p->is($pet)));
    }

    public function test_get_pet_expone_species_y_species_name(): void
    {
        [, $token] = $this->characterWithToken();

        $response = $this->withToken($token)->getJson('/api/v1/pet');

        $response->assertOk();
        $response->assertJsonPath('species', 'starter');
        $response->assertJsonPath('species_name', 'Compañero');
    }

    public function test_seed_deja_aproximadamente_20_especies_activas(): void
    {
        // No es un número mágico exacto -el pedido de la fase es
        // "aproximadamente 20"-, pero confirma que el seeder realmente
        // pobló contenido y no solo la especie starter de la migración.
        $this->assertGreaterThanOrEqual(18, PetSpecies::where('is_active', true)->count());
    }

    // --- PetModifierResolver ---

    private function speciesWithModifiers(array $modifiers, array $levelModifiers = []): PetSpecies
    {
        return PetSpecies::create([
            'key' => 'test_species_' . uniqid(),
            'name' => 'Especie de prueba',
            'rarity' => 'common',
            'modifiers_json' => $modifiers,
            'level_modifiers_json' => $levelModifiers,
            'is_active' => true,
        ]);
    }

    public function test_resolver_devuelve_los_modificadores_base_sin_milestones(): void
    {
        $species = $this->speciesWithModifiers([
            ['type' => 'loot_bonus_pct', 'scope' => 'destination', 'target' => 'blood_castle', 'value' => 10],
        ]);

        $result = (new PetModifierResolver())->resolve($species, 1);

        $this->assertCount(1, $result);
        $this->assertSame('loot_bonus_pct', $result[0]['type']);
        $this->assertSame('blood_castle', $result[0]['target']);
        $this->assertEquals(10.0, $result[0]['value']);
    }

    public function test_milestone_exacto_reemplaza_los_modificadores_base(): void
    {
        $species = $this->speciesWithModifiers(
            [['type' => 'loot_bonus_pct', 'scope' => 'global', 'value' => 10]],
            [['level' => 5, 'modifiers' => [['type' => 'loot_bonus_pct', 'scope' => 'global', 'value' => 12]]]]
        );

        $result = (new PetModifierResolver())->resolve($species, 5);

        $this->assertCount(1, $result);
        $this->assertEquals(12.0, $result[0]['value']);
    }

    public function test_nivel_entre_dos_milestones_usa_el_menor_que_todavia_aplica(): void
    {
        $species = $this->speciesWithModifiers(
            [['type' => 'loot_bonus_pct', 'scope' => 'global', 'value' => 10]],
            [
                ['level' => 5, 'modifiers' => [['type' => 'loot_bonus_pct', 'scope' => 'global', 'value' => 12]]],
                ['level' => 10, 'modifiers' => [['type' => 'loot_bonus_pct', 'scope' => 'global', 'value' => 15]]],
            ]
        );

        // Nivel 7: ya pasó el milestone de 5, todavía no llega al de 10.
        $result = (new PetModifierResolver())->resolve($species, 7);

        $this->assertEquals(12.0, $result[0]['value']);
    }

    public function test_sin_milestone_aplicable_usa_los_modificadores_base(): void
    {
        $species = $this->speciesWithModifiers(
            [['type' => 'loot_bonus_pct', 'scope' => 'global', 'value' => 10]],
            [['level' => 5, 'modifiers' => [['type' => 'loot_bonus_pct', 'scope' => 'global', 'value' => 12]]]]
        );

        // Nivel 1: ningún milestone (el primero es a partir de 5) todavía aplica.
        $result = (new PetModifierResolver())->resolve($species, 1);

        $this->assertEquals(10.0, $result[0]['value']);
    }

    public function test_tipo_de_modificador_desconocido_se_ignora_sin_romper(): void
    {
        $species = $this->speciesWithModifiers([
            ['type' => 'algo_que_no_existe', 'scope' => 'global', 'value' => 99],
            ['type' => 'loot_bonus_pct', 'scope' => 'global', 'value' => 10],
        ]);

        $result = (new PetModifierResolver())->resolve($species, 1);

        $this->assertCount(1, $result);
        $this->assertSame('loot_bonus_pct', $result[0]['type']);
    }

    public function test_resolve_for_pet_usa_el_nivel_real_de_la_mascota(): void
    {
        $species = $this->speciesWithModifiers(
            [['type' => 'loot_bonus_pct', 'scope' => 'global', 'value' => 10]],
            [['level' => 10, 'modifiers' => [['type' => 'loot_bonus_pct', 'scope' => 'global', 'value' => 20]]]]
        );

        [$character] = $this->characterWithToken();
        $pet = Pet::create([
            'character_id' => $character->id,
            'species_id' => $species->id,
            'name' => 'Test',
            'level' => 10,
            'exp' => 0,
            'health' => 100,
            'max_health' => 100,
            'energy' => 100,
            'max_energy' => 100,
            'status' => 'idle',
        ]);

        $result = (new PetModifierResolver())->resolveForPet($pet);

        $this->assertEquals(20.0, $result[0]['value']);
    }
}
