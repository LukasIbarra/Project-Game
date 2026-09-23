<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckpointKind;
use App\Enums\PetExpeditionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideCheckpointRequest;
use App\Http\Requests\StartExpeditionRequest;
use App\Models\ExpeditionDefinition;
use App\Models\PetExpeditionCheckpoint;
use App\Services\ExpeditionService;
use App\Services\PetProvisioningService;
use App\Support\PetPresenter;
use Illuminate\Http\Request;

class PetExpeditionController extends Controller
{
    public function __construct(
        private readonly PetProvisioningService $provisioning,
        private readonly ExpeditionService $expeditions
    ) {
    }

    public function start(StartExpeditionRequest $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);

        $existing = $this->expeditions->currentFor($pet);
        if ($existing) {
            return response()->json([
                'message' => $existing->status === PetExpeditionStatus::Active
                    ? 'Tu mascota ya está explorando.'
                    : 'Tu mascota tiene recompensas sin reclamar. Reclámalas antes de iniciar otra expedición.',
            ], 409);
        }

        $definition = ExpeditionDefinition::where('key', $request->string('expedition_key'))->firstOrFail();

        if ($pet->level < $definition->min_pet_level) {
            return response()->json([
                'message' => "Tu mascota necesita nivel {$definition->min_pet_level} para esta expedición.",
            ], 409);
        }

        $expedition = $this->expeditions->start($pet, $definition);

        return response()->json(PetPresenter::expedition($expedition), 201);
    }

    public function current(Request $request)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);
        $expedition = $this->expeditions->currentFor($pet);

        if (! $expedition) {
            return response()->json(['message' => 'No hay ninguna expedición en curso.'], 404);
        }

        return response()->json(PetPresenter::expedition($expedition));
    }

    public function claim(Request $request, int $expedition)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);

        // whereKey+where('pet_id', ...) en vez de findOrFail suelto -nunca
        // resuelve una expedición de otra mascota, sea cual sea el id que
        // mande el cliente (mismo criterio que el resto del proyecto:
        // ownership siempre server-side, nunca confiado del cliente).
        $model = $pet->expeditions()->whereKey($expedition)->first();
        if (! $model) {
            return response()->json(['message' => 'No se encontró esa expedición.'], 404);
        }

        $model = $this->expeditions->resolveDueCheckpoints($model);

        if ($model->status === PetExpeditionStatus::Active) {
            return response()->json(['message' => 'La expedición todavía no terminó.'], 409);
        }

        $loot = $this->expeditions->claim($model);

        return response()->json([
            'expedition' => PetPresenter::expedition($model->fresh()),
            'loot' => $loot,
        ]);
    }

    // F21: preparado con locking/idempotencia real (ver
    // ExpeditionService::decide()), aunque F21 no genera checkpoints en
    // awaiting_decision de forma natural todavía -eso es F22-. Devuelve
    // 409 mientras ese sea el caso, que es siempre hoy.
    public function decide(DecideCheckpointRequest $request, int $checkpoint)
    {
        $character = $request->user()->character;
        if (! $character) {
            return response()->json(['message' => 'Este usuario no tiene un personaje.'], 404);
        }

        $pet = $this->provisioning->ensureForCharacter($character);

        $model = PetExpeditionCheckpoint::whereKey($checkpoint)
            ->whereHas('expedition', fn ($query) => $query->where('pet_id', $pet->id))
            ->first();

        if (! $model) {
            return response()->json(['message' => 'No se encontró ese evento de expedición.'], 404);
        }

        // `kind` es estructural -se fija en planCheckpoints() y nunca
        // cambia- a diferencia de `status`, que sí cambia bajo concurrencia:
        // chequear `kind` acá (no `status`) es lo que hace este 409 seguro
        // ante una carrera real. Un checkpoint kind=event que YA se
        // resolvió (por una decide() concurrente que ganó el lock primero)
        // sigue pasando este chequeo -ExpeditionService::decide() es quien
        // responde el resultado ya existente sin volver a aplicarlo,
        // idempotente (docs/PETS_EXPEDITIONS_SYSTEM.md §12.1)-.
        if ($model->kind !== CheckpointKind::Event) {
            return response()->json(['message' => 'Ese evento no admite una decisión.'], 409);
        }

        $model = $this->expeditions->decide($model, $request->string('decision'));

        return response()->json([
            'id' => $model->id,
            'status' => $model->status->value,
            'payload' => $model->payload,
        ]);
    }
}
