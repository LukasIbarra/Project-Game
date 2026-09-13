<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pets', function (Blueprint $table) {
            $table->id();

            // unique = 1 mascota por personaje en el MVP (decisión
            // documentada en el reporte; relajar a futuro es solo borrar
            // este índice, no un rediseño).
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('key'); // especie/tipo, ej. "pet_cat_basic"
            $table->string('name')->nullable(); // apodo puesto por el jugador
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('exp')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pets');
    }
};
