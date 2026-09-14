<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F8: la primera y única moneda del juego (sección 10 de la fase — nada
// de gemas/oro/segunda moneda todavía). Vive en el personaje, no en el
// usuario, igual que level/exp/stats (CLAUDE.md principio #3: columna
// normal, no JSON).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('coins')->default(0)->after('vitality');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('coins');
        });
    }
};
