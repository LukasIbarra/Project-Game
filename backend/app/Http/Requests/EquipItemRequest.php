<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EquipItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Solo valida que la instancia EXISTA -no que pertenezca al
            // usuario autenticado, eso es una regla de negocio (ownership),
            // no de formato, y se verifica en el controller (CLAUDE.md:
            // el servidor nunca confía en ownership implícito del cliente).
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
        ];
    }
}
