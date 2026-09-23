<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// F23: retiro de la mascota legacy "Compañero" (F7/F20). Auditoría real
// contra la DB de desarrollo antes de escribir esto (102 characters, 102
// pets, TODOS species=starter, 5 expediciones históricas asociadas) —
// confirma que, dado que `pets.character_id` era UNIQUE hasta la
// migración anterior de esta misma fase, ningún personaje puede tener
// jamás más de una Pet todavía, así que "la única Pet es legacy" es
// simplemente "tiene una Pet" para cualquier fila existente hoy.
//
// Vía DB:: crudo, no Eloquent -una migración no debe depender de que el
// modelo Pet/PetSpecies no cambie de forma más adelante-.
//
// Qué hace, explícitamente:
//   1. Desactiva la especie 'starter' (is_active=false,
//      is_starter_option=false) -dejará de poder elegirse u ofrecerse,
//      pero la fila NUNCA se borra (integridad referencial con Pets y
//      pet_expeditions históricas).
//   2. Marca retired_at=now() en TODAS las Pets de esa especie que
//      todavía no estén retiradas -NUNCA un DELETE-. pet_expeditions
//      sigue intacta (pet_id no se toca).
//   3. NO hace falta limpiar characters.active_pet_id -es una columna
//      nueva de esta misma fase, nace NULL para todos los personajes
//      existentes, nunca llegó a apuntar a ninguna Pet legacy.
//
// Resultado esperado: los 102 personajes existentes entran a `/pet` sin
// ninguna mascota activa -pasan por la selección inicial, igual que un
// usuario nuevo-. Las 5 expediciones históricas de esas Pets siguen
// existiendo, íntegras, simplemente ya no se muestran como "mi mascota".
return new class extends Migration
{
    public function up(): void
    {
        $starterId = DB::table('pet_species')->where('key', 'starter')->value('id');

        if ($starterId === null) {
            return; // no debería pasar (F20 la crea siempre), pero no hay nada que retirar si no existe.
        }

        DB::table('pet_species')->where('id', $starterId)->update([
            'is_active' => false,
            'is_starter_option' => false,
            'updated_at' => now(),
        ]);

        DB::table('pets')
            ->where('species_id', $starterId)
            ->whereNull('retired_at')
            ->update([
                'retired_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $starterId = DB::table('pet_species')->where('key', 'starter')->value('id');

        if ($starterId === null) {
            return;
        }

        DB::table('pet_species')->where('id', $starterId)->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);

        DB::table('pets')->where('species_id', $starterId)->update([
            'retired_at' => null,
            'updated_at' => now(),
        ]);
    }
};
