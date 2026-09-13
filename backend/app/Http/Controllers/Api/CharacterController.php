<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CharacterController extends Controller
{
    public function show(Request $request)
    {
        $character = $request->user()->character;

        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        return response()->json($character);
    }
}
