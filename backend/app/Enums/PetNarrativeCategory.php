<?php

namespace App\Enums;

// F7.1: puramente organizativo (filtros/estadísticas futuras) — no
// afecta selección ni gameplay. "Rare" es una categoría temática (algo
// que se SIENTE raro/misterioso), independiente de PetNarrativeRarity
// (que es la probabilidad estadística de aparecer).
enum PetNarrativeCategory: string
{
    case Environment = 'environment';
    case Thought = 'thought';
    case Funny = 'funny';
    case Animal = 'animal';
    case OtherPlayer = 'other_player';
    case Memory = 'memory';
    case Strange = 'strange';
    case Dark = 'dark';
    case Rare = 'rare';
}
