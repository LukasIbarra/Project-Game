<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\ArenaAttackController;
use App\Http\Controllers\Api\ArenaController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\CraftingController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\PetController;
use App\Http\Controllers\Api\PetExpeditionController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RoomObjectController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/ping', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'laravel-backend',
        'time' => now()->toIso8601String(),
    ]);
});

// Fase Deploy: límite extra sobre el throttle:api global (60/min) —
// login/register son los blancos típicos de fuerza bruta/spam de cuentas
// en una demo pública, así que además de la protección genérica quedan
// con un límite propio más estricto (throttle nativo de Laravel, sin
// dependencias nuevas).
Route::prefix('v1/auth')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

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

    // Fase 12: feed de actividad reciente -mismo principio, siempre el
    // personaje del usuario autenticado, nunca uno que mande el cliente.
    Route::get('/activity', [ActivityController::class, 'index']);

    Route::get('/items', [ItemController::class, 'index']);

    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory/grant', [InventoryController::class, 'grant']);
    Route::post('/inventory/sell', [InventoryController::class, 'sell']);

    Route::get('/equipment', [EquipmentController::class, 'index']);
    Route::post('/equipment/equip', [EquipmentController::class, 'equip']);
    Route::delete('/equipment/{slot}', [EquipmentController::class, 'unequip']);

    // Fase 7/F21: mascota/expediciones -mismo principio, la mascota
    // siempre se deriva del usuario autenticado. Contrato reemplazado por
    // completo en F21 (ver docs/PETS_EXPEDITIONS_SYSTEM.md §13) -sin alias
    // de compatibilidad con las rutas viejas, frontend y backend se
    // actualizan juntos en la misma fase.
    Route::get('/pet', [PetController::class, 'show']);
    Route::get('/pet/species', [PetController::class, 'species']);
    Route::get('/pet/expeditions/definitions', [PetController::class, 'expeditionDefinitions']);
    Route::get('/pet/expeditions/current', [PetExpeditionController::class, 'current']);
    Route::post('/pet/expeditions/start', [PetExpeditionController::class, 'start']);
    Route::post('/pet/expeditions/{expedition}/claim', [PetExpeditionController::class, 'claim']);
    Route::post('/pet/expeditions/checkpoints/{checkpoint}/decide', [PetExpeditionController::class, 'decide']);
    Route::get('/pet/expeditions/history', [PetController::class, 'expeditionHistory']);

    // Fase 20: alimentación de mascotas -mismo principio de siempre, la
    // mascota siempre se deriva del usuario autenticado.
    Route::get('/pet/food', [PetController::class, 'food']);
    Route::post('/pet/feed', [PetController::class, 'feed']);

    // F8: economía/crafting -mismo principio, la receta la elige el
    // cliente, todo lo demás (ingredientes/resultado) lo resuelve el
    // backend.
    Route::get('/recipes', [RecipeController::class, 'index']);
    Route::post('/crafting/craft', [CraftingController::class, 'craft']);

    // Room: habitación personal + muebles colocables -mismo principio,
    // el room_item se re-valida contra la habitación del usuario
    // autenticado en cada acción, nunca se confía en el id de la URL solo.
    Route::get('/room', [RoomController::class, 'show']);
    Route::post('/room/objects', [RoomObjectController::class, 'store']);
    Route::patch('/room/objects/{roomObject}', [RoomObjectController::class, 'update']);
    Route::delete('/room/objects/{roomObject}', [RoomObjectController::class, 'destroy']);

    // Fase 10: combate asíncrono -mismo principio, el atacante siempre es
    // el personaje del usuario autenticado, nunca un id que mande el
    // cliente. El resultado completo (eventos/ganador/xp/monedas) lo
    // calcula el backend en el POST; Phaser solo lo reproduce.
    Route::get('/arena', [ArenaController::class, 'index']);
    // Fase 17: lista ANTES del show con {combatLog} -no compiten entre sí
    // (una es /arena/combats a secas, la otra pide un segmento extra), pero
    // queda en orden de lectura natural: primero la lista, después el
    // detalle de un ítem puntual.
    Route::get('/arena/combats', [ArenaController::class, 'combats']);
    Route::get('/arena/combats/{combatLog}', [ArenaController::class, 'show']);
    Route::post('/arena/attack', [ArenaAttackController::class, 'attack']);

    // Fase 16: Tienda (primera versión, catálogo fijo sin rotación) -mismo
    // principio, el personaje siempre se deriva del usuario autenticado,
    // el precio siempre sale de shop_products (DB), nunca del cliente.
    Route::get('/shop', [ShopController::class, 'index']);
    Route::post('/shop/purchase', [ShopController::class, 'purchase']);

    // Fase 13: endpoint propio y liviano para /ranking -reusa
    // ArenaRankingService tal cual (misma fuente de verdad que ya usa
    // GET /arena), sin acoplar la pantalla de Ranking a la respuesta
    // completa de Arena.
    Route::get('/ranking', [RankingController::class, 'index']);

    // Chat global (demo, polling HTTP) -mismo principio que el resto: el
    // usuario que aparece en cada mensaje siempre es el autenticado por
    // Sanctum, nunca uno que mande el cliente. GET ya cae bajo el limiter
    // global "api" (60/min, bootstrap/app.php); POST suma un throttle
    // propio más estricto -mismo patrón que /auth/register|login- porque
    // es el endpoint que un cliente hostil podría usar para spamear.
    Route::get('/chat/messages', [ChatController::class, 'index']);
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/chat/messages', [ChatController::class, 'store']);
    });

    // Fase 18: presencia de jugadores -HTTP + polling, sin Reverb-. Sin
    // throttle dedicado: mismo criterio que /character, /inventory, etc.
    // -no maneja contenido de usuario ni economía, el limiter global
    // "api" (60/min) ya alcanza para un heartbeat de ~1 request/25s-.
    Route::post('/presence/heartbeat', [PresenceController::class, 'heartbeat']);
    Route::get('/presence', [PresenceController::class, 'index']);

    // Fase 19.6 HOTFIX: throttle:api (60/min, aplicado globalmente a TODA
    // routes/api.php desde bootstrap/app.php -> $middleware->throttleApi())
    // se apilaba ADEMÁS de throttle:presence.position acá (confirmado con
    // `route:list -vv`, que expande los grupos de middleware) -dos
    // limiters activos a la vez sobre la MISMA request-. El tráfico de
    // movimiento (~200 req/min) agotaba en segundos el balde de 60/min que
    // esta misma ruta comparte con /presence, /presence/heartbeat y
    // /chat/messages para ese usuario -por eso esas otras rutas también
    // empezaban a devolver 429, sin que su propio tráfico las hubiera
    // superado nunca-. withoutMiddleware() saca a esta ruta del balde
    // compartido; el límite real de movimiento queda 100% definido por
    // presence.position (240/min, ver AppServiceProvider::boot()), sin
    // interferir con el resto de la API.
    Route::middleware('throttle:presence.position')
        ->withoutMiddleware('throttle:api')
        ->group(function () {
            Route::post('/presence/position', [PresenceController::class, 'position']);
        });
});
