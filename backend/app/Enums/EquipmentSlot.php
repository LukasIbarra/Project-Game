<?php

namespace App\Enums;

// Mismas 6 categorías equipables que ya usa el CharacterRenderer del
// frontend (web/src/game/entities/CharacterRenderer.ts, LAYER_ORDER) —
// "body" queda afuera a propósito: es la base del personaje, no un slot
// equipable en el MVP.
enum EquipmentSlot: string
{
    case Shirt = 'shirt';
    case Pants = 'pants';
    case Shoes = 'shoes';
    case Hair = 'hair';
    case Weapon = 'weapon';
    case Accessory = 'accessory';
}
