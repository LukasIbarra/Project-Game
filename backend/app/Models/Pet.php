<?php

namespace App\Models;

use App\Enums\PetStatus;
use Illuminate\Database\Eloquent\Builder;
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
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PetStatus::class,
            'retired_at' => 'datetime',
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

    // F23: "mis mascotas" (colección, catálogo, elegir activa) nunca debe
    // incluir Pets retiradas (legacy "Compañero", ver
    // 2026_09_23_000004_retire_legacy_starter_pets) -su fila sigue
    // existiendo por integridad histórica con pet_expeditions, pero deja
    // de ser jugable. Nombrado `notRetired`, no `active`, para no
    // confundirse con el concepto de "mascota ACTIVA"
    // (characters.active_pet_id) -son dos ejes distintos-.
    public function scopeNotRetired(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }
}
