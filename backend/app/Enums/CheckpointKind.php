<?php

namespace App\Enums;

// F21 (docs/PETS_EXPEDITIONS_SYSTEM.md §11): deliberadamente solo 2
// valores -quién decide si un checkpoint necesita interacción del jugador
// es ExpeditionEventDefinition::type/config_json, nunca el checkpoint en
// sí. Esto permite que F22 agregue tipos de evento nuevos (chest/enemy/
// help) sin tocar pet_expedition_checkpoints.
enum CheckpointKind: string
{
    case Narrative = 'narrative'; // siempre auto-resuelve, sin decisión
    case Event = 'event';         // respaldado por expedition_event_definitions
}
