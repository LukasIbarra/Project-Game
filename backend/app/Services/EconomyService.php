<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// F8: la única operación económica de esta fase es vender -la moneda en
// sí es una columna en Character (sección 10 de la fase). El precio
// SIEMPRE sale de `items.sell_value` (DB), nunca de lo que mande el
// cliente (sección 11: nunca aceptar price/total_price del frontend).
class EconomyService
{
    public function __construct(private readonly InventoryGrantService $inventory)
    {
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
        });

        return $totalValue;
    }
}
