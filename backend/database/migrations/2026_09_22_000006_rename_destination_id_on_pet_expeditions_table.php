<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F21: `pet_expeditions.destination_id` -> `expedition_definition_id`,
// mismo alineamiento de nombres que el resto de esta fase. 3 pasos
// explícitos (drop FK -> rename columna -> recrear FK) en vez de confiar
// en que renameColumn() preserve la constraint con el nombre viejo -mismo
// criterio defensivo que el resto de las migraciones de este proyecto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_expeditions', function (Blueprint $table) {
            $table->dropForeign(['destination_id']);
        });

        Schema::table('pet_expeditions', function (Blueprint $table) {
            $table->renameColumn('destination_id', 'expedition_definition_id');
        });

        Schema::table('pet_expeditions', function (Blueprint $table) {
            $table->foreign('expedition_definition_id')->references('id')->on('expedition_definitions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pet_expeditions', function (Blueprint $table) {
            $table->dropForeign(['expedition_definition_id']);
        });

        Schema::table('pet_expeditions', function (Blueprint $table) {
            $table->renameColumn('expedition_definition_id', 'destination_id');
        });

        Schema::table('pet_expeditions', function (Blueprint $table) {
            $table->foreign('destination_id')->references('id')->on('expedition_definitions')->restrictOnDelete();
        });
    }
};
