<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F21 (docs/PETS_EXPEDITIONS_SYSTEM.md §3.1/§6/§11): tabla nueva, no
// existía absolutamente nada de esto antes (confirmado por grep exhaustivo
// de "checkpoint" en todo backend/ durante la auditoría — cero resultados).
// Es el corazón del rediseño: reemplaza "todo se calcula una vez en
// start()" por una secuencia de checkpoints que se resuelven en su
// momento real (resolución perezosa de verdad, no solo de la APLICACIÓN
// de un resultado ya calculado).
//
// payload SIEMPRE null hasta que el checkpoint pasa a resolved -nunca se
// pre-rollea nada en start(), eso es justamente lo que este rediseño existe
// para corregir (ver PetExpeditionService actual, que sí precalcula todo).
//
// kind se mantiene deliberadamente en 2 valores (narrative|event) — quién
// decide si un checkpoint necesita interacción del jugador es
// expedition_event_definitions.type/config_json, nunca el checkpoint en sí
// (así F22 agrega tipos de evento nuevos sin tocar esta tabla).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_expedition_checkpoints', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pet_expedition_id')->constrained('pet_expeditions')->cascadeOnDelete();

            // Orden de resolución dentro de la expedición -nunca se
            // resuelve el checkpoint 3 antes que el 2, aunque ambos ya
            // estén vencidos (F21, catch-up offline en orden).
            $table->unsignedInteger('sequence');

            $table->dateTime('scheduled_at');

            $table->string('kind');

            // Solo se completa cuando el checkpoint pasa a resolved (se
            // elige recién en ese momento, nunca antes). null en
            // pending/awaiting_decision.
            $table->foreignId('event_definition_id')->nullable()->constrained('expedition_event_definitions')->restrictOnDelete();

            $table->string('status')->default('pending');

            // Solo relevante para checkpoints kind=event que requieran
            // decisión (F22) — F21 no genera ninguno en awaiting_decision
            // todavía, pero la columna ya existe para no re-migrar.
            $table->string('decision')->nullable();

            $table->dateTime('resolved_at')->nullable();
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['pet_expedition_id', 'status', 'scheduled_at'], 'pet_expedition_checkpoints_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_expedition_checkpoints');
    }
};
