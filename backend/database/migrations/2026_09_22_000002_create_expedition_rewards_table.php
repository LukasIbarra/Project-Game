<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// F21 (docs/PETS_EXPEDITIONS_SYSTEM.md §5.6/§6): reemplaza
// `expedition_definitions.loot_pool_json` (array plano de keys, sin pesos
// ni cantidades) por una loot table normalizada real. Player-facing:
// necesita integridad referencial contra `items` (un item borrado/
// renombrado se vuelve un error visible al editar contenido, no un `if
// (!$item) continue` silencioso como hace hoy PetExpeditionService::claim())
// y permite calcular porcentajes reales server-side
// (weight / SUM(weight) * 100) para el `reward_preview` que expone
// GET /v1/pet/expeditions/definitions.
//
// cascadeOnDelete en expedition_definition_id: una recompensa no tiene
// sentido sin su expedición dueña (igual que recipe_ingredients con su
// receta). restrictOnDelete en item_id: mismo criterio que el resto del
// catálogo, no se puede borrar un item mientras siga siendo recompensa de
// alguna expedición.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedition_rewards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('expedition_definition_id')->constrained('expedition_definitions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();

            // Desempate ponderado dentro del pool de UNA expedición -no es
            // una probabilidad absoluta, se normaliza en runtime
            // (weight / SUM(weight) de esa expedición).
            $table->unsignedInteger('weight');

            $table->unsignedInteger('min_qty');
            $table->unsignedInteger('max_qty');

            // string, no ENUM de MySQL -mismo patrón que el resto del
            // proyecto. Reusa App\Enums\ItemRarity (mismo vocabulario que
            // items.rarity), nullable porque no todo reward necesita
            // mostrarse con un badge de rareza distinto al de su propio item.
            $table->string('rarity_tier')->nullable();

            $table->timestamps();

            $table->index(['expedition_definition_id']);
        });

        DB::statement('ALTER TABLE expedition_rewards ADD CONSTRAINT expedition_rewards_qty_check CHECK (max_qty >= min_qty)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expedition_rewards');
    }
};
