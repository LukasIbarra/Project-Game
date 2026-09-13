<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'item_id',
        'quantity',
        'instance_data_json',
    ];

    protected function casts(): array
    {
        return [
            'instance_data_json' => 'array',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    // Presente solo si esta instancia está equipada en algún slot.
    public function equippedAs(): HasOne
    {
        return $this->hasOne(CharacterEquipment::class);
    }
}
