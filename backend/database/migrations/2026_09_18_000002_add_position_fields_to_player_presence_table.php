<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 19.1: agrega posición/dirección a la MISMA fila de player_presence
// (nunca una tabla nueva -ver docs/ROADMAP.md, Fase 18 ya dejó la tabla
// preparada a propósito para esto). Los tres nullable: una presencia
// puede existir (heartbeat desde cualquier página) sin que el jugador
// haya mandado nunca una posición real desde Mundo -eso recién pasa en
// F19.2, acá solo se agregan las columnas-.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_presence', function (Blueprint $table) {
            $table->decimal('position_x', 8, 2)->nullable()->after('current_map');
            $table->decimal('position_y', 8, 2)->nullable()->after('position_x');

            // String plano a propósito -F19.2 decide si un enum hace falta
            // cuando exista el endpoint que de verdad valida esto (hoy el
            // movimiento solo produce up/down/left/right, pero el sistema
            // de animación del frontend ya soporta las 8 direcciones).
            $table->string('direction')->nullable()->after('position_y');
        });
    }

    public function down(): void
    {
        Schema::table('player_presence', function (Blueprint $table) {
            $table->dropColumn(['position_x', 'position_y', 'direction']);
        });
    }
};
