<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 7: el pre-flight dejó `expedition_type` como string suelto porque
// todavía no existía un catálogo de destinos -nunca llegó a tener datos
// reales-. Ahora que existe `pet_destinations`, se reemplaza por una FK
// real (mismo patrón que inventory_items.item_id -> items).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_expeditions', function (Blueprint $table) {
            $table->dropColumn('expedition_type');

            // restrict: no se puede borrar un destino mientras exista una
            // expedición (activa o histórica) que lo referencie.
            $table->foreignId('destination_id')->after('pet_id')->constrained('pet_destinations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pet_expeditions', function (Blueprint $table) {
            $table->dropForeign(['destination_id']);
            $table->dropColumn('destination_id');
            $table->string('expedition_type')->after('pet_id');
        });
    }
};
