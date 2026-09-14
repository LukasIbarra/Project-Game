<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CraftRequest;
use App\Models\Recipe;
use App\Services\CraftingService;

class CraftingController extends Controller
{
    public function __construct(private readonly CraftingService $crafting)
    {
    }

    public function craft(CraftRequest $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $recipe = Recipe::where('key', $request->string('recipe_key'))->firstOrFail();

        $this->crafting->craft($character, $recipe);

        return response()->json(
            $character->inventoryItems()->with('item')->get()
        );
    }
}
