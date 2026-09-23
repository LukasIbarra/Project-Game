<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F23: extiende pet_species (ya existente desde F20) para catálogo de
// adopción -sigue siendo la ÚNICA fuente de verdad de especies, ningún
// catálogo paralelo-.
//
// is_starter_option: si puede elegirse como la mascota gratis inicial.
// default false a propósito -las 19 especies "_pup" de F20 NO se ofrecen
// como starter (quedan como contenido futuro para compra/otros sistemas,
// sin decidir eso ahora), la especie legacy "starter" tampoco (se
// desactiva en la migración de datos siguiente). Solo las 5 nuevas la
// tienen en true (ver PetSpeciesSeeder).
//
// adoption_price: costo en monedas para adoptarla DESPUÉS de la primera
// mascota gratis -la primera adopción (starter) nunca cobra esto,
// independientemente de su valor, ver PetAdoptionService::adoptStarter().
//
// sprite_meta_json: reemplaza la necesidad de un segundo catálogo de
// assets -reutiliza sprite_key (ya existente, hoy vacío) para el
// identificador corto, y agrega acá la grilla real medida del spritesheet
// (frame_width/height/columns/rows/idle_frames) para que el frontend
// nunca tenga que hardcodear "if species === X".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_species', function (Blueprint $table) {
            $table->boolean('is_starter_option')->default(false)->after('is_active');
            $table->unsignedInteger('adoption_price')->default(0)->after('is_starter_option');
            $table->json('sprite_meta_json')->nullable()->after('sprite_key');
        });
    }

    public function down(): void
    {
        Schema::table('pet_species', function (Blueprint $table) {
            $table->dropColumn(['is_starter_option', 'adoption_price', 'sprite_meta_json']);
        });
    }
};
