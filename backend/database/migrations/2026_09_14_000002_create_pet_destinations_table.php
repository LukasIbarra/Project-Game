<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 7: catálogo de destinos de exploración -mismo espíritu que
// "items" en F6: datos configurables, no hardcodeados en el controller.
// Agregar un destino nuevo (Desierto, Pantano, etc.) = una fila nueva,
// cero cambios de código.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_destinations', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('name');

            // 1-5, ver CHECK más abajo.
            $table->unsignedTinyInteger('difficulty');

            $table->unsignedInteger('duration_minutes');

            // string, no ENUM -solo descriptivo/UI ("Básico -> Raro"), F7
            // no implementa un sistema de tiers que filtre loot por esto.
            $table->string('loot_min_tier');
            $table->string('loot_max_tier');

            // Pool de `items.key` de los que este destino puede dar loot
            // -reutiliza el catálogo de F6, no crea un catálogo paralelo-.
            $table->json('loot_pool_json');

            $table->timestamps();
        });

        DB::statement('ALTER TABLE pet_destinations ADD CONSTRAINT pet_destinations_difficulty_check CHECK (difficulty BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_destinations');
    }
};
