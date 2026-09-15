<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 12: feed de actividad reciente. Genérico a propósito -"type" +
// "payload" libre, mismo espíritu que combat_logs.events_json- para que
// agregar un tipo de evento nuevo en una fase futura (ataque recibido,
// evento de expedición, compra en tienda, evento de mundo) nunca necesite
// una migración nueva, solo un `type` más. Append-only: sin updated_at (ver
// ActivityEvent::UPDATED_AT), un evento de actividad nunca se edita.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            // El feed siempre se pide ordenado/filtrado por id (mismo
            // proxy cronológico estable que chat_messages, ver
            // ChatController) -de ahí el índice compuesto sobre id, no
            // sobre created_at-.
            $table->index(['character_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
