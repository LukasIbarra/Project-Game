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

    // F8: total que un personaje posee de un item, sumado entre todas sus
    // filas/instancias -para que EconomyService::sell y CraftingService
    // puedan validar "¿alcanza?" antes de tocar nada.
    public function totalOwned(Character $character, Item $item): int
    {
        return (int) InventoryItem::where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->sum('quantity');
    }

    // F8: complemento de grant() -consume cantidad de un item desde las
    // filas más viejas primero (mismo criterio que grantStackable),
    // borrando la fila si llega a 0 en vez de dejarla en quantity=0. El
    // caller (EconomyService/CraftingService) es responsable de llamar
    // dentro de una transacción y de haber validado totalOwned() antes
    // -acá se vuelve a bloquear con lockForUpdate() como red de
    // seguridad contra una carrera entre el chequeo y el consumo real-.
    public function consume(Character $character, Item $item, int $quantity): void
    {
        $remaining = $quantity;

        $stacks = InventoryItem::where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($stacks as $stack) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($stack->quantity, $remaining);
            $remaining -= $take;

            if ($take === $stack->quantity) {
                $stack->delete();
            } else {
                $stack->quantity -= $take;
                $stack->save();
            }
        }

        if ($remaining > 0) {
            throw new \RuntimeException("No hay suficiente '{$item->key}' en el inventario (faltan {$remaining}).");
        }
    }
}
