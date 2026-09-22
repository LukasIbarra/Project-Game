<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 20: catálogo de comida para mascotas -mismo patrón que
// pet_destinations/recipes (tabla de catálogo, seedeable, sin acoplar el
// catálogo general de `items` a un dominio ajeno). Ver
// docs/PETS_EXPEDITIONS_SYSTEM.md §5.4.
//
// item_id UNIQUE: un item es comida de mascota con un único valor de EXP,
// nunca dos filas de pet_food_items para el mismo item.
// cascadeOnDelete: si el item en sí se borra del catálogo, la fila de
// "esto es comida" deja de tener sentido -mismo criterio que
// character_id en el resto del proyecto-.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_food_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')->unique()->constrained('items')->cascadeOnDelete();
            $table->unsignedInteger('exp_value');

            // Nullable a propósito -F20 no implementa "comida favorita"
            // todavía (pedido explícito), pero la columna ya existe para
            // que sumarla después sea aditivo, sin otra migración.
            $table->json('favorite_species_ids_json')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_food_items');
    }
};
