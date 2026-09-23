<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartExpeditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expedition_key' => ['required', 'string', 'exists:expedition_definitions,key'],
        ];
    }
}
