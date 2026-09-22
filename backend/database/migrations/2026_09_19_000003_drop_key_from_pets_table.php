<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 20: última pata del reemplazo de `pets.key` por `pets.species_id`
// -migración anterior (2026_09_19_000002) ya backfillió el 100% de las
// filas existentes y dejó species_id NOT NULL; ningún código del
// proyecto lee `pets.key` a esta altura (confirmado por grep, ver
// App\Support\PetPresenter/App\Services\PetProvisioningService, ya
// actualizados). down() restaura la columna nullable -no intenta
// reconstruir los valores, ese sentido se perdió a propósito al migrar a
// una relación real-.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('key');
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->string('key')->nullable()->after('character_id');
        });
    }
};
