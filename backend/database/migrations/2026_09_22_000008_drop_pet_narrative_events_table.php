<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// F21: se ejecuta DESPUÉS de la migración de datos (000004) que ya copió
// las 108 filas reales a expedition_event_definitions. Esta tabla queda
// completamente superseded -pet_narrative_events + config/pet_events.php
// se unifican en expedition_event_definitions, ver
// docs/PETS_EXPEDITIONS_SYSTEM.md §5.7-. down() recrea el esquema exacto
// de la migración original (2026_09_15_000001) por si hace falta revertir,
// aunque los datos ya migrados a expedition_event_definitions no se
// restauran automáticamente acá (down() de un drop nunca recupera datos).
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pet_narrative_events');
    }

    public function down(): void
    {
        Schema::create('pet_narrative_events', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->nullable()->constrained('expedition_definitions')->restrictOnDelete();
            $table->string('category');
            $table->string('rarity')->default('common');
            $table->text('text');
            $table->unsignedInteger('weight')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['destination_id', 'rarity', 'is_active']);
        });
    }
};
