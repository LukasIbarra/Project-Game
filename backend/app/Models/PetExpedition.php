<?php

namespace App\Models;

use App\Enums\PetExpeditionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PetExpedition extends Model
{
    use HasFactory;

    protected $fillable = [
        'pet_id',
        'expedition_definition_id',
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

    public function expeditionDefinition(): BelongsTo
    {
        return $this->belongsTo(ExpeditionDefinition::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(PetExpeditionCheckpoint::class)->orderBy('sequence');
    }

    // F21: "¿terminó de verdad?" -a diferencia del diseño anterior
    // (F7, todo calculado en start()), ahora esto depende de DOS
    // condiciones: el reloj YA pasó ends_at, Y no queda ningún checkpoint
    // pending/awaiting_decision sin resolver. Ver
    // ExpeditionService::resolveDueCheckpoints, que es quien realmente
    // decide y persiste la transición a Completed -este método es de
    // lectura, no muta nada.
    public function isReadyToComplete(): bool
    {
        if ($this->status !== PetExpeditionStatus::Active || now()->lt($this->ends_at)) {
            return false;
        }

        return ! $this->checkpoints()
            ->whereIn('status', ['pending', 'awaiting_decision'])
            ->exists();
    }
}
