<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F23 (adopción/colección de mascotas): fuente única de verdad de "cuál
// es la mascota activa" -una columna en vez de una fila marcada
// (pets.is_active) porque una columna solo puede apuntar a UNA fila por
// construcción, sin necesitar ninguna constraint extra para garantizarlo.
// nullOnDelete(): si la Pet activa se borrara (hoy no hay ningún flujo que
// borre Pets, pero no cuesta nada dejarlo seguro), el personaje queda sin
// mascota activa en vez de romper la fila de characters.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('active_pet_id')->nullable()->after('coins')->constrained('pets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropForeign(['active_pet_id']);
            $table->dropColumn('active_pet_id');
        });
    }
};
