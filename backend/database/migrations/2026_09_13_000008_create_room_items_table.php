<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();

            // restrict: no borrar un item del catálogo mientras esté
            // colocado en alguna habitación.
            $table->foreignId('item_id')->constrained()->restrictOnDelete();

            // Nullable a propósito (pedido explícito): si la instancia de
            // inventario de origen se borra, el mueble colocado no
            // desaparece, solo pierde la trazabilidad hacia esa instancia.
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('x');
            $table->integer('y');
            $table->unsignedSmallInteger('rotation')->default(0);
            $table->string('layer')->default('floor');

            $table->json('metadata_json')->nullable();

            $table->timestamps();

            $table->index('room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_items');
    }
};
