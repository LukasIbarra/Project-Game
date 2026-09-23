<?php

namespace App\Http\Controllers\Api;

use App\Enums\PetExpeditionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeedPetRequest;
use App\Models\ExpeditionDefinition;
use App\Models\Item;
use App\Models\PetExpedition;
use App\Models\PetFoodItem;
use App\Models\PetSpecies;
use App\Services\ExpeditionService;
use App\Services\PetFeedingService;
use App\Services\PetProvisioningService;
use App\Support\PetPresenter;
use Illuminate\Http\Request;

class PetController extends Controller
{
    public function __construct(
        private readonly PetProvisioningService $provisioning,
        private readonly ExpeditionService $expeditions,
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
        $expedition = $this->expeditions->currentFor($pet);

        return response()->json(PetPresenter::pet($pet, $expedition));
    }

    // F21: catálogo de especies -sin modifiers_json/level_modifiers_json
    // crudo expuesto (docs/PETS_EXPEDITIONS_SYSTEM.md §13), esos son
    // detalles de balance server-side, no algo que el frontend consuma.
    public function species()
    {
        return response()->json(
            PetSpecies::where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (PetSpecies $species) => [
                    'key' => $species->key,
                    'name' => $species->name,
                    'description' => $species->description,
                    'rarity' => $species->rarity->value,
                ])
        );
    }

    // F21: reemplaza destinations() -mismo endpoint de catálogo, ahora con
    // reward_preview calculado server-side (ver PetPresenter).
    public function expeditionDefinitions()
    {
        $definitions = ExpeditionDefinition::where('is_active', true)
            ->orderBy('difficulty')
            ->with('rewards.item')
            ->get();

        return response()->json($definitions->map(fn (ExpeditionDefinition $definition) => PetPresenter::expeditionDefinition($definition)));
    }

    // F21: reemplaza events() -mismo criterio exacto (solo Claimed, una
    // Completed-sin-reclamar ya se muestra como acción pendiente en
    // GET /pet), solo la ruta cambia de nombre para alinearse al resto del
    // contrato nuevo (/pet/expeditions/history).
    public function expeditionHistory(Request $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);

        $history = $pet->expeditions()
            ->where('status', PetExpeditionStatus::Claimed)
            ->with('expeditionDefinition', 'checkpoints')
            ->latest('id')
            ->take(20)
            ->get();

        return response()->json($history->map(fn (PetExpedition $expedition) => PetPresenter::expedition($expedition)));
    }

    // Fase 20: catálogo de comida -mismo criterio que expeditionDefinitions(),
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
            'pet' => PetPresenter::pet($result['pet'], $this->expeditions->currentFor($result['pet'])),
            'leveled_up' => $result['leveled_up'],
        ]);
    }
}
