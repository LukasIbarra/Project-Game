<?php

namespace App\Enums;

// F21 (docs/PETS_EXPEDITIONS_SYSTEM.md §5.7): tipo discriminado de
// ExpeditionEventDefinition, mismo patrón que ItemType+metadata_json.
// Solo `Narrative` tiene resolución implementada en F21 -chest/enemy/help
// (con config_json/mecánica real) son F22, se agregan acá cuando esa fase
// los implemente, sin ALTER TABLE (columna string, enum de PHP).
enum ExpeditionEventType: string
{
    case Narrative = 'narrative';
}
