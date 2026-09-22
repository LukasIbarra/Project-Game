<?php

namespace App\Enums;

// Fase 20: mismo vocabulario que PetNarrativeRarity -consistencia con el
// resto del proyecto-. Hoy solo describe la especie (sin efecto
// mecánico propio); F25 la usará para pesos de gachapon/huevos.
enum PetSpeciesRarity: string
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case VeryRare = 'very_rare';
}
