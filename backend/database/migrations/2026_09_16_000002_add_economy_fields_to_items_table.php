<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F8: `sell_value` es el precio que el backend paga al vender -nunca un
// precio que mande el cliente (sección 11 de la fase). 0 = no vendible,
// evita una columna booleana aparte. `icon` es un placeholder de emoji
// reemplazable después por una clave de asset real sin tocar lógica de
// inventario/crafting (sección 27). `rarity` es solo descriptivo/UI, ver
// App\Enums\ItemRarity -mismo patrón que PetDestination.loot_min_tier en
// F7, nada de un sistema de tiers que filtre nada todavía-.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedInteger('sell_value')->default(0)->after('max_stack');
            $table->string('icon')->nullable()->after('sell_value');
            $table->string('rarity')->default('common')->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['sell_value', 'icon', 'rarity']);
        });
    }
};
