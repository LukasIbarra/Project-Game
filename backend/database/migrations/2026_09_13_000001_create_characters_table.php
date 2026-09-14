<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');

            // Stats: columnas normales, no JSON (CLAUDE.md principio #3) —
            // se necesitan para rankings, matchmaking y balance.
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('exp')->default(0);
            $table->unsignedInteger('strength')->default(1);
            $table->unsignedInteger('agility')->default(1);
            $table->unsignedInteger('vitality')->default(1);

            // appearance_json SÍ es JSON a propósito (CLAUDE.md principio
            // #3): el CharacterRenderer lo consume entero como una
            // composición completa de capas, nunca se filtra/ordena por
            // sus claves. Default = personaje base sin nada equipado,
            // igual al DEFAULT_APPEARANCE del frontend (Player.ts, Fase 3).
            //
            // Render+Neon: el default ANTES vivía acá como
            // `JSON_OBJECT('body', 'base')` -función específica de MySQL/
            // MariaDB, PostgreSQL no la tiene con esa firma-. Se movió al
            // modelo Eloquent (Character::$attributes), que es portable a
            // cualquier motor y no depende de SQL crudo -ver
            // docs/RENDER_DEPLOYMENT_ANALYSIS.md-. La columna sigue
            // NOT NULL (nunca hubo default nullable): todo alta de
            // Character pasa por Eloquent, que ya completa este atributo
            // antes del INSERT.
            $table->json('appearance_json');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
