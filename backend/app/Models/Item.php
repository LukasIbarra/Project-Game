<?php

namespace App\Models;

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
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'stackable' => 'boolean',
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
}
