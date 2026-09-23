<?php

namespace App\Models;

use App\Enums\ExpeditionEventType;
use App\Enums\PetNarrativeRarity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// F21 (docs/PETS_EXPEDITIONS_SYSTEM.md §5.7): unifica pet_narrative_events
// (migrado acá con type=narrative, ver migración 2026_09_22_000004) +
// config/pet_events.php (eventos mecánicos, migración real queda para F22
// cuando esos tipos tengan resolución implementada). null en
// expedition_definition_id = evento universal, mismo criterio que antes.
class ExpeditionEventDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'expedition_definition_id',
        'type',
        'rarity',
        'weight',
        'is_active',
        'title',
        'text',
        'config_json',
    ];

    protected function casts(): array
    {
        return [
            'type' => ExpeditionEventType::class,
            'rarity' => PetNarrativeRarity::class,
            'is_active' => 'boolean',
            'config_json' => 'array',
        ];
    }

    public function expeditionDefinition(): BelongsTo
    {
        return $this->belongsTo(ExpeditionDefinition::class);
    }
}
