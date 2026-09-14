<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chat global (demo, polling HTTP — ver docs). `message` limitado a 150
// caracteres A NIVEL DE COLUMNA (no solo en el FormRequest), mismo
// espíritu que el resto del proyecto de no confiar solo en la capa de
// aplicación para invariantes de datos. cascadeOnDelete en user_id: el
// historial de chat no es un log de auditoría con valor propio (a
// diferencia de combat_logs), así que si se borra un usuario no hay
// razón para dejar mensajes huérfanos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('message', 150);
            $table->timestamps();

            // El feed siempre se pide ordenado/filtrado por id (proxy
            // cronológico estable, ver ChatController) — no hace falta
            // indexar created_at aparte.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
