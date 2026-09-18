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

    // Render+Neon: default de `appearance_json` movido acá desde la
    // migración (antes usaba `JSON_OBJECT('body', 'base')`, específico de
    // MySQL/MariaDB — no existe con esa firma en PostgreSQL). `$attributes`
    // guarda el valor CRUDO (sin castear) que tendría la columna, igual
    // que Eloquent ya lo espera -el cast 'array' de abajo se aplica al
    // leer/escribir, no acá-. Portable a cualquier motor, sin SQL crudo.
    protected $attributes = [
        'appearance_json' => '{"body":"base"}',
    ];

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

    // Fase 18: presencia (heartbeat HTTP + polling, sin Reverb).
    public function presence(): HasOne
    {
        return $this->hasOne(PlayerPresence::class);
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
