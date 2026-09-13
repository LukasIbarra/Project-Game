<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PetDestination extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'difficulty',
        'duration_minutes',
        'loot_min_tier',
        'loot_max_tier',
        'loot_pool_json',
    ];

    protected function casts(): array
    {
        return [
            'loot_pool_json' => 'array',
        ];
    }

    public function expeditions(): HasMany
    {
        return $this->hasMany(PetExpedition::class, 'destination_id');
    }
}
