<?php

namespace App\Enums;

// F21/F22 (docs/PETS_EXPEDITIONS_SYSTEM.md §5.7): tipo discriminado de
// ExpeditionEventDefinition, mismo patrón que ItemType+metadata_json.
// F22 implementa la resolución real de chest/enemy/help -cada uno
// interpreta su propio config_json (ver ExpeditionService), nunca la key
// del destino.
enum ExpeditionEventType: string
{
    case Narrative = 'narrative';
    case Chest = 'chest';
    case Enemy = 'enemy';
    case Help = 'help';
}
