<?php

namespace App\Http\Controllers\Api;

use App\Enums\PetExpeditionStatus;
use App\Http\Controllers\Controller;
use App\Models\PetDestination;
use App\Models\PetExpedition;
use App\Services\PetExpeditionService;
use App\Services\PetProvisioningService;
use App\Support\PetPresenter;
use Illuminate\Http\Request;

class PetController extends Controller
{
    public function __construct(
        private readonly PetProvisioningService $provisioning,
        private readonly PetExpeditionService $expeditions
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
