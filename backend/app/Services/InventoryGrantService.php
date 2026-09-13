<?php

namespace App\Services;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

// Extraído de InventoryController en Fase 7: la mascota (claim de
// expedición) necesita entregar loot al inventario respetando
// stackable/max_stack -exactamente la misma regla que F6 ya tenía-, así
// que se centraliza acá en vez de duplicarla. Comportamiento idéntico al
// que tenía InventoryController::grant, solo movido de lugar.
class InventoryGrantService
{
    public function grant(Character $character, Item $item, int $quantity): void
    {
        DB::transaction(function () use ($character, $item, $quantity) {
            if ($item->stackable) {
                $this->grantStackable($character->id, $item, $quantity);
            } else {
                for ($i = 0; $i < $quantity; $i++) {
                    InventoryItem::create([
                        'character_id' => $character->id,
                        'item_id' => $item->id,
                        'quantity' => 1,
                    ]);
                }
            }
        });
    }

    private function grantStackable(int $characterId, Item $item, int $quantity): void
    {
        $remaining = $quantity;

        $existingStacks = InventoryItem::where('character_id', $characterId)
            ->where('item_id', $item->id)
            ->orderBy('id')
            ->get();

        foreach ($existingStacks as $stack) {
            if ($remaining <= 0) {
                break;
            }

            $space = $item->max_stack - $stack->quantity;
            if ($space <= 0) {
                continue;
            }

            $add = min($space, $remaining);
            $stack->quantity += $add;
            $stack->save();
            $remaining -= $add;
        }

        while ($remaining > 0) {
            $take = min($remaining, $item->max_stack);
            InventoryItem::create([
                'character_id' => $characterId,
                'item_id' => $item->id,
                'quantity' => $take,
            ]);
            $remaining -= $take;
        }
    }
}
