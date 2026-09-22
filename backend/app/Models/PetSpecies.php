<?php

namespace App\Models;

use App\Enums\PetSpeciesRarity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Fase 20: catálogo de especies -ver
// docs/PETS_EXPEDITIONS_SYSTEM.md §6-. `modifiers_json`/
// `level_modifiers_json` se leen crudos acá (array); la interpretación
// tipada/validada vive en App\Services\PetModifierResolver, nunca en
// este modelo ni en el JSON en sí.
class PetSpecies extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'rarity',
        'sprite_key',
        'modifiers_json',
        'level_modifiers_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rarity' => PetSpeciesRarity::class,
            'modifiers_json' => 'array',
            'level_modifiers_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function pets(): HasMany
    {
        return $this->hasMany(Pet::class, 'species_id');
    }
}
