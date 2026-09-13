<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CombatLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'attacker_character_id',
        'defender_character_id',
        'winner_character_id',
        'seed',
        'status',
        'started_at',
        'finished_at',
        'events_json',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'events_json' => 'array',
        ];
    }

    public function attacker(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'attacker_character_id');
    }

    public function defender(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'defender_character_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'winner_character_id');
    }
}
