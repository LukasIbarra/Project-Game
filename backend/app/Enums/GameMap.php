<?php

namespace App\Enums;

// Fase 18: única fuente de verdad para los identificadores válidos de
// current_map -uno por cada página autenticada real que usa AppShell (ver
// web/src/pages/*.astro). login/register/index quedan afuera a propósito:
// son pre-auth, nunca mandan heartbeat. Centralizado acá para que nunca
// puedan colarse variantes inconsistentes como "/home" vs "home" (ver
// SendPresenceHeartbeatRequest::prepareForValidation, que normaliza antes
// de validar contra este enum).
enum GameMap: string
{
    case Home = 'home';
    case Arena = 'arena';
    case Pet = 'pet';
    case House = 'house';
    case Play = 'play';
    case Crafting = 'crafting';
    case Inventory = 'inventory';
    case Ranking = 'ranking';
    case Shop = 'shop';
    case Social = 'social';

    // Fase 19.2: mismo trim/lowercase que ya usaba
    // SendPresenceHeartbeatRequest::prepareForValidation (sin tocar ese
    // archivo -F18 queda intacto-), extraído acá para que GET /presence?map=
    // lo reutilice sin reimplementar el criterio de normalización. Así
    // "/Arena/", "Arena" y "arena" siguen siendo el mismo valor en
    // cualquier punto de entrada que lo use.
    public static function normalize(string $raw): string
    {
        return strtolower(trim($raw, "/ \t\n\r\0\x0B"));
    }
}
