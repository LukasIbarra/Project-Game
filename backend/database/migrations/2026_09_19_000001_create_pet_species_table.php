<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 20: catálogo de especies -antes de esta fase, `pets.key` era un
// string suelto ("starter" era el único valor real, ver migración
// siguiente). Mismo patrón que `items`/`pet_destinations`: tabla de
// catálogo, seedeable, sin migración nueva para agregar una especie.
//
// `modifiers_json`/`level_modifiers_json`: JSON, no columnas ni tabla
// normalizada -ver docs/PETS_EXPEDITIONS_SYSTEM.md §5.1/5.2-. Se leen
// siempre completos para UNA especie a la vez (nunca se filtra/ordena
// entre especies por el valor de un modificador), así que no hay
// necesidad real de que sean queryables por SQL. La forma de cada
// entrada de `modifiers_json` la valida App\Enums\PetModifierType
// (PetModifierResolver), nunca queda como lógica arbitraria solo en JSON.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_species', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // string, no ENUM de MySQL -mismo patrón que el resto del
            // proyecto-. Ver App\Enums\PetSpeciesRarity.
            $table->string('rarity')->default('common');

            // Referencia a un asset visual -F20 no crea sprites nuevos,
            // esta columna solo deja el lugar preparado (F25/F26).
            $table->string('sprite_key')->nullable();

            // Ver comentario de arriba del archivo.
            $table->json('modifiers_json')->nullable();
            $table->json('level_modifiers_json')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_species');
    }
};
