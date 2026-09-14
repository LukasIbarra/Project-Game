<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Character extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'level',
        'exp',
        'strength',
        'agility',
        'vitality',
        'coins',
        'appearance_json',
    ];

    protected function casts(): array
    {
        return [
            'appearance_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(CharacterEquipment::class);
    }

    public function pet(): HasOne
    {
        return $this->hasOne(Pet::class);
    }

    public function room(): HasOne
    {
        return $this->hasOne(Room::class);
    }

    public function attackedCombatLogs(): HasMany
    {
        return $this->hasMany(CombatLog::class, 'attacker_character_id');
    }

    public function defendedCombatLogs(): HasMany
    {
        return $this->hasMany(CombatLog::class, 'defender_character_id');
    }
}
