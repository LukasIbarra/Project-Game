<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// F21 (ver docs/PETS_EXPEDITIONS_SYSTEM.md §5.5/§6): `pet_destinations` pasa
// a ser `expedition_definitions` -mismo concepto (catálogo de expediciones),
// nombre alineado al resto del sistema nuevo (expedition_rewards,
// expedition_event_definitions, pet_expedition_checkpoints). Las 3 keys
// reales existentes (forest/mountains/blood_castle) se conservan -no se
// tocan acá, eso es contenido (ExpeditionDefinitionSeeder), no esquema-.
//
// duration_seconds reemplaza duration_minutes (backfill = minutos*60,
// misma duración real, solo más granular para permitir valores no
// múltiplos de un minuto en el futuro). min_pet_level/is_active/description
// son campos nuevos que el diseño exige y que hoy no existen.
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('pet_destinations', 'expedition_definitions');

        Schema::table('expedition_definitions', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->unsignedInteger('duration_seconds')->nullable()->after('duration_minutes');
            $table->unsignedTinyInteger('min_pet_level')->default(1)->after('duration_seconds');
            $table->boolean('is_active')->default(true)->after('loot_pool_json');
        });

        DB::table('expedition_definitions')->update([
            'duration_seconds' => DB::raw('duration_minutes * 60'),
        ]);

        Schema::table('expedition_definitions', function (Blueprint $table) {
            $table->unsignedInteger('duration_seconds')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('expedition_definitions', function (Blueprint $table) {
            $table->dropColumn(['description', 'duration_seconds', 'min_pet_level', 'is_active']);
        });

        Schema::rename('expedition_definitions', 'pet_destinations');
    }
};
