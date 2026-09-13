<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;

// Fase 6: catálogo de solo lectura -no hay CRUD de items todavía, eso no
// es parte de esta fase-. Necesario para que el frontend pueda listar qué
// existe y ofrecer "obtener" un item de prueba (no hay loot/crafting/
// recompensas reales todavía, ver InventoryController::grant).
class ItemController extends Controller
{
    public function index()
    {
        return response()->json(Item::orderBy('key')->get());
    }
}
