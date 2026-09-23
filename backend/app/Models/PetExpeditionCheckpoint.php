<?php

namespace App\Models;

use App\Enums\CheckpointKind;
use App\Enums\CheckpointStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// F21 (docs/PETS_EXPEDITIONS_SYSTEM.md §3.1/§11): no existía nada de esto
// antes -tabla/modelo nuevos desde cero. payload SIEMPRE null hasta que el
// checkpoint pasa a Resolved (ver ExpeditionService::resolveDueCheckpoints).
class PetExpeditionCheckpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'pet_expedition_id',
        'sequence',
        'scheduled_at',
        'kind',
        'event_definition_id',
        'status',
        'decision',
        'resolved_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'kind' => CheckpointKind::class,
            'status' => CheckpointStatus::class,
            'resolved_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function expedition(): BelongsTo
    {
        return $this->belongsTo(PetExpedition::class, 'pet_expedition_id');
    }

    public function eventDefinition(): BelongsTo
    {
        return $this->belongsTo(ExpeditionEventDefinition::class, 'event_definition_id');
    }

    public function isDue(): bool
    {
        return $this->status === CheckpointStatus::Pending && now()->gte($this->scheduled_at);
    }
}
