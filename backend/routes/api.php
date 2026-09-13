<?php

use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\PetController;
use App\Http\Controllers\Api\PetExpeditionController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/ping', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'laravel-backend',
        'time' => now()->toIso8601String(),
    ]);
});

Route::prefix('v1/auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// Fase 6: inventario/equipamiento -el servidor deriva siempre "el
// personaje" del usuario autenticado (Sanctum), nunca de un character_id
// que mande el cliente (CLAUDE.md principio #1).
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/character', [CharacterController::class, 'show']);

    Route::get('/items', [ItemController::class, 'index']);

    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory/grant', [InventoryController::class, 'grant']);

    Route::get('/equipment', [EquipmentController::class, 'index']);
    Route::post('/equipment/equip', [EquipmentController::class, 'equip']);
    Route::delete('/equipment/{slot}', [EquipmentController::class, 'unequip']);

    // Fase 7: mascota/expediciones AFK -mismo principio, la mascota
    // siempre se deriva del usuario autenticado.
    Route::get('/pet', [PetController::class, 'show']);
    Route::get('/pet/destinations', [PetController::class, 'destinations']);
    Route::get('/pet/expedition', [PetController::class, 'expedition']);
    Route::post('/pet/expedition/start', [PetExpeditionController::class, 'start']);
    Route::post('/pet/expedition/claim', [PetExpeditionController::class, 'claim']);
    Route::get('/pet/events', [PetController::class, 'events']);
});
