<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combat_logs', function (Blueprint $table) {
            $table->id();

            // Nullable + setNull a propósito (decisión documentada en el
            // reporte de esta tarea): el log de combate es HISTORIAL, no
            // debe desaparecer ni romperse si más adelante existe un flujo
            // que borre un personaje. Si eso pasa, el log sobrevive con
            // esa referencia en null en vez de cascadear el delete.
            $table->foreignId('attacker_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->foreignId('defender_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->foreignId('winner_character_id')->nullable()->constrained('characters')->nullOnDelete();

            // string (no bigint): admite tanto un seed numérico simple
            // como un hash/uuid si el algoritmo de combate lo prefiere
            // más adelante, sin tocar el esquema.
            $table->string('seed');

            // string, no ENUM de MySQL. CLAUDE.md ya define el combate
            // como síncrono (el servidor calcula todo en el POST), así
            // que hoy el único valor real es "completed"; el campo queda
            // para permitir variantes futuras sin romper el esquema.
            $table->string('status')->default('completed');

            // dateTime, no timestamp — mismo motivo que en pet_expeditions
            // (MySQL estricto rechaza el default implícito de la segunda
            // columna TIMESTAMP sin default explícito).
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();

            // Secuencia de eventos ya calculada por Laravel — Phaser solo
            // la reproduce (CLAUDE.md principio #6), nunca la genera.
            $table->json('events_json');

            $table->timestamps();

            $table->index('attacker_character_id');
            $table->index('defender_character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combat_logs');
    }
};
