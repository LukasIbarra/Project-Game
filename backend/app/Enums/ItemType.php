<?php

namespace App\Enums;

// Pre-flight Fases 6-10: enum de PHP, no enum de MySQL, a propósito.
// Un enum de columna en MySQL exige un ALTER TABLE (bloqueante) para
// agregar un valor nuevo; un enum de PHP + columna string da la misma
// seguridad de tipos en el código sin ese costo cuando Fase 8/9 necesiten
// agregar tipos de item que hoy no imaginamos.
enum ItemType: string
{
    case Equipment = 'equipment';
    case Cosmetic = 'cosmetic';
    case Resource = 'resource';
    case Room = 'room';
    case Consumable = 'consumable';
    case Misc = 'misc';
}
