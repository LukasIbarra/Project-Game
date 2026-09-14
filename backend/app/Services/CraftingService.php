<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// F8. El cliente solo manda `recipe_key` (sección 12 de la fase) — todo
// lo demás (ingredientes, cantidades, resultado) lo resuelve el backend
// desde la receta. Reutiliza InventoryGrantService::consume/grant en vez
// de duplicar la lógica de stack (pedido explícito de la fase).
class CraftingService
{
    public function __construct(private readonly InventoryGrantService $inventory)
    {
    }

    public function craft(Character $character, Recipe $recipe): void
    {
        if (! $recipe->is_active) {
            throw ValidationException::withMessages([
                'recipe_key' => ['Esta receta no está disponible.'],
            ]);
        }

        // unlock_condition queda declarado para F9+ (sección 13 de la
        // fase) pero ninguna receta de F8 lo usa — si algún día una
        // receta lo trae seteado, se bloquea por defecto en vez de
        // ignorarlo silenciosamente.
        if ($recipe->unlock_condition) {
            throw ValidationException::withMessages([
                'recipe_key' => ['Receta bloqueada.'],
            ]);
        }

        if ($character->level < $recipe->required_level) {
            throw ValidationException::withMessages([
                'recipe_key' => ["Necesitás nivel {$recipe->required_level} para fabricar esto."],
            ]);
        }

        $recipe->loadMissing('ingredients.item', 'result');

        $missing = [];
        foreach ($recipe->ingredients as $ingredient) {
            $owned = $this->inventory->totalOwned($character, $ingredient->item);
            if ($owned < $ingredient->quantity) {
                $missing[] = "{$ingredient->item->name} ({$owned}/{$ingredient->quantity})";
            }
        }

        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'ingredients' => ['Faltan materiales: '.implode(', ', $missing)],
            ]);
        }

        DB::transaction(function () use ($character, $recipe) {
            foreach ($recipe->ingredients as $ingredient) {
                $this->inventory->consume($character, $ingredient->item, $ingredient->quantity);
            }

            $this->inventory->grant($character, $recipe->result, $recipe->result_quantity);
        });
    }
}
