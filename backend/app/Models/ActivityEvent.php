<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fase 12: fila append-only del feed de actividad de un personaje. Nunca se
// edita después de creada -de ahí UPDATED_AT = null, la tabla no tiene esa
// columna (ver migración)-.
class ActivityEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'character_id',
        'type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
