<?php

namespace App\Http\Controllers\Api;

use App\Enums\EquipmentSlot;
use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Http\Requests\EquipItemRequest;
use App\Models\CharacterEquipment;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EquipmentController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        return response()->json([
            'equipment' => $character->equipment()->with('inventoryItem.item')->get(),
            'appearance' => $character->appearance_json,
        ]);
    }

    public function equip(EquipItemRequest $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        /** @var InventoryItem $inventoryItem */
        $inventoryItem = InventoryItem::with('item')->findOrFail($request->integer('inventory_item_id'));

        // El servidor nunca confía en que el cliente solo pida sus propias
        // instancias -CLAUDE.md principio #1-: se verifica ownership acá,
        // no en la regla de validación del FormRequest.
        if ($inventoryItem->character_id !== $character->id) {
            return response()->json(['message' => 'Esa instancia no pertenece a tu personaje.'], 403);
        }

        $item = $inventoryItem->item;
        $slot = EquipmentSlot::tryFrom($item->subtype ?? '');
        $equipableTypes = [ItemType::Equipment, ItemType::Cosmetic];

        if (! $slot || ! in_array($item->type, $equipableTypes, true)) {
            return response()->json(['message' => 'Este item no es equipable.'], 422);
        }

        DB::transaction(function () use ($character, $inventoryItem, $slot, $item) {
            CharacterEquipment::updateOrCreate(
                ['character_id' => $character->id, 'slot' => $slot->value],
                ['inventory_item_id' => $inventoryItem->id]
            );

            // Solo se toca la clave de este slot -el resto de
            // appearance_json (CLAUDE.md principio #3) queda intacto.
            $appearance = $character->appearance_json ?? [];
            $appearance[$slot->value] = $item->key;
            $character->appearance_json = $appearance;
            $character->save();
        });

        return response()->json([
            'equipment' => $character->equipment()->with('inventoryItem.item')->get(),
            'appearance' => $character->fresh()->appearance_json,
        ]);
    }

    public function unequip(Request $request, string $slot)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $slotEnum = EquipmentSlot::tryFrom($slot);
        if (! $slotEnum) {
            return response()->json(['message' => 'Slot inválido.'], 422);
        }

        $equipped = CharacterEquipment::where('character_id', $character->id)
            ->where('slot', $slotEnum->value)
            ->first();

        if (! $equipped) {
            return response()->json(['message' => 'No hay nada equipado en ese slot.'], 404);
        }

        DB::transaction(function () use ($character, $equipped, $slotEnum) {
            $equipped->delete();

            $appearance = $character->appearance_json ?? [];
            $appearance[$slotEnum->value] = null;
            $character->appearance_json = $appearance;
            $character->save();
        });

        return response()->json([
            'equipment' => $character->equipment()->with('inventoryItem.item')->get(),
            'appearance' => $character->fresh()->appearance_json,
        ]);
    }
}
