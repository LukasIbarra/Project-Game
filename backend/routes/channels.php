<?php

use Illuminate\Support\Facades\Broadcast;

// Chat global: cualquier usuario autenticado por Sanctum puede escuchar -no
// hay lógica de pertenencia (no es un chat privado 1-a-1), es el mismo chat
// global que ya expone GET /chat/messages sin filtrar por nadie.
Broadcast::channel('chat', fn ($user) => true);
