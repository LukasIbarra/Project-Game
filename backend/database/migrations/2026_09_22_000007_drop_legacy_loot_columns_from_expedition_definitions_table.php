<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F21: se ejecuta DESPUÉS de que ExpeditionRewardSeeder ya migró el
// contenido real de loot a la tabla normalizada (expedition_rewards) —
// estas columnas quedan completamente reemplazadas: loot_pool_json por
// expedition_rewards, loot_min_tier/loot_max_tier eran puramente
// cosméticos y nunca tuvieron consumidor real (confirmado en la auditoría),
// duration_minutes por duration_seconds (ya migrado en la primera
// migración de esta fase).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expedition_definitions', function (Blueprint $table) {
            $table->dropColumn(['loot_min_tier', 'loot_max_tier', 'loot_pool_json', 'duration_minutes']);
        });
    }

    public function down(): void
    {
        Schema::table('expedition_definitions', function (Blueprint $table) {
            $table->string('loot_min_tier')->nullable()->after('difficulty');
            $table->string('loot_max_tier')->nullable()->after('loot_min_tier');
            $table->json('loot_pool_json')->nullable()->after('loot_max_tier');
            $table->unsignedInteger('duration_minutes')->nullable()->after('difficulty');
        });
    }
};
