<?php

namespace App\Http\Controllers\Api;

use App\Enums\PetExpeditionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartPetExpeditionRequest;
use App\Models\PetDestination;
use App\Services\PetExpeditionService;
use App\Services\PetProvisioningService;
use App\Support\PetPresenter;
use Illuminate\Http\Request;

class PetExpeditionController extends Controller
{
    public function __construct(
        private readonly PetProvisioningService $provisioning,
        private readonly PetExpeditionService $expeditions
    ) {
    }

    public function start(StartPetExpeditionRequest $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);

        $existing = $pet->expeditions()
            ->whereIn('status', [PetExpeditionStatus::Active, PetExpeditionStatus::Completed])
            ->latest('id')
            ->first();

        if ($existing) {
            $existing = $this->expeditions->resolveIfDue($existing);

            if ($existing->status !== PetExpeditionStatus::Claimed) {
                return response()->json([
                    'message' => $existing->status === PetExpeditionStatus::Active
                        ? 'Tu mascota ya está explorando.'
                        : 'Tu mascota tiene recompensas sin reclamar. Reclámalas antes de iniciar otra expedición.',
                ], 409);
            }
        }

        $destination = PetDestination::where('key', $request->string('destination_key'))->firstOrFail();

        $expedition = $this->expeditions->start($pet, $destination);

        return response()->json(PetPresenter::expedition($expedition), 201);
    }

    public function claim(Request $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);

        $expedition = $pet->expeditions()
            ->whereIn('status', [PetExpeditionStatus::Active, PetExpeditionStatus::Completed])
            ->latest('id')
            ->first();

        if (! $expedition) {
            return response()->json(['message' => 'No hay ninguna expedición para reclamar.'], 404);
        }

        $expedition = $this->expeditions->resolveIfDue($expedition);

        if ($expedition->status === PetExpeditionStatus::Active) {
            return response()->json(['message' => 'La expedición todavía no terminó.'], 409);
        }

        $loot = $this->expeditions->claim($expedition);

        return response()->json([
            'expedition' => PetPresenter::expedition($expedition->fresh()),
            'loot' => $loot,
        ]);
    }
}
