<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F8: catálogo de recetas -mismo espíritu que items/pet_destinations:
// datos configurables, no hardcodeados en el controller.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // string, no ENUM -ver App\Enums\RecipeCategory. Filtros de
            // la UI de /crafting (sección 14 de la fase).
            $table->string('category');
            $table->string('rarity')->default('common');

            // restrict: no se puede borrar el item resultado mientras
            // exista una receta que lo produzca.
            $table->foreignId('result_item_id')->constrained('items')->restrictOnDelete();
            $table->unsignedInteger('result_quantity')->default(1);

            // Sección 13: "aunque required_level/unlock_condition todavía
            // puedan estar sin utilizar, quiero que la arquitectura los
            // soporte". required_level SÍ se valida en F8 (gate simple
            // contra character.level); unlock_condition queda declarado
            // pero sin ninguna receta que lo use todavía -reservado para
            // F9+, ver CraftingService::isUnlockedFor-.
            $table->unsignedInteger('required_level')->default(1);
            $table->string('unlock_condition')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
