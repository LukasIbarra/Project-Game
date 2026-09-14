<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipe_key' => ['required', 'string', 'exists:recipes,key'],
        ];
    }
}
