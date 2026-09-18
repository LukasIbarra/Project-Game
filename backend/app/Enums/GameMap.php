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
}
