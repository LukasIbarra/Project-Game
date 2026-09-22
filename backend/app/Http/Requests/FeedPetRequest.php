<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeedPetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Solo valida que el item EXISTA en el catálogo -no que sea
            // comida de mascota ni que el personaje lo tenga en
            // inventario, eso es una regla de negocio que se verifica en
            // el controller (mismo criterio que EquipItemRequest).
            'food_key' => ['required', 'string', 'exists:items,key'],
        ];
    }
}
