<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SellItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_key' => ['required', 'string', 'exists:items,key'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
