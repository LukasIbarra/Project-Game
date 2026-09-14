<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RoomProvisioningService;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function __construct(private readonly RoomProvisioningService $provisioning)
    {
    }

    public function show(Request $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $room = $this->provisioning->ensureForCharacter($character);

        return response()->json([
            'id' => $room->id,
            'map_key' => $room->map_key,
            'objects' => $room->items()->with('item')->get(),
        ]);
    }
}
