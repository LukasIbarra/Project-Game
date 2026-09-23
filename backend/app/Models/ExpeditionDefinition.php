<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// F21: reemplaza PetDestination (misma tabla, renombrada -ver migración
// 2026_09_22_000001). Las keys reales existentes (forest/mountains/
// blood_castle) se conservan sin cambios, ver ExpeditionDefinitionSeeder.
class ExpeditionDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'difficulty',
        'duration_seconds',
        'min_pet_level',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function expeditions(): HasMany
    {
        return $this->hasMany(PetExpedition::class, 'expedition_definition_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(ExpeditionReward::class);
    }

    public function eventDefinitions(): HasMany
    {
        return $this->hasMany(ExpeditionEventDefinition::class);
    }
}
