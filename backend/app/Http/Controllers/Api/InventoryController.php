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

    // Fase 6: herramienta de PRUEBA para el flujo de inventario/
    // equipamiento -nunca fue un mecanismo de juego real-. Desde F7/F8/F10
    // ya existen formas reales de conseguir items (loot de expedición,
    // crafting, recompensas de combate), así que esto quedó obsoleto para
    // gameplay real y, peor, expuesto vía HTTP le permitía a CUALQUIER
    // usuario autenticado regalarse cualquier item del catálogo gratis
    // (encontrado en la auditoría de la Fase Deploy — sección 10/13 de esa
    // tarea lo pedía explícitamente). Se bloquea fuera de entornos locales
    // en vez de borrarse: sigue sirviendo para pruebas manuales en
    // desarrollo (`php artisan tinker` / `item:grant` cubren lo mismo sin
    // pasar por HTTP, pero esto se deja también por compatibilidad con
    // scripts de test existentes que sí corren en local).
    public function grant(GrantItemRequest $request)
    {
        // 'testing' además de 'local' -PHPUnit corre con APP_ENV=testing
        // (phpunit.xml) y InventoryTest ya cubre este endpoint por HTTP.
        if (! app()->environment(['local', 'testing'])) {
            return response()->json(['message' => 'No disponible.'], 403);
        }

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
