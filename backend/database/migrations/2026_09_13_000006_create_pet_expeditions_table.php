<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_expeditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();

            $table->string('expedition_type'); // clave del mapa/expedición

            // string, no ENUM de MySQL. Valores esperados: in_progress,
            // completed -Fase 7 define el vocabulario final-.
            $table->string('status')->default('in_progress');

            // dateTime, no timestamp: con dos o más columnas TIMESTAMP sin
            // default explícito, MySQL en modo estricto tira "Invalid
            // default value" al crear la tabla (intenta darle un default
            // implícito de fecha cero y NO_ZERO_DATE lo rechaza). dateTime
            // no tiene ese comportamiento heredado.
            $table->dateTime('started_at');
            $table->dateTime('ends_at');
            $table->dateTime('resolved_at')->nullable();

            // Resolución perezosa (CLAUDE.md principio #2): NO hay job ni
            // scheduler acá. `result_data_json` queda vacío hasta que
            // alguien pida el recurso después de ends_at y Fase 7 calcule
            // y persista el resultado ahí mismo, una sola vez.
            $table->json('result_data_json')->nullable();

            $table->timestamps();

            $table->index(['pet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_expeditions');
    }
};
