<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();

            // unique = 1 habitación por personaje en el MVP.
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();

            // Clave lógica del mapa base (ej. "room_basic"), NUNCA una
            // ruta de archivo/máquina — la estructura de paredes/suelo
            // sigue viviendo en los assets/mapas Tiled, esta tabla es solo
            // la habitación LÓGICA del jugador.
            $table->string('map_key')->default('room_basic');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
