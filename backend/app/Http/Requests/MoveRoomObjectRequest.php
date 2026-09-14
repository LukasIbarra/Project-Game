<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveRoomObjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tile_x' => ['required', 'integer', 'min:0'],
            'tile_y' => ['required', 'integer', 'min:0'],
        ];
    }
}
