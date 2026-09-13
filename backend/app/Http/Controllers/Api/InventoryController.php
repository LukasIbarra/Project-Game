<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrantItemRequest;
use App\Models\Item;
use App\Services\InventoryGrantService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryGrantService $grantService)
    {
    }

    public function index(Request $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        return response()->json(
            $character->inventoryItems()->with('item')->get()
        );
    }

    // Fase 6: no existe todavía ningún sistema de obtención real (loot,
    // crafting, recompensas) -llegan en F7/F8/F10-. Este endpoint es la
    // única forma actual de que un personaje reciba un item, pensado para
    // probar el flujo de inventario/equipamiento de esta fase; server-side
    // sigue siendo la única autoridad (el cliente solo pide una `item_key`
    // del catálogo, nunca decide cantidades ni ownership).
    public function grant(GrantItemRequest $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $item = Item::where('key', $request->string('item_key'))->firstOrFail();
        $quantity = (int) $request->input('quantity', 1);

        $this->grantService->grant($character, $item, $quantity);

        return response()->json(
            $character->inventoryItems()->with('item')->get()
        );
    }
}
