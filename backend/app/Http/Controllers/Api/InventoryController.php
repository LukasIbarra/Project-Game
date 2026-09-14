<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrantItemRequest;
use App\Http\Requests\SellItemRequest;
use App\Models\Item;
use App\Services\EconomyService;
use App\Services\InventoryGrantService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryGrantService $grantService,
        private readonly EconomyService $economy
    ) {
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

    // F8, sección 11: el cliente solo manda item_key + quantity. El
    // precio (`sell_value`) y la validación de ownership/cantidad las
    // resuelve el backend -nunca se acepta un price/total del cliente.
    public function sell(SellItemRequest $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $item = Item::where('key', $request->string('item_key'))->firstOrFail();
        $quantity = (int) $request->input('quantity');

        $earned = $this->economy->sell($character, $item, $quantity);

        return response()->json([
            'earned' => $earned,
            'coins' => $character->fresh()->coins,
            'inventory' => $character->inventoryItems()->with('item')->get(),
        ]);
    }
}
