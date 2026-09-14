<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recipe;

// F8: catálogo de solo lectura -mismo patrón que ItemController/
// PetController::destinations-. No lleva estado del jugador (no dice si
// el personaje puede fabricar cada receta): el frontend ya tiene su
// propio inventario vía GET /v1/inventory y cruza ambos, igual que
// pet.astro ya hace con destinos + estado de la mascota.
class RecipeController extends Controller
{
    public function index()
    {
        return response()->json(
            Recipe::with(['ingredients.item', 'result'])
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('name')
                ->get()
        );
    }
}
