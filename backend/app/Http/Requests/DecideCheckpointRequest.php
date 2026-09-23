<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// F21: validación de forma únicamente -qué valores de `decision` son
// válidos para un checkpoint concreto depende de su
// ExpeditionEventDefinition.config_json (F22, todavía no existe ningún
// checkpoint real en awaiting_decision, ver ExpeditionService::decide()).
class DecideCheckpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'max:50'],
        ];
    }
}
