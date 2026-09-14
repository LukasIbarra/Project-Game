<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F7.1: catálogo de eventos puramente narrativos -sin efecto mecánico,
// a diferencia de config/pet_events.php (eventos MECÁNICOS que sí
// afectan health/tiempo/loot, ver PetExpeditionService::rollEvents). Se
// modela como tabla (no config) porque es contenido de texto extenso
// (100+ filas) pensado para crecer con el tiempo, no un puñado de reglas
// -es lo mismo que la razón por la que "items" es tabla y no config-.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_narrative_events', function (Blueprint $table) {
            $table->id();

            // null = evento universal (cualquier destino); con valor =
            // exclusivo de ese destino. restrict: no se puede borrar un
            // destino mientras tenga eventos narrativos propios.
            $table->foreignId('destination_id')->nullable()->constrained('pet_destinations')->restrictOnDelete();

            // string, no ENUM de MySQL -mismo patrón que el resto del
            // proyecto-. Ver App\Enums\PetNarrativeCategory/Rarity.
            $table->string('category');
            $table->string('rarity')->default('common');

            $table->text('text');

            // Desempate dentro de la misma rareza -mayor peso, más chance
            // relativa entre candidatos igual de raros-. No es la
            // probabilidad de rareza en sí (esa la decide el código).
            $table->unsignedInteger('weight')->default(1);

            // Permite desactivar un evento sin borrarlo (p.ej. contenido
            // estacional futuro, sección 18 de la fase).
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['destination_id', 'rarity', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_narrative_events');
    }
};
