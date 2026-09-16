<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseItemRequest;
use App\Models\Item;
use App\Models\ShopProduct;
use App\Services\EconomyService;
use Illuminate\Http\Request;

// Fase 16: Tienda, primera versión -catálogo fijo, sin rotación ni stock-.
// Mismo principio que el resto de la API (CLAUDE.md #1): el personaje
// siempre se deriva del usuario autenticado, el precio siempre sale de
// `shop_products.price` (DB), nunca de lo que mande el cliente.
class ShopController extends Controller
{
    public function __construct(private readonly EconomyService $economy)
    {
    }

    public function index(Request $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $products = ShopProduct::where('is_active', true)
            ->with('item')
            ->get()
            ->map(fn (ShopProduct $product) => [
                'item_key' => $product->item->key,
                'name' => $product->item->name,
                'icon' => $product->item->icon,
                'price' => $product->price,
            ])
            ->values();

        return response()->json([
            'products' => $products,
            'coins' => $character->coins,
        ]);
    }

    public function purchase(PurchaseItemRequest $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $item = Item::where('key', $request->string('item_key'))->firstOrFail();

        // Sección "Seguridad": un item real puede no estar (o haber dejado
        // de estar) listado en la tienda -404, no 422, es un producto que
        // no existe como tal, no un dato inválido en el request-.
        $product = ShopProduct::where('item_id', $item->id)->where('is_active', true)->first();
        if (! $product) {
            return response()->json(['message' => 'Este producto no está disponible.'], 404);
        }

        $quantity = (int) $request->input('quantity');

        $spent = $this->economy->buy($character, $product, $quantity);

        return response()->json([
            'spent' => $spent,
            'coins' => $character->fresh()->coins,
            'inventory' => $character->inventoryItems()->with('item')->get(),
        ]);
    }
}
