<?php

namespace App\Http\Controllers\Api;

use App\Enums\PetExpeditionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeedPetRequest;
use App\Models\Item;
use App\Models\PetDestination;
use App\Models\PetExpedition;
use App\Models\PetFoodItem;
use App\Services\PetExpeditionService;
use App\Services\PetFeedingService;
use App\Services\PetProvisioningService;
use App\Support\PetPresenter;
use Illuminate\Http\Request;

class PetController extends Controller
{
    public function __construct(
        private readonly PetProvisioningService $provisioning,
        private readonly PetExpeditionService $expeditions,
        private readonly PetFeedingService $feeding
    ) {
    }

    public function show(Request $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);
        $expedition = $this->currentExpedition($pet);

        return response()->json(PetPresenter::pet($pet, $expedition));
    }

    public function destinations()
    {
        return response()->json(
            PetDestination::orderBy('difficulty')->get()
        );
    }

    public function expedition(Request $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);
        $expedition = $this->currentExpedition($pet);

        if (! $expedition) {
            return response()->json(['message' => 'No hay ninguna expedición en curso.'], 404);
        }

        return response()->json(PetPresenter::expedition($expedition));
    }

    public function events(Request $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);

        // Solo Claimed: una expedición Completed-sin-reclamar ya se
        // muestra como acción pendiente en GET /pet (panel "Estado") -
        // listarla acá también sería mostrar loot como "recibido" cuando
        // todavía no pasó al inventario.
        $history = $pet->expeditions()
            ->where('status', PetExpeditionStatus::Claimed)
            ->with('destination')
            ->latest('id')
            ->take(20)
            ->get();

        return response()->json($history->map(fn (PetExpedition $expedition) => PetPresenter::expedition($expedition)));
    }

    // Fase 20: catálogo de comida -mismo criterio que destinations(),
    // datos de configuración, no algo que dependa del personaje
    // autenticado. `item_name` ya resuelto acá para que el frontend no
    // tenga que cruzar contra el catálogo de items por separado.
    public function food()
    {
        $food = PetFoodItem::with('item')->get();

        return response()->json($food->map(fn (PetFoodItem $food) => [
            'item_key' => $food->item->key,
            'item_name' => $food->item->name,
            'exp_value' => $food->exp_value,
        ]));
    }

    // POST /v1/pet/feed { food_key }
    public function feed(FeedPetRequest $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);

        // FeedPetRequest ya validó que el item EXISTE en el catálogo -acá
        // se verifica que además sea comida de mascota, una regla de
        // negocio, no de formato (mismo criterio que
        // EquipmentController::equip() con "no es equipable").
        $item = Item::where('key', $request->string('food_key'))->firstOrFail();
        $food = PetFoodItem::where('item_id', $item->id)->first();

        if (! $food) {
            return response()->json(['message' => 'Ese item no es comida para mascotas.'], 422);
        }

        try {
            $result = $this->feeding->feed($character, $pet, $food);
        } catch (\RuntimeException $e) {
            // InventoryGrantService::consume() -no hay suficiente cantidad.
            return response()->json(['message' => 'No tenés ese alimento en tu inventario.'], 422);
        }

        return response()->json([
            'pet' => PetPresenter::pet($result['pet'], $this->currentExpedition($result['pet'])),
            'leveled_up' => $result['leveled_up'],
        ]);
    }

    // "La expedición que importa ahora mismo" -activa o completada pero
    // todavía sin reclamar-. Una vez reclamada (Claimed) deja de ser
    // "la actual" y pasa a vivir solo en /pet/events (historial).
    private function currentExpedition($pet): ?PetExpedition
    {
        $expedition = $pet->expeditions()
            ->whereIn('status', [PetExpeditionStatus::Active, PetExpeditionStatus::Completed])
            ->latest('id')
            ->first();

        if (! $expedition) {
            return null;
        }

        return $this->expeditions->resolveIfDue($expedition);
    }
}
