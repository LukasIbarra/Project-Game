<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fase 18: una sola fila mutable por personaje (character_id es unique en
// la migración), reescrita en cada heartbeat -nunca un log append-only
// como ActivityEvent-. Por eso solo se desactiva CREATED_AT, no
// UPDATED_AT: acá sí importa "cuándo se tocó por última vez".
class PlayerPresence extends Model
{
    const CREATED_AT = null;

    protected $table = 'player_presence';

    protected $fillable = [
        'character_id',
        'last_seen_at',
        'current_map',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
