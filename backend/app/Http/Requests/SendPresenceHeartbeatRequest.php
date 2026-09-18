<?php

namespace App\Http\Requests;

use App\Enums\GameMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendPresenceHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Normaliza ANTES de validar -mismo criterio que
    // SendChatMessageRequest::prepareForValidation con el trim-: así
    // "/home", "Home" o "home/" llegan todas a la regla de abajo como
    // "home", en vez de rechazar variantes que en la práctica significan
    // lo mismo. La validación contra GameMap (abajo) es la que de verdad
    // impide un valor inventado.
    protected function prepareForValidation(): void
    {
        $map = strtolower(trim((string) $this->input('current_map', ''), "/ \t\n\r\0\x0B"));

        $this->merge(['current_map' => $map]);
    }

    public function rules(): array
    {
        return [
            'current_map' => ['required', 'string', Rule::enum(GameMap::class)],
        ];
    }
}
