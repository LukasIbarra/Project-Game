<?php

namespace App\Enums;

// F21 (docs/PETS_EXPEDITIONS_SYSTEM.md §11): estado de un
// PetExpeditionCheckpoint individual.
enum CheckpointStatus: string
{
    case Pending = 'pending';                   // todavía no llegó scheduled_at
    case AwaitingDecision = 'awaiting_decision'; // vencido, evento elegido, requiere POST decide (F22)
    case Resolved = 'resolved';                  // terminado, payload fijo
}
