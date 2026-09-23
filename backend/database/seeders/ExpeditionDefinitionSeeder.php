<?php

namespace Database\Seeders;

use App\Models\ExpeditionDefinition;
use Illuminate\Database\Seeder;

// F21: reemplaza PetSeeder -mismas 3 keys reales existentes
// (forest/mountains/blood_castle, NUNCA renombradas: PetSpeciesSeeder ya
// las referencia en modifiers_json.target) con sus datos de catálogo
// actualizados al diseño objetivo, más 3 expediciones nuevas
// (windy_hills/ancient_ruins/cursed_swamp). Ver
// docs/PETS_EXPEDITIONS_SYSTEM.md §9.1 para la tabla de duración/
// dificultad/nivel mínimo propuesta -usada acá tal cual, es un punto de
// partida razonable, no un valor mágico inventado en el momento-.
class ExpeditionDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            ExpeditionDefinition::updateOrCreate(
                ['key' => $definition['key']],
                $definition
            );
        }
    }

    private function definitions(): array
    {
        return [
            [
                'key' => 'forest',
                'name' => 'Bosque Encantado',
                'description' => 'Un bosque tranquilo donde la magia natural todavía se nota en el aire. Ideal para una primera salida.',
                'difficulty' => 1,
                'duration_seconds' => 1800, // 30 min
                'min_pet_level' => 1,
                'is_active' => true,
            ],
            [
                'key' => 'windy_hills',
                'name' => 'Colinas del Viento',
                'description' => 'Colinas abiertas donde el viento nunca deja de soplar. Un paso natural para mascotas que ya perdieron el miedo a explorar.',
                'difficulty' => 2,
                'duration_seconds' => 3600, // 1 h
                'min_pet_level' => 1,
                'is_active' => true,
            ],
            [
                'key' => 'mountains',
                'name' => 'Montañas Heladas',
                'description' => 'Picos cubiertos de nieve y hielo permanente. El frío ahí arriba no perdona a quien no está preparado.',
                'difficulty' => 3,
                'duration_seconds' => 7200, // 2 h
                'min_pet_level' => 3,
                'is_active' => true,
            ],
            [
                'key' => 'ancient_ruins',
                'name' => 'Ruinas Antiguas',
                'description' => 'Restos de una civilización que ya nadie recuerda. Entre las piedras todavía quedan objetos que alguna vez tuvieron dueño.',
                'difficulty' => 3,
                'duration_seconds' => 14400, // 4 h
                'min_pet_level' => 5,
                'is_active' => true,
            ],
            [
                'key' => 'cursed_swamp',
                'name' => 'Pantano Maldito',
                'description' => 'Un pantano donde hasta las plantas parecen equivocadas. Algo ahí adentro corrompió todo lo que toca.',
                'difficulty' => 4,
                'duration_seconds' => 28800, // 8 h
                'min_pet_level' => 7,
                'is_active' => true,
            ],
            [
                'key' => 'blood_castle',
                'name' => 'Castillo Sangriento',
                'description' => 'El destino más peligroso conocido. Un castillo que nadie visita dos veces sin motivo.',
                'difficulty' => 5,
                'duration_seconds' => 43200, // 12 h
                'min_pet_level' => 10,
                'is_active' => true,
            ],
        ];
    }
}
