<?php

namespace App\Models;

use App\Enums\PetExpeditionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetExpedition extends Model
{
    use HasFactory;

    protected $fillable = [
        'pet_id',
        'destination_id',
        'status',
        'started_at',
        'ends_at',
        'resolved_at',
        'result_data_json',
    ];

    protected function casts(): array
    {
        return [
            'status' => PetExpeditionStatus::class,
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'resolved_at' => 'datetime',
            'result_data_json' => 'array',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(PetDestination::class, 'destination_id');
    }

    // Fase 7: "¿terminó de verdad?" según el reloj del servidor -toda la
    // duración final (incluyendo cualquier retraso de eventos) ya quedó
    // fijada en `ends_at` al iniciar la expedición (ver
    // PetExpeditionService::start), así que esto nunca necesita
    // recalcular nada, solo comparar timestamps.
    public function isDue(): bool
    {
        return $this->status === PetExpeditionStatus::Active && now()->gte($this->ends_at);
    }
}
