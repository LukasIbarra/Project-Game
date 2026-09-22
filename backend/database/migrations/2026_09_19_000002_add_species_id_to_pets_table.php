<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 20: reemplaza `pets.key` (string suelto) por una relación real a
// `pet_species`. Migración de datos incluida a propósito -esta base tiene
// mascotas reales con key='starter' que no se pueden perder (pedido
// explícito)-, en 3 pasos dentro del mismo up():
//   1. agrega species_id NULLABLE (todavía no puede fallar por datos
//      existentes);
//   2. asegura que exista la especie "starter" y reasigna toda mascota
//      existente a ella (vía DB:: crudo, no Eloquent -una migración no
//      debe depender de que el modelo Pet/PetSpecies no cambie de forma
//      más adelante-);
//   3. recién con TODAS las filas ya migradas, vuelve species_id
//      NOT NULL.
// `key` se deja intacta acá a propósito -se retira en la migración
// siguiente, después de que el código ya no la use-.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->foreignId('species_id')
                ->nullable()
                ->after('key')
                ->constrained('pet_species')
                ->restrictOnDelete();
        });

        $starterSpeciesId = DB::table('pet_species')->where('key', 'starter')->value('id');

        if ($starterSpeciesId === null) {
            $starterSpeciesId = DB::table('pet_species')->insertGetId([
                'key' => 'starter',
                'name' => 'Compañero',
                'description' => 'La mascota inicial de todo jugador.',
                'rarity' => 'common',
                'modifiers_json' => json_encode([]),
                'level_modifiers_json' => json_encode([]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Hoy 'starter' es el único valor real de `key` en producción/dev
        // (confirmado en la auditoría) -de cualquier forma, esto reasigna
        // TODA fila sin species_id a 'starter', sin asumir que no haya
        // otros valores de key sueltos por ahí.
        DB::table('pets')->whereNull('species_id')->update(['species_id' => $starterSpeciesId]);

        Schema::table('pets', function (Blueprint $table) {
            $table->foreignId('species_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropForeign(['species_id']);
            $table->dropColumn('species_id');
        });
    }
};
