<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F23: relaja la restricción "1 mascota por personaje" del pre-flight
// (documentada ahí mismo como decisión reversible: "relajar a futuro es
// solo borrar este índice, no un rediseño"). Se reemplaza por
// unique(character_id, species_id) -refuerza a nivel DB la regla de
// negocio de F23: "máximo una mascota de cada especie por usuario"-, no se
// deja sin ninguna restricción.
//
// `retired_at`: soft-retire de mascotas legacy (ver
// 2026_09_23_000004_retire_legacy_starter_pets, la data migration que la
// usa) -nunca se hace DELETE de una Pet (pet_expeditions.pet_id tiene
// cascadeOnDelete(), borrar la fila se llevaría el historial con ella).
// Una Pet retirada simplemente deja de poder ser la activa ni aparecer en
// "mis mascotas", pero su historial de expediciones sigue íntegro.
return new class extends Migration
{
    public function up(): void
    {
        // InnoDB exige que la columna de una FK tenga algún índice
        // cubriéndola -pets_character_id_unique era el único, así que hay
        // que soltar la FK antes de poder soltar ese índice-. El nuevo
        // unique(character_id, species_id) vuelve a cubrir la FK (misma
        // columna líder), así que se recrea al final sin dejar la FK sin
        // índice en ningún paso intermedio.
        Schema::table('pets', function (Blueprint $table) {
            $table->dropForeign(['character_id']);
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->dropUnique(['character_id']);
            $table->dateTime('retired_at')->nullable()->after('status');
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->unique(['character_id', 'species_id']);
            $table->foreign('character_id')->references('id')->on('characters')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropForeign(['character_id']);
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->dropUnique(['character_id', 'species_id']);
            $table->dropColumn('retired_at');
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->unique('character_id');
            $table->foreign('character_id')->references('id')->on('characters')->cascadeOnDelete();
        });
    }
};
