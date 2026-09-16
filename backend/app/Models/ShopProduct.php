<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fase 16: fila de catálogo de la Tienda -"este item se puede comprar a
// este precio", nada más. Sin rotación/stock todavía (primera versión).
class ShopProduct extends Model
{
    protected $fillable = [
        'item_id',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
