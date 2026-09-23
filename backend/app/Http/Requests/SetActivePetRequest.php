<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetActivePetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pet_id' => ['required', 'integer', 'exists:pets,id'],
        ];
    }
}
