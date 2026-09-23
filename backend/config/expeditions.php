<?php

// F22 (docs/PETS_EXPEDITIONS_SYSTEM.md, eventos interactivos): parámetros
// de balance simples que no ameritan una migración ni una tabla -mismo
// criterio que config/pet_events.php (F7) para el sistema mecánico
// anterior, ahora con un solo valor global en vez de un array de eventos
// hardcodeados por destino.
//
// Deliberadamente UN SOLO valor global, no una tabla por dificultad/
// destino -F22 no implementa balance diferenciado, eso es F23
// (docs/PETS_EXPEDITIONS_SYSTEM.md §10, fórmula de presupuesto). Ajustar
// este número no requiere tocar ExpeditionService.
return [
    // Probabilidad (0-100) de que un checkpoint que NO sea el primero de
    // la expedición se planifique como kind=event en vez de narrative
    // -ver ExpeditionService::planCheckpoints(). Solo se aplica si la
    // expedición tiene al menos un evento mecánico (chest/enemy/help)
    // disponible; si no, siempre cae a narrative (degradación segura).
    'checkpoint_event_chance_pct' => 30,
];
