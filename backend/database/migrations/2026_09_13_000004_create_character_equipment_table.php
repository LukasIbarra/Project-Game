<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();

            // Referencia la INSTANCIA poseída, no el catálogo directamente
            // (CLAUDE.md principio #4) — así una instancia con
            // instance_data_json propio (durabilidad, etc.) puede ser LA
            // que está equipada, no "cualquier item de este tipo". Si la
            // instancia se destruye/transfiere, se desequipa sola.
            $table->foreignId('inventory_item_id')->unique()->constrained()->cascadeOnDelete();

            // string, no ENUM de MySQL — ver App\Enums\EquipmentSlot.
            $table->string('slot');

            $table->timestamps();

            // Un personaje solo puede tener un item puesto por slot.
            $table->unique(['character_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_equipment');
    }
};
