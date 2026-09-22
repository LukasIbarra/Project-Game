<?php

namespace App\Enums;

// Fase 20: vocabulario FIJO y tipado de qué puede significar una entrada
// de pet_species.modifiers_json/level_modifiers_json -ver
// docs/PETS_EXPEDITIONS_SYSTEM.md §5.1 y App\Services\PetModifierResolver.
// El JSON guarda DATOS (qué tipo + a qué escala/objetivo + qué valor),
// nunca la lógica de qué hacer con ellos -eso vive en el resolver y,
// eventualmente, en quien consuma sus resultados (F21)-. Agregar un tipo
// nuevo es un caso nuevo acá, nunca una migración de columnas.
enum PetModifierType: string
{
    // +X% de loot en una expedición/scope determinado.
    case LootBonusPct = 'loot_bonus_pct';

    // +X% de probabilidad de que el loot obtenido sea de rareza alta.
    case RareLootChancePct = 'rare_loot_chance_pct';

    // -X% de daño recibido en eventos de un tipo determinado (ej. "enemy").
    case DamageReductionPct = 'damage_reduction_pct';

    // +X% a la cantidad de materiales obtenidos (no a la probabilidad de
    // obtenerlos, sino a cuántos por tirada).
    case MaterialQuantityBonusPct = 'material_quantity_bonus_pct';
}
