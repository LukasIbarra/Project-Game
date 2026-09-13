<?php

namespace App\Enums;

// Fase 7. "injured" existe en el vocabulario pero F7 no lo usa para
// bloquear nada todavía (no hay sistema de curación) -entra/sale solo
// entre idle y exploring-. Se deja declarado para no tener que migrar la
// columna cuando una fase futura le dé un efecto real.
enum PetStatus: string
{
    case Idle = 'idle';
    case Exploring = 'exploring';
    case Injured = 'injured';
}
