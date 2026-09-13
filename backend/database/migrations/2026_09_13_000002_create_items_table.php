<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();

            // Clave lógica estable (ej. "resource_wood"), NO una ruta de
            // archivo — el asset real se resuelve después vía
            // web/public/assets/manifest.json (CLAUDE.md principio #5).
            $table->string('key')->unique();

            $table->string('name');
            $table->text('description')->nullable();

            // string, no ENUM de MySQL a propósito — ver App\Enums\ItemType.
            $table->string('type');
            $table->string('subtype')->nullable();

            $table->boolean('stackable')->default(false);
            $table->unsignedInteger('max_stack')->default(1);

            $table->json('metadata_json')->nullable();

            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
