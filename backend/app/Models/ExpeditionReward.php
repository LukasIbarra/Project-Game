<?php

namespace App\Models;

use App\Enums\ItemRarity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// F21 (docs/PETS_EXPEDITIONS_SYSTEM.md §5.6): reemplaza
// expedition_definitions.loot_pool_json -loot table normalizada con pesos
// reales, para poder calcular reward_preview con porcentajes server-side
// (weight / SUM(weight) * 100) en vez de "elegí 2 al azar, uniforme".
class ExpeditionReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'expedition_definition_id',
        'item_id',
        'weight',
        'min_qty',
        'max_qty',
        'rarity_tier',
    ];

    protected function casts(): array
    {
        return [
            'rarity_tier' => ItemRarity::class,
        ];
    }

    public function expeditionDefinition(): BelongsTo
    {
        return $this->belongsTo(ExpeditionDefinition::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
