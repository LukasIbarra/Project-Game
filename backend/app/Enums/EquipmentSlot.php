<?php

namespace App\Enums;

// Mismas 6 categorías equipables que ya usa el CharacterRenderer del
// frontend (web/src/game/entities/CharacterRenderer.ts, LAYER_ORDER) —
// "body" queda afuera a propósito: es la base del personaje, no un slot
// equipable en el MVP.
//
// Fase 10: se agrega `Shield`. `reinforced_wooden_shield` (F8) se había
// sembrado deliberadamente con `subtype: null` porque "ningún
// EquipmentSlot existente encaja bien con escudo" -ahora que el combate
// necesita que el equipamiento module estadísticas de verdad, forzar un
// mapeo falso a un slot existente sería peor que agregar el slot real que
// faltaba. No tiene contraparte visual en CharacterRenderer todavía (no
// hay sprite de escudo), igual que hair/shirt/etc. ya conviven sin sprite
// propio desde F6 -se omite en el render hasta que exista el asset.
enum EquipmentSlot: string
{
    case Shirt = 'shirt';
    case Pants = 'pants';
    case Shoes = 'shoes';
    case Hair = 'hair';
    case Weapon = 'weapon';
    case Accessory = 'accessory';
    case Shield = 'shield';
}
