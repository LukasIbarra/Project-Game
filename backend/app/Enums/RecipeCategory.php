<?php

namespace App\Enums;

// F8, sección 14: categorías de filtro de la UI de /crafting.
enum RecipeCategory: string
{
    case Materials = 'materials';
    case Consumables = 'consumables';
    case House = 'house';
    case Equipment = 'equipment';
}
