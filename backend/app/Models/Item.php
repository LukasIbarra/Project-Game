<?php

namespace App\Models;

use App\Enums\ItemRarity;
use App\Enums\ItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'type',
        'subtype',
        'stackable',
        'max_stack',
        'sell_value',
        'icon',
        'rarity',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'stackable' => 'boolean',
            'rarity' => ItemRarity::class,
            'metadata_json' => 'array',
        ];
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function roomItems(): HasMany
    {
        return $this->hasMany(RoomItem::class);
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function recipesProducing(): HasMany
    {
        return $this->hasMany(Recipe::class, 'result_item_id');
    }

    public function isSellable(): bool
    {
        return $this->sell_value > 0;
    }
}
