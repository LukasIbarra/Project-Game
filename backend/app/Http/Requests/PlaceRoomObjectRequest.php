<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceRoomObjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_key' => ['required', 'string', 'exists:items,key'],
            'tile_x' => ['required', 'integer', 'min:0'],
            'tile_y' => ['required', 'integer', 'min:0'],
        ];
    }
}
