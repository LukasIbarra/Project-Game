<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 16 (primera versión, deliberadamente simple): catálogo de la
// Tienda. Sin rotación ni stock por ahora (fuera de alcance de esta
// versión) -un producto es simplemente "este item se puede comprar a este
// precio". `price` es un concepto propio de la tienda, distinto de
// `items.sell_value` (lo que el banco te paga a VOS, F8) -por eso vive acá
// y no se reusa/pisa esa columna-. `item_id` único: un item tiene a lo
// sumo un listado de tienda, no duplicados. `restrictOnDelete()` mismo
// criterio que recipe_ingredients->items: no se puede borrar un item
// mientras siga listado para la venta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->unique()->constrained('items')->restrictOnDelete();
            $table->unsignedInteger('price');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_products');
    }
};
