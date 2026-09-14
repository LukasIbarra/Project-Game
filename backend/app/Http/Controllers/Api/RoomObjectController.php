<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MoveRoomObjectRequest;
use App\Http\Requests\PlaceRoomObjectRequest;
use App\Models\Item;
use App\Models\RoomItem;
use App\Services\RoomPlacementService;
use App\Services\RoomProvisioningService;
use Illuminate\Http\Request;

// Room, sección 16-17: el character SIEMPRE se deriva del usuario
// autenticado (nunca de un character_id del cliente); cada acción vuelve
// a comprobar que el room_item pertenece a la habitación de ESE
// character antes de tocarlo, sin importar qué id venga en la URL.
class RoomObjectController extends Controller
{
    public function __construct(
        private readonly RoomProvisioningService $provisioning,
        private readonly RoomPlacementService $placement
    ) {
    }

    public function store(PlaceRoomObjectRequest $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $room = $this->provisioning->ensureForCharacter($character);
        $item = Item::where('key', $request->string('item_key'))->firstOrFail();

        $this->placement->place($room, $item, $request->integer('tile_x'), $request->integer('tile_y'));

        return response()->json([
            'objects' => $room->items()->with('item')->get(),
            'inventory' => $character->inventoryItems()->with('item')->get(),
        ], 201);
    }

    public function update(MoveRoomObjectRequest $request, RoomItem $roomObject)
    {
        $character = $request->user()->character;
        if (! $character || ! $character->room || $roomObject->room_id !== $character->room->id) {
            return response()->json(['message' => 'Ese mueble no pertenece a tu habitación.'], 403);
        }

        $this->placement->move($roomObject, $request->integer('tile_x'), $request->integer('tile_y'));

        return response()->json([
            'objects' => $character->room->items()->with('item')->get(),
        ]);
    }

    public function destroy(Request $request, RoomItem $roomObject)
    {
        $character = $request->user()->character;
        if (! $character || ! $character->room || $roomObject->room_id !== $character->room->id) {
            return response()->json(['message' => 'Ese mueble no pertenece a tu habitación.'], 403);
        }

        $this->placement->remove($roomObject);

        return response()->json([
            'objects' => $character->room->items()->with('item')->get(),
            'inventory' => $character->inventoryItems()->with('item')->get(),
        ]);
    }
}
