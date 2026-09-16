<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;
use App\Models\ShopProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// F8: la única operación económica de esta fase es vender -la moneda en
// sí es una columna en Character (sección 10 de la fase). El precio
// SIEMPRE sale de `items.sell_value` (DB), nunca de lo que mande el
// cliente (sección 11: nunca aceptar price/total_price del frontend).
class EconomyService
{
    public function __construct(
        private readonly InventoryGrantService $inventory,
        private readonly ActivityLogger $activity
    ) {
    }

    public function sell(Character $character, Item $item, int $quantity): int
    {
        if (! $item->isSellable()) {
            throw ValidationException::withMessages([
                'item_key' => ['Este item no se puede vender.'],
            ]);
        }

        $owned = $this->inventory->totalOwned($character, $item);
        if ($owned < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => ["No tenés suficiente cantidad ({$owned} disponible)."],
            ]);
        }

        $totalValue = $item->sell_value * $quantity;

        DB::transaction(function () use ($character, $item, $quantity, $totalValue) {
            $this->inventory->consume($character, $item, $quantity);
            $character->increment('coins', $totalValue);

            $this->activity->log($character, 'sale', [
                'item_key' => $item->key,
                'item_name' => $item->name,
                'quantity' => $quantity,
                'coins_earned' => $totalValue,
            ]);
        });

        return $totalValue;
    }

    // Fase 16: extensión coherente de la misma clase que ya modifica coins
    // -no un sistema económico paralelo-. Mismo principio que sell(): el
    // precio SIEMPRE sale de `shop_products.price` (DB), nunca de lo que
    // mande el cliente. Atómica: si algo falla a mitad de camino, la
    // transacción entera se revierte -nunca quedan coins descontadas sin
    // el item entregado-.
    public function buy(Character $character, ShopProduct $product, int $quantity): int
    {
        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'item_key' => ['Este producto no está disponible.'],
            ]);
        }

        $totalCost = $product->price * $quantity;

        if ($character->coins < $totalCost) {
            throw ValidationException::withMessages([
                'quantity' => ["No tenés suficientes monedas ({$character->coins} disponibles, necesitás {$totalCost})."],
            ]);
        }

        DB::transaction(function () use ($character, $product, $quantity, $totalCost) {
            $character->decrement('coins', $totalCost);
            $this->inventory->grant($character, $product->item, $quantity);

            $this->activity->log($character, 'purchase', [
                'item_key' => $product->item->key,
                'item_name' => $product->item->name,
                'quantity' => $quantity,
                'coins_spent' => $totalCost,
            ]);
        });

        return $totalCost;
    }
}
