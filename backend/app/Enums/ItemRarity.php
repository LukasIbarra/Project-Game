<?php

namespace App\Enums;

// F8: puramente descriptivo/UI (color-coding, badges) — no hay un
// sistema de tiers que filtre drops ni crafting por esto todavía. Mismas
// 4 categorías que PetNarrativeRarity pero declarado aparte a propósito:
// dominios distintos (items/recetas vs. eventos narrativos de mascota),
// no hace falta acoplarlos para compartir 4 strings.
enum ItemRarity: string
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case VeryRare = 'very_rare';
}
