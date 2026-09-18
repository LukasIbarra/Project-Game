<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 18: presencia de jugadores (HTTP + polling, sin Reverb -ver
// docs/ROADMAP.md-). Una sola fila por personaje, actualizada en cada
// heartbeat -por eso `character_id` es unique, no un log append-only como
// activity_events-. "Online" se decide siempre por ventana temporal sobre
// last_seen_at (>= now() - 60s), nunca por un valor de `status` ni por un
// evento explícito de logout -de ahí el índice sobre last_seen_at, es la
// única columna por la que GET /presence filtra-.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_presence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('last_seen_at');

            // Fase 19 va a sumar position_x/position_y/direction a esta
            // misma tabla (no una nueva) -ver docs/ROADMAP.md-.
            $table->string('current_map')->nullable();

            // Reservado para estados futuros (away/in_combat/etc, Fase 19+).
            // Hoy el heartbeat siempre escribe 'online'; no participa de la
            // definición de "en línea", que es 100% la ventana sobre
            // last_seen_at.
            $table->string('status')->default('online');

            // Sin created_at a propósito (mismo criterio invertido que
            // ActivityEvent::UPDATED_AT = null): acá hay una sola fila
            // mutable por personaje, no un log -solo importa cuándo se
            // tocó por última vez-.
            $table->timestamp('updated_at')->nullable();

            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_presence');
    }
};
