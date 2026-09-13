<?php

namespace App\Enums;

enum PetExpeditionStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Claimed = 'claimed';
}
