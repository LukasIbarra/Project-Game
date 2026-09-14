<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Trim ANTES de validar: así "   " (solo espacios) cae correctamente
    // en 'required' en vez de guardarse como mensaje vacío-con-espacios.
    protected function prepareForValidation(): void
    {
        $this->merge([
            'message' => trim((string) $this->input('message', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:150'],
        ];
    }
}
