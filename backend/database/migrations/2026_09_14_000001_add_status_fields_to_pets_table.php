<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 7: la mascota del pre-flight ya tenía character_id/key/name/level/
// exp -"key" ya cumple el rol de "species" que pide esta fase (especie/
// tipo, ej. "starter"), así que no se duplica esa columna-. Lo que falta
// es el estado de exploración AFK: salud/energía/status.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->unsignedInteger('health')->default(100)->after('exp');
            $table->unsignedInteger('max_health')->default(100)->after('health');
            $table->unsignedInteger('energy')->default(100)->after('max_health');
            $table->unsignedInteger('max_energy')->default(100)->after('energy');

            // string, no ENUM de MySQL -mismo patrón que items.type/
            // character_equipment.slot-. Ver App\Enums\PetStatus.
            $table->string('status')->default('idle')->after('max_energy');
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['health', 'max_health', 'energy', 'max_energy', 'status']);
        });
    }
};
