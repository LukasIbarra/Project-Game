<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F21 (docs/PETS_EXPEDITIONS_SYSTEM.md §5.7/§6): unifica lo que hoy son DOS
// catálogos separados de "eventos de expedición" -`pet_narrative_events`
// (tabla, puramente cosmético) y `config/pet_events.php` (config estática,
// mecánico)- en uno solo, discriminado por `type` + `config_json` (mismo
// patrón que `items.type` + `metadata_json`, ya probado en el proyecto).
//
// F21 solo puebla/usa filas `type=narrative` (config_json=null) -los tipos
// con consecuencia mecánica real (chest/enemy/help) son F22 (Fase 21 del
// roadmap maestro), la columna ya queda lista para ellos sin otra migración.
//
// destination_id nullable = evento universal (cualquier expedición), mismo
// criterio que pet_narrative_events.destination_id hoy.
return new class extends Migration
{
    public function up(): void
    {
        // Defensivo: un primer intento de esta migración falló acá mismo
        // por un nombre de índice de MySQL/MariaDB demasiado largo (>64
        // caracteres, ya corregido abajo), dejando la tabla creada pero sin
        // registrar la migración como corrida -dropIfExists la deja limpia
        // para el reintento real, sin dejar un huérfano vacío en la DB.
        Schema::dropIfExists('expedition_event_definitions');

        Schema::create('expedition_event_definitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('expedition_definition_id')->nullable()->constrained('expedition_definitions')->restrictOnDelete();

            $table->string('type')->default('narrative');
            $table->string('rarity')->default('common');
            $table->unsignedInteger('weight')->default(1);
            $table->boolean('is_active')->default(true);

            $table->string('title')->nullable();
            $table->text('text');

            // Parámetros específicos de cada `type` -null para narrative,
            // que no tiene ninguna mecánica que configurar. F22 define la
            // forma real para chest/enemy/help (ver diseño, §5.7).
            $table->json('config_json')->nullable();

            $table->timestamps();

            $table->index(['expedition_definition_id', 'rarity', 'is_active'], 'expedition_event_definitions_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expedition_event_definitions');
    }
};
