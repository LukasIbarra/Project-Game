<?php

namespace App\Models;

use App\Enums\PetNarrativeCategory;
use App\Enums\PetNarrativeRarity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetNarrativeEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'destination_id',
        'category',
        'rarity',
        'text',
        'weight',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category' => PetNarrativeCategory::class,
            'rarity' => PetNarrativeRarity::class,
            'is_active' => 'boolean',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(PetDestination::class, 'destination_id');
    }
}
