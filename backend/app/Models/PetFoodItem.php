<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fase 20: catálogo de comida para mascotas -relación real con `items`
// (nunca un item_key suelto), mismo patrón de catálogo que
// pet_destinations/recipes. `favorite_species_ids_json` queda nullable
// desde el día uno para que "comida favorita" (futuro, ver
// docs/PETS_EXPEDITIONS_SYSTEM.md §5.4) sea aditivo -F20 no la usa
// todavía, ningún resolver la lee-.
class PetFoodItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id',
        'exp_value',
        'favorite_species_ids_json',
    ];

    protected function casts(): array
    {
        return [
            'favorite_species_ids_json' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
