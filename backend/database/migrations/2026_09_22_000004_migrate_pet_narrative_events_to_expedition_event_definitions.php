<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// F21: migración de DATOS (no solo esquema) -mismo criterio que
// 2026_09_19_000002_add_species_id_to_pets_table (F20): esta base tiene
// contenido narrativo real ya curado (108 filas, ver
// PetNarrativeEventSeeder) que no se puede perder ni volver a redactar.
// Se copia 1:1 a expedition_event_definitions con type=narrative,
// config_json=null (sin mecánica) — `category` (que expedition_event_
// definitions no tiene como columna propia, ver migración anterior) se
// preserva dentro de config_json como metadata, no se descarta.
//
// Vía DB:: crudo, no Eloquent -una migración no debe depender de que el
// modelo PetNarrativeEvent/ExpeditionEventDefinition no cambie de forma
// más adelante-.
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('pet_narrative_events')->orderBy('id')->get();

        foreach ($rows as $row) {
            DB::table('expedition_event_definitions')->insert([
                'expedition_definition_id' => $row->destination_id,
                'type' => 'narrative',
                'rarity' => $row->rarity,
                'weight' => $row->weight,
                'is_active' => $row->is_active,
                'title' => null,
                'text' => $row->text,
                'config_json' => json_encode(['category' => $row->category]),
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('expedition_event_definitions')->where('type', 'narrative')->delete();
    }
};
