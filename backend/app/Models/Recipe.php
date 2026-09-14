<?php

namespace App\Models;

use App\Enums\ItemRarity;
use App\Enums\RecipeCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'rarity',
        'result_item_id',
        'result_quantity',
        'required_level',
        'unlock_condition',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category' => RecipeCategory::class,
            'rarity' => ItemRarity::class,
            'is_active' => 'boolean',
        ];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'result_item_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }
}
