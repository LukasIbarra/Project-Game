<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Fase 10, sección 4: el payload es mínimo a propósito -solo el
// objetivo-. El atacante SIEMPRE se deriva de auth:sanctum en el
// controller, nunca de un campo del request (CLAUDE.md #1); si el
// cliente manda `attacker_character_id`, `damage`, `winner`, etc., estos
// campos ni siquiera están declarados acá, así que quedan ignorados —
// mismo patrón que CraftRequest (F8) con `ingredients`/`output_item`.
class AttackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'defender_character_id' => ['required', 'integer', 'exists:characters,id'],
        ];
    }
}
