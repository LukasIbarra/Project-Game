<?php

namespace App\Models;

use App\Enums\PetStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pet extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'species_id',
        'name',
        'level',
        'exp',
        'health',
        'max_health',
        'energy',
        'max_energy',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => PetStatus::class,
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    // Fase 20: reemplaza el antiguo `key` string suelto -ver migración
    // 2026_09_19_000002_add_species_id_to_pets_table-.
    public function species(): BelongsTo
    {
        return $this->belongsTo(PetSpecies::class, 'species_id');
    }

    public function expeditions(): HasMany
    {
        return $this->hasMany(PetExpedition::class);
    }
}
