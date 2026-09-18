<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Fase 19.2: valida el body de POST /v1/presence/position. Los límites de
// mapa (1280x800) son los mismos que usa WorldMap.ts en el frontend (mapa
// de Tiled 80x50 tiles de 16px, ver auditoría de F19) -no hay forma de
// compartir una constante entre PHP y TypeScript, así que este valor
// queda documentado acá y en el frontend por separado, no derivado de una
// fuente única.
//
// `direction` solo acepta las 4 cardinales: es lo único que el movimiento
// real de Mundo puede producir hoy (WorldPlayer.update(), sin diagonales)
// aunque el tipo `Direction` del frontend ya soporte 8 -no se acepta acá
// hasta que el movimiento real las produzca.
class SendPresencePositionRequest extends FormRequest
{
    private const MAP_WIDTH = 1280;
    private const MAP_HEIGHT = 800;

    public const ALLOWED_DIRECTIONS = ['up', 'down', 'left', 'right'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // numeric (no integer): position_x/position_y son decimal(8,2)
            // -coordenadas con posibles decimales, no tiles enteros-.
            'x' => ['required', 'numeric', 'min:0', 'max:' . self::MAP_WIDTH],
            'y' => ['required', 'numeric', 'min:0', 'max:' . self::MAP_HEIGHT],
            'direction' => ['required', 'string', Rule::in(self::ALLOWED_DIRECTIONS)],
        ];
    }
}
