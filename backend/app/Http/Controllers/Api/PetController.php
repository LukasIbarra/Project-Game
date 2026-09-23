<?php

namespace App\Http\Controllers\Api;

use App\Enums\PetExpeditionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdoptPetRequest;
use App\Http\Requests\FeedPetRequest;
use App\Http\Requests\SetActivePetRequest;
use App\Models\ExpeditionDefinition;
use App\Models\Item;
use App\Models\PetExpedition;
use App\Models\PetFoodItem;
use App\Models\PetSpecies;
use App\Services\ExpeditionService;
use App\Services\PetAdoptionService;
use App\Services\PetFeedingService;
use App\Services\PetProvisioningService;
use App\Support\PetPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PetController extends Controller
{
    public function __construct(
        private readonly PetProvisioningService $provisioning,
        private readonly PetAdoptionService $adoption,
        private readonly ExpeditionService $expeditions,
        private readonly PetFeedingService $feeding
    ) {
    }

    // F23: ya NO auto-crea nada -si el personaje no tiene mascota activa
    // (usuario nuevo, o legacy con "Compañero" retirado), responde 404. El
    // frontend usa esto para decidir si muestra la selección inicial o la
    // interfaz normal (ver pet.astro).
    public function show(Request $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->activePetFor($character);
        if (! $pet) {
            return response()->json(['message' => 'Este personaje todavía no tiene una mascota activa.'], 404);
        }

        $expedition = $this->expeditions->currentFor($pet);

        return response()->json(PetPresenter::pet($pet, $expedition));
    }

    // F21/F23: catálogo de especies -ahora incluye lo necesario para
    // adopción/colección (sprite, precio, disponibilidad como starter,
    // si el usuario ya la posee). Sigue sin exponer modifiers_json/
    // level_modifiers_json crudo (docs/PETS_EXPEDITIONS_SYSTEM.md §13).
    public function species(Request $request)
    {
        $character = $request->user()->character;
        $ownedSpeciesIds = $character
            ? $character->pets()->notRetired()->pluck('species_id')->all()
            : [];

        return response()->json(
            PetSpecies::where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (PetSpecies $species) => PetPresenter::species($species, in_array($species->id, $ownedSpeciesIds, true)))
        );
    }

    // F23: todas las mascotas del personaje (colección) + cuál es la
    // activa -nunca incluye Pets retiradas (legacy).
    public function mine(Request $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pets = $character->pets()->notRetired()->with('species')->orderBy('id')->get();

        return response()->json([
            'active_pet_id' => $character->active_pet_id,
            'pets' => $pets->map(fn ($pet) => PetPresenter::petSummary($pet))->all(),
        ]);
    }

    // POST /v1/pet/adopt { species_key } — primera mascota, gratis.
    public function adopt(AdoptPetRequest $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $species = PetSpecies::where('key', $request->string('species_key'))->firstOrFail();

        try {
            $pet = $this->adoption->adoptStarter($character, $species);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first()], 409);
        }

        return response()->json(PetPresenter::pet($pet, null), 201);
    }

    // POST /v1/pet/species/{key}/purchase — mascota adicional, cobra
    // species.adoption_price (nunca un precio que mande el cliente).
    public function purchase(Request $request, string $key)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $species = PetSpecies::where('key', $key)->where('is_active', true)->first();
        if (! $species) {
            return response()->json(['message' => 'Esa especie no existe.'], 404);
        }

        try {
            $pet = $this->adoption->purchase($character, $species);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first()], 422);
        }

        return response()->json(PetPresenter::petSummary($pet), 201);
    }

    // POST /v1/pet/active { pet_id } — cambia cuál es la mascota activa.
    // No toca ninguna PetExpedition -ver docs/PETS_EXPEDITIONS_SYSTEM.md,
    // nota de F23: una expedición pertenece a una Pet concreta para
    // siempre, cambiar la activa nunca la reasigna-.
    public function setActive(SetActivePetRequest $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $character->pets()->whereKey($request->integer('pet_id'))->first();
        if (! $pet) {
            return response()->json(['message' => 'No se encontró esa mascota.'], 404);
        }

        try {
            $this->adoption->setActive($character, $pet);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first()], 409);
        }

        $expedition = $this->expeditions->currentFor($pet);

        return response()->json(PetPresenter::pet($pet->fresh(), $expedition));
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
    // contrato nuevo (/pet/expeditions/history). F23: el historial es de
    // la mascota ACTIVA -expediciones de mascotas retiradas/no-activas no
    // se listan acá (siguen íntegras en DB, solo no se muestran por esta
    // vía; no hay UI todavía para ver historial de otra mascota).
    public function expeditionHistory(Request $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->activePetFor($character);
        if (! $pet) {
            return response()->json(['message' => 'Este personaje todavía no tiene una mascota activa.'], 404);
        }

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

        $pet = $this->provisioning->activePetFor($character);
        if (! $pet) {
            return response()->json(['message' => 'Este personaje todavía no tiene una mascota activa.'], 404);
        }

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
