<?php

namespace App\Enums;

// F7.1: probabilidad de selección, ver
// PetExpeditionService::rollNarrativeRarity — distribución objetivo
// aproximada: common 65-75%, uncommon 20-25%, rare 5-8%, very_rare 1-2%.
enum PetNarrativeRarity: string
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case VeryRare = 'very_rare';
}
