<?php

namespace App\Services;

use App\Enums\PetModifierType;
use App\Models\Pet;
use App\Models\PetSpecies;

// Fase 20: resuelve los modificadores EFECTIVOS de una especie para un
// nivel dado -ver docs/PETS_EXPEDITIONS_SYSTEM.md §5.1/5.2. Ningún
// consumidor real todavía (F21 es quien lo va a usar para expediciones);
// F20 solo deja esta pieza correcta y testeada de forma aislada.
//
// Contrato de salida: array de arrays normalizados
// {type, scope, target, value} — `type` siempre un valor válido de
// PetModifierType (cualquier entrada con un `type` desconocido/legacy en
// el JSON se descarta silenciosamente, nunca rompe la resolución completa
// por una fila de contenido mal cargada).
class PetModifierResolver
{
    public function resolveForPet(Pet $pet): array
    {
        $pet->loadMissing('species');

        return $this->resolve($pet->species, $pet->level);
    }

    // Objetivo #4 del pedido de F20: "no necesariamente tiene que
    // mejorar en cada nivel" -se parte de los modificadores base de la
    // especie (nivel 1 implícito) y se OVERRIDEA por completo (nunca se
    // suma/acumula) con el milestone de mayor `level` que sea <= al nivel
    // actual. Sin ningún milestone aplicable, el resultado son los
    // modificadores base tal cual -"ausencia de milestone exacto" nunca
    // es un error, es el caso normal para la mayoría de los niveles-.
    public function resolve(PetSpecies $species, int $level): array
    {
        $effective = $this->normalize($species->modifiers_json ?? []);

        $milestones = $species->level_modifiers_json ?? [];
        usort($milestones, fn (array $a, array $b) => ($a['level'] ?? 0) <=> ($b['level'] ?? 0));

        foreach ($milestones as $milestone) {
            if (($milestone['level'] ?? null) === null) {
                continue;
            }

            if ((int) $milestone['level'] <= $level) {
                $effective = $this->normalize($milestone['modifiers'] ?? []);
            }
        }

        return $effective;
    }

    private function normalize(array $rawModifiers): array
    {
        $normalized = [];

        foreach ($rawModifiers as $raw) {
            $type = PetModifierType::tryFrom($raw['type'] ?? '');
            if ($type === null) {
                continue; // tipo desconocido/legacy -se ignora, nunca revienta la resolución-.
            }

            $normalized[] = [
                'type' => $type->value,
                'scope' => (string) ($raw['scope'] ?? 'global'),
                'target' => isset($raw['target']) ? (string) $raw['target'] : null,
                'value' => (float) ($raw['value'] ?? 0),
            ];
        }

        return $normalized;
    }
}
