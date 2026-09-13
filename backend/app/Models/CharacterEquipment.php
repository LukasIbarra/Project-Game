<?php

namespace App\Models;

use App\Enums\EquipmentSlot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterEquipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'inventory_item_id',
        'slot',
    ];

    protected function casts(): array
    {
        return [
            'slot' => EquipmentSlot::class,
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
