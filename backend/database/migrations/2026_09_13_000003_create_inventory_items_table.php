<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();

            // restrict: no se puede borrar una definición de item mientras
            // exista una instancia en algún inventario.
            $table->foreignId('item_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity')->default(1);

            // Nullable a propósito (CLAUDE.md principio #4): datos de esta
            // instancia puntual (ej. {"durability": 85}), vacío en el MVP.
            // NO reemplaza columnas relacionales.
            $table->json('instance_data_json')->nullable();

            $table->timestamps();

            // Índice para "¿qué tiene este personaje?", NO unique: un
            // item no-stackable (ej. un arma con durabilidad propia) puede
            // tener varias filas con el mismo character_id+item_id, una
            // por instancia. Evitar duplicados de un stackable es lógica
            // de aplicación (buscar fila existente antes de insertar), no
            // una constraint de base de datos -acá sí "corresponde" evitar
            // duplicación (item stackable), y en el otro caso no.
            $table->index(['character_id', 'item_id']);
        });

        // Blueprint no tiene un helper check() nativo en Laravel 11 -MySQL
        // 8 sí soporta CHECK constraints (8.0.16+), se agrega con SQL crudo.
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_quantity_check CHECK (quantity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
