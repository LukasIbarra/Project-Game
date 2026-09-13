<?php

// Fase 7: pool de eventos aleatorios por destino. Configuración estática
// en código -no una tabla-: no hay ninguna UI de administración que los
// edite en runtime, y "extensible" acá solo significa "agregar una fila
// a este array", igual de simple que una tabla pero sin una migración de
// más para algo que ningún jugador ni admin va a tocar desde afuera.
//
// `type`:
//   - "positive": suma un item extra de loot (`loot_bonus_items`) y/o
//     bonus de cantidad (`loot_bonus_quantity`) sobre el loot base.
//   - "negative": resta vida (`health_delta`, negativo).
//   - "delay": suma minutos a la duración final (`delay_minutes`).
//
// Ver App\Services\PetExpeditionService::rollEvents.
return [
    'forest' => [
        ['key' => 'small_spring', 'label' => 'Tu mascota encontró una pequeña fuente.', 'type' => 'positive', 'loot_bonus_quantity' => 2],
        ['key' => 'medicinal_herbs', 'label' => 'Tu mascota encontró hierbas medicinales.', 'type' => 'positive', 'loot_bonus_items' => 1],
        ['key' => 'attacked', 'label' => 'Tu mascota fue atacada durante la exploración.', 'type' => 'negative', 'health_delta' => -10],
        ['key' => 'trapped', 'label' => 'Tu mascota quedó atrapada entre unas raíces.', 'type' => 'delay', 'delay_minutes' => 30],
        ['key' => 'ancient_tree', 'label' => 'Tu mascota encontró un árbol antiguo.', 'type' => 'positive', 'loot_bonus_quantity' => 3],
        ['key' => 'secret_zone', 'label' => 'Tu mascota encontró una zona secreta.', 'type' => 'positive', 'loot_bonus_items' => 1, 'loot_bonus_quantity' => 2],
    ],

    'mountains' => [
        ['key' => 'mineral_vein', 'label' => 'Tu mascota encontró una veta mineral.', 'type' => 'positive', 'loot_bonus_quantity' => 3],
        ['key' => 'blocked_path', 'label' => 'Una roca bloquea el camino.', 'type' => 'delay', 'delay_minutes' => 45],
        ['key' => 'found_cave', 'label' => 'Tu mascota encontró una cueva.', 'type' => 'positive', 'loot_bonus_items' => 1],
        ['key' => 'attacked', 'label' => 'Tu mascota fue atacada por una criatura.', 'type' => 'negative', 'health_delta' => -15],
        ['key' => 'strange_crystal', 'label' => 'Tu mascota encontró un cristal extraño.', 'type' => 'positive', 'loot_bonus_items' => 1, 'loot_bonus_quantity' => 2],
    ],

    'blood_castle' => [
        ['key' => 'secret_room', 'label' => 'Tu mascota encontró una sala secreta.', 'type' => 'positive', 'loot_bonus_items' => 1],
        ['key' => 'ancient_chest', 'label' => 'Tu mascota encontró un cofre antiguo.', 'type' => 'positive', 'loot_bonus_quantity' => 4],
        ['key' => 'ambushed', 'label' => 'Tu mascota fue emboscada.', 'type' => 'negative', 'health_delta' => -25],
        ['key' => 'found_altar', 'label' => 'Tu mascota encontró un altar.', 'type' => 'delay', 'delay_minutes' => 60],
        ['key' => 'found_relic', 'label' => 'Tu mascota encontró una reliquia.', 'type' => 'positive', 'loot_bonus_items' => 1, 'loot_bonus_quantity' => 3],
    ],
];
