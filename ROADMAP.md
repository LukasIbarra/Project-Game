# ROADMAP — De Demo Jugable a Producción v1

Fuente de planificación del proyecto. `CLAUDE.md` sigue siendo el documento de
reglas/arquitectura/convenciones permanentes; este archivo es exclusivamente
seguimiento de fases (qué falta, qué se completó, qué deuda quedó).

Metodología: **FASE → ANÁLISIS → IMPLEMENTACIÓN → TEST → REVISIÓN → CIERRE →
SIGUIENTE FASE**. No se avanza de fase sin confirmación explícita del dueño
del proyecto.

Arquitectura fija (no se reabre sin razón técnica real): Astro + Tailwind +
Phaser 3 + TypeScript + `ApiClient.ts` como única entrada de red en el
frontend; Laravel + Postgres en el backend. Decisiones transversales ya
fijadas para todo el roadmap:

- **Sin WebSockets obligatorios en el Demo.** Polling (mismo patrón que el
  chat global: intervalo fijo + cursor `after_id`/timestamps).
- **Resolución perezosa, no schedulers/colas.** El servidor decide/calcula
  cuando alguien pregunta, nunca con un job de fondo.
- **Backend como única autoridad.** El cliente nunca calcula resultados, solo
  los solicita y los muestra.
- **Sin librería de estado nueva.** Sincronización de UI vía `CustomEvent`s
  en `window` (patrón ya usado antes de este roadmap: `coins:changed`).
- **Riesgo abierto conocido:** la conexión *pooled* de Neon (`DB_URL`) ya
  causó errores reales `SQLSTATE[25P02]` en flujos con lectura-decisión-
  escritura bajo concurrencia (`PetExpeditionService::resolveIfDue()`,
  `CombatService::attack()`). Cualquier fase nueva con ese mismo patrón
  (Fase 16 Tienda, Fase 20 motor de expediciones temporal) hereda ese riesgo
  y debe tratarlo como deuda activa, no como asumido-resuelto.

---

## Estado de fases

| Fase | Nombre | Estado |
|---|---|---|
| 1–10 | (fases previas: auth, personaje, mundo, casa, inventario/equipo, mascota+expediciones AFK, crafting/economía, combate PvP/Arena) | ✅ Completadas |
| **11** | **Player State real** | ✅ **Completada** |
| **12** | **Activity Feed** | ✅ **Completada** |
| 13 | Ranking real | ⬜ Pendiente |
| 14 | Notificaciones toast | ⬜ Pendiente |
| 15 | Navegación real desde el Mundo | ⬜ Pendiente |
| 16 | Tienda | ⬜ Pendiente |
| 17 | Historial de Combates + Ataques Recibidos | ⬜ Pendiente |
| 18 | Presencia (jugadores conectados) | ⬜ Pendiente |
| 19 | Otros jugadores visibles en el Mundo | ⬜ Pendiente |
| 20 | Motor de expediciones realmente temporal | ⬜ Pendiente |
| 21 | Eventos interactivos de expedición con decisión | ⬜ Pendiente |
| 22 | Mundo vivo: eventos ambientales básicos | ⬜ Pendiente |
| 23 | Sonidos | ⬜ Pendiente |
| 24 | Pulido de Demo / QA end-to-end | ⬜ Pendiente |
| 25–38 | Roadmap Producción (ver abajo) | ⬜ Pendiente |

---

## Nota de contradicción con CLAUDE.md (heredada, sigue vigente)

`CLAUDE.md` puede listar checklists de fases previas como pendientes aunque el
código real ya las tenga completas (ej. Combate PvP/Arena). Ante cualquier
contradicción entre `CLAUDE.md` y el código real, el código real es la fuente
de verdad — este roadmap parte de esa base.

---

## ROADMAP DEMO

### FASE 11 — Player State real ✅ COMPLETADA

**Objetivo:** fuente única de verdad de nivel/XP/monedas para HUD e Inicio.
**Qué resuelve:** HUD/Inicio mostraban `MOCK_PLAYER_PROGRESS` hardcodeado.
**Dependencias:** ninguna.
**Criterios de aceptación:** subir de nivel/ganar XP/monedas en cualquier
sistema actualiza el HUD sin recargar; cero `MOCK_*` de nivel/XP/coins en el
código.
**Qué NO tocar:** "recursos" del HUD (no existe un sistema de recursos
genérico distinto de Inventario).

**Implementado:** ver sección "Registro de cierre de fases" al final de este
documento.

---

### FASE 12 — Activity Feed ✅ COMPLETADA

**Objetivo:** feed genérico y real de actividad reciente, diseñado para
crecer sin tocar el esquema.
**Qué resuelve:** array hardcodeado `MOCK_RECENT_ACTIVITY` en `home.astro`.
**DB:** tabla nueva `activity_events` (`id`, `character_id` FK cascadeOnDelete,
`type` string, `payload` json, `created_at`; índice `[character_id,
created_at]`; sin `updated_at`, append-only).
**API:** `GET /v1/activity` (nuevo, `?after_id=`, tope 20-30).
**Backend:** helper `ActivityLogger::log($character, $type, $payload)`;
instrumentar en esta fase solo 4 tipos base: level-up, resultado de combate
propio, expedición reclamada, venta/crafting. El resto de los tipos (ataque
recibido, evento de expedición, compra en tienda, evento de mundo) se agregan
en sus fases correspondientes (17, 21, 16, 22) sin tocar el esquema.
**Frontend:** `home.astro` con fetch real + mapa `type → render` en cliente,
timestamps relativos, altura fija + scroll interno.
**Dependencias:** Fase 11.
**Qué NO hacer todavía:** no instrumentar todos los tipos posibles ahora;
no hacer notificación en tiempo real de esto (es Fase 14).

---

### FASE 13 — Ranking real

**Objetivo:** conectar `/ranking` a `ArenaRankingService` ya existente.
**Backend:** ninguno nuevo, o `GET /v1/ranking` reusando el mismo service.
**Frontend:** `ranking.astro` deja de ser `PlaceholderView`.
**Dependencias:** ninguna.
**Qué NO hacer todavía:** temporadas, filtros, paginación.

---

### FASE 14 — Notificaciones toast

**Objetivo:** toasts no invasivos para eventos importantes (+XP, level up,
mascota regresó, logro, etc.).
**Frontend:** `ToastHost.astro` en `AppShell.astro`. Dispatcher:
`window.dispatchEvent(new CustomEvent("notify", {detail:{type,message}}))`.
**Dependencias:** Fase 12 (mapa `type → mensaje` reusado).
**Qué NO hacer todavía:** no es un centro de notificaciones persistente (eso
es la Fase 12).

---

### FASE 15 — Navegación real desde el Mundo + formalización mínima de Tiled

**Objetivo:** que tocar Dojo/Casa/Arena/Mascota en `/play` navegue de verdad,
leyendo una propiedad formal de Tiled (`type`/`class` + `targetPage`) en vez
de matching por nombre de objeto en TypeScript.
**Dependencias:** ninguna.
**Qué NO hacer todavía:** transiciones animadas, confirmación antes de salir.

---

### FASE 16 — Tienda

**Objetivo:** vidriera compartida con rotación horaria real, cupo de compra
por jugador.
**Decisión de arquitectura (evaluada explícitamente, no default):** catálogo
y rotación **compartidos** (todos ven la misma vidriera/countdown) + cupo de
compra en **fila propia por jugador** — evita la contención de stock global
compartido bajo escritura concurrente, mismo tipo de riesgo que ya causó
SQLSTATE 25P02 en Neon. Stock verdaderamente global queda para Producción
(Fase 29), condicionado a resolver esa deuda antes.
**DB:** `shop_rotations` (`id`, `started_at`, `ends_at`, `items_json`:
catálogo fijo de la rotación `[{item_key, price, stock_limit}]`);
`shop_purchases` (`id`, `shop_rotation_id` FK cascadeOnDelete, `character_id`
FK cascadeOnDelete, `item_key`, `quantity`, `created_at`; índice
`[shop_rotation_id, character_id, item_key]`, no único).
**API:** `GET /v1/shop` (resolución perezosa: genera la rotación si no hay
una vigente), `POST /v1/shop/purchase` (valida cupo server-side, descuenta
vía `EconomyService`, otorga vía `InventoryGrantService`).
**Frontend:** nueva página `shop.astro` + entrada en `NavBar.astro`.
**Dependencias:** Fase 12 (log de actividad), Fase 14 (toast de compra).
**Qué NO hacer todavía:** stock global, consumibles/efectos especiales.

---

### FASE 17 — Historial de Combates + Ataques Recibidos

**Objetivo:** panel de Historial junto al Ranking en Arena; visibilizar
ataques recibidos.
**Backend:** `combat_logs` ya tiene todo lo necesario (verificado, cero tabla
nueva). Nuevo `GET /v1/arena/combats` (lista mía como atacante o defensor,
paginado). Instrumentar `CombatService::attack()` para loguear actividad
tanto al atacante como al defensor (`combat_attacked`/`combat_defended`) —
así el defensor se entera vía el Activity Feed ya existente, sin construir un
sistema de inbox nuevo.
**Frontend:** `arena.astro`: Ranking se angosta, panel Historial al lado.
**Dependencias:** Fase 12, Fase 14.
**Qué NO hacer todavía:** aviso instantáneo en tiempo real (se entera en el
próximo poll).

---

### FASE 18 — Presencia (jugadores conectados)

**Objetivo:** panel "Jugadores en línea" arriba del chat.
**DB:** `player_presence` (`id`, `character_id` FK unique cascadeOnDelete,
`last_seen_at`, `current_map` nullable, `status`, `updated_at`).
**API:** `POST /v1/presence/heartbeat`, `GET /v1/presence`.
**Dependencias:** Fase 11.
**Qué NO hacer todavía:** posición/dirección (Fase 19).

---

### FASE 19 — Otros jugadores visibles en el Mundo

**Objetivo:** ver a otros personajes moviéndose en `/play`, sin llegar a un
MMO en tiempo real.
**Backend:** extender `player_presence` con `position_x/position_y/direction`
(mismo endpoint de heartbeat, no uno nuevo).
**Frontend/Phaser:** `WorldScene` pollea presencia (~2-3s), interpola
posiciones, spawn points múltiples reales en Tiled.
**Dependencias:** Fase 18, Fase 15.
**Qué NO hacer todavía:** interacción entre jugadores, colisión entre
jugadores, verlos en la Habitación.

---

### FASE 20 — Motor de expediciones realmente temporal

**Objetivo:** que el resultado de cada checkpoint de una expedición se genere
server-side EN EL MOMENTO en que corresponde — nunca al iniciar la
expedición. Reemplaza el diseño anterior de `PetExpeditionService::start()`
que precalculaba daño/loot/narrativa completos desde el minuto 0.
**Regla fundamental:** separar PLANIFICACIÓN TEMPORAL (duración, cantidad de
checkpoints, `scheduled_at`, `kind`) de RESOLUCIÓN DEL EVENTO (texto/daño/
loot concretos, decididos solo cuando el checkpoint vence) de APLICACIÓN DE
CONSECUENCIAS (inmediata al resolver) y REGISTRO HISTÓRICO (bitácora).
**DB:** `pet_expedition_checkpoints` (`id`, `pet_expedition_id` FK
cascadeOnDelete, `scheduled_at`, `kind` [narrative|mechanical], `status`
[pending|resolved], `resolved_at` nullable, `payload` nullable hasta
resolverse); índices `[pet_expedition_id, scheduled_at]` y
`[pet_expedition_id, status]`.
**Backend:** `resolveIfDue()` (existente) se extiende para resolver TODOS los
checkpoints vencidos en orden cronológico en una sola llamada (soporta
catch-up offline), tirando el resultado recién ahí y aplicándolo de
inmediato (`pet.health`, `InventoryGrantService`, posible corrimiento de
`ends_at` si hay retraso).
**API:** `GET /v1/pet/expedition` (existente, extendido: `remaining_seconds`,
`log` de checkpoints resueltos).
**Riesgo:** mismo patrón lectura-decide-escribe-bajo-concurrencia que ya
causó SQLSTATE 25P02 — recomendado resolver la deuda de `DB_URL` pooled de
Neon antes o junto con esta fase.
**Dependencias:** ninguna estricta, pero comparte tabla con la Fase 21.
**Qué NO hacer todavía:** decisiones del jugador (Fase 21).

---

### FASE 21 — Eventos interactivos de expedición con decisión del jugador

**Objetivo:** checkpoints tipo `decision` ([ENFRENTAR]/[HUIR]) resueltos por
el jugador, con timeout+default si no está online.
**Backend:** `pet_expedition_checkpoints` gana `kind = "decision"` +
`decision_options` (json, incluye default) + `decision_deadline` (nullable).
**API:** `POST /v1/pet/expedition/checkpoints/{id}/resolve`.
**Dependencias:** Fase 20 (obligatoria), Fase 12/14.
**Qué NO hacer todavía:** más de una decisión por expedición, branching.

---

### FASE 22 — Mundo vivo: eventos ambientales básicos

**Objetivo:** apariciones ocasionales y significativas en el Mundo (ej.
"lluvia de meteoritos"), no "click cada 30s".
**DB:** `world_events` (`id`, `type`, `payload`, `starts_at`, `ends_at`,
`claimed_by_character_id` nullable).
**API:** `GET /v1/world/events`, `POST /v1/world/events/{id}/claim`.
**Dependencias:** Fase 19.
**Qué NO hacer todavía:** múltiples tipos simultáneos, eventos por zona,
comerciante ambulante con inventario propio.

---

### FASE 23 — Sonidos

**Objetivo:** capa mínima de efectos de sonido (chat, evento interactivo de
mascota, evento de mundo, combate, level up, compra, crafting, recompensa
importante), sin música.
**Frontend:** `web/src/game/audio/GameAudio.ts` (nuevo, sin librería
externa). Se suscribe al mismo `CustomEvent("notify")` de la Fase 14 — nunca
dispara sonido en cada poll, solo ante eventos nuevos reales. UI (chat,
toast, compra, crafting) vía `<audio>` simple; mundo/combate vía
`this.sound` de Phaser. Toggle 🔊/🔇 en HUD, preferencia en `localStorage`.
**Dependencias:** Fase 14; en la práctica se implementa último porque
necesita que 16/17/21/22 ya disparen el evento `notify`.
**Qué NO hacer todavía:** música ambiente, mezclador de volumen por
categoría.

---

### FASE 24 — Pulido de Demo / QA end-to-end

**Objetivo:** cerrar el Demo como unidad coherente. Sin features nuevas: pase
de estados vacíos, responsive, `tsc --noEmit` + `php artisan test` limpios,
Playwright end-to-end del recorrido completo.

---

## ROADMAP PRODUCCIÓN

- **Fase 25** — Auditoría de seguridad y abuso (throttle en heartbeat/shop/
  expedition-resolve; revisar doble-resolución de checkpoints por polls
  concurrentes).
- **Fase 26** — Interacciones sociales en el Mundo (inspeccionar/desafiar).
- **Fase 27** — Amigos y Social completo (tabla `friendships`).
- **Fase 28** — Logros y títulos (`achievements` + `character_achievements`,
  reactivos sobre `activity_events`).
- **Fase 29** — Tienda con stock verdaderamente global (condicionado a
  resolver antes la deuda de `DB_URL` pooled de Neon o locking explícito).
- **Fase 30** — Expediciones avanzadas: branching y checkpoints encadenados.
- **Fase 31** — Contenido: más mascotas, destinos, mapas.
- **Fase 32** — Crafting avanzado / progresión de profesión.
- **Fase 33** — Habitación avanzada: visitas de otros jugadores (solo vista).
- **Fase 34** — Recompensas moderadas por tiempo online (sin pay-to-win, sin
  obligar a dejar el navegador abierto).
- **Fase 35** — Balance de economía (incluye precios/stock de Tienda).
- **Fase 36** — Rendimiento, observabilidad, deploy robusto.
- **Fase 37** — Formalización completa de Tiled (spawn points, zonas,
  object layers 100% declarados, cero matching por nombre en código).
- **Fase 38** — Audio ambiente y música (opcional, baja prioridad).

---

## Diagrama de dependencias

```
Player State (11)
      │
      ├──→ Activity (12) ──→ Notifications (14) ──→ Audio (23)
      │        │                    │
      │        │                    ├──→ Shop notif (16)
      │        │                    ├──→ Combat notif (17)
      │        │                    ├──→ Expedition notif (21)
      │        │                    └──→ World event notif (22)
      │        └── nuevos `type`s agregados por 16/17/21/22
      └──→ Ranking (13)   [independiente]

Presence (18) ──→ Other Players (19) ──→ World Events (22) ──→ Social (26)

Pet Expeditions (existente)
      └──→ Real-time Event Resolution (20) ──→ Expedition Log (20)
                 └──→ Interactive Decisions (21) ──→ Advanced Expedition System (30)

Economy (existente) ──→ Shop (16) ──→ Crafting (existente) ──→ Economy Balance (35)
                            └──→ Shop stock global (29) [requiere deuda Neon resuelta]

Combat (existente/Fase 10) ──→ Combat History (17) ──→ Attack Notifications (17) ──→ Social Combat (26)
```

---

## Definition of Done

**Demo:** ver checklist completo acordado (estado dinámico, actividad,
ranking, historial Arena, ataques recibidos, presencia, otros jugadores,
expediciones temporales, eventos de expedición, bitácora, tienda, crafting,
mundo vivo, sonidos principales, notificaciones, QA) — se transcribe en
detalle al cerrar la Fase 24.

**Producción:** ver checklist acordado (auditoría, interacciones sociales,
amigos, logros, stock global, expediciones avanzadas, contenido, visitas,
recompensas online, balance, observabilidad, Tiled formal, CI) — se
transcribe en detalle al cerrar la Fase 38.

---

## Registro de cierre de fases

### Fase 11 — Player State real — ✅ Completada

**Implementado:**
- `GET /v1/character` ahora incluye `exp_to_next_level` (reusa
  `CombatStatsService::xpToNextLevel()`, sin duplicar la fórmula).
- Nuevo módulo `web/src/game/state/playerState.ts`: `refreshPlayerState()`
  hace fetch a `getCharacter()`, cachea, y dispara
  `CustomEvent("player:changed", {detail: character})` en `window`.
- `Hud.astro` y `home.astro` migrados a datos 100% reales de nivel/XP/
  monedas vía este módulo; se eliminó `MOCK_PLAYER_PROGRESS` y el archivo
  `mockPlayerProgress.ts`.
- El evento legado `coins:changed` (disparado solo por `inventory.astro` al
  vender) se unificó en `player:changed` — un solo evento para todo cambio
  de estado del jugador, sin duplicar el mecanismo de sincronización.
- `arena.astro` dispara `refreshPlayerState()` al terminar un combate, para
  que el HUD refleje level-up/monedas ganadas sin recargar la página.
- El contador "Recursos (demo)" se eliminó de HUD e Inicio (no existe un
  sistema de recursos genérico distinto de Inventario; dejarlo con datos
  mock junto a datos reales era inconsistente con el DoD de la fase).

**Deuda/observaciones detectadas, no resueltas en esta fase:**
- "Recursos" del HUD queda sin reemplazo real. Si se quiere volver a mostrar
  algo ahí, debería ser un conteo real derivado de Inventario, a decidir en
  una fase futura (no bloquea nada del roadmap actual).
- Hud.astro y home.astro ambos llaman `refreshPlayerState()` de forma
  independiente al cargar `/home` (cada uno hace su propio fetch a
  `/character`), sin deduplicar. Es inofensivo (mismo patrón que ya usan
  otras páginas) pero es una duplicación menor de red que podría
  eliminarse con un cache con TTL si se vuelve un problema real.
- La deuda de `DB_URL` pooled de Neon (SQLSTATE 25P02) sigue abierta y sin
  tocar — no era parte del alcance de esta fase, se mantiene documentada
  arriba para las Fases 16/20.

### Fase 12 — Activity Feed — ✅ Completada

**Implementado:**
- Migración `activity_events` (`character_id` FK cascadeOnDelete, `type`
  string, `payload` json, sin `updated_at` — append-only), modelo
  `ActivityEvent`, servicio `ActivityLogger::log()` (único punto de
  escritura, inyectado donde hace falta en vez de crear el modelo a mano en
  cada servicio).
- 5 tipos instrumentados exactamente donde ya ocurre la acción real, dentro
  de la transacción existente de cada una: `level_up`
  (`CombatStatsService::addExperience`, para CUALQUIER personaje que suba
  de nivel — decisión confirmada con el usuario, ver justificación en el
  código), `combat` (`CombatService::attack`, **solo para el atacante** — el
  defensor queda deliberadamente sin instrumentar, es Fase 17), `sale`
  (`EconomyService::sell`), `crafting` (`CraftingService::craft`),
  `expedition_claimed` (`PetExpeditionService::claim`).
- `GET /v1/activity` (nuevo), mismo patrón exacto que `ChatController`
  (`after_id`, tope 25, orden cronológico, siempre filtrado por el
  personaje del usuario autenticado).
- Frontend: `home.astro` reemplaza `MOCK_RECENT_ACTIVITY` por un feed real
  (polling cada 10s con `after_id`, dedup, altura fija `max-h-48` +
  scroll interno, timestamps relativos, mapa `type → mensaje` en cliente,
  estado vacío). `ApiClient.ts` gana `ActivityEventDto`/`getActivityEvents`.
- La traducción `type → texto` vive 100% en el cliente; el backend nunca
  arma mensajes de UI — agregar un tipo nuevo (Fase 16/17/21/22) es sumar
  un `case` en `describeActivity()`, sin tocar el backend.

**Deuda/observaciones detectadas, no resueltas en esta fase:**
- El índice de `activity_events` quedó sobre `[character_id, id]` (no
  `[character_id, created_at]` como decía el borrador original de este
  documento) porque el cursor real de paginación es `id`, igual que
  `chat_messages` — ajuste menor, ya reflejado arriba.
- Un `bootstrap/cache/routes-v7.php` viejo (de antes de esta fase) hizo que
  la ruta nueva no apareciera hasta correr `php artisan route:clear` — no
  es un problema del código, pero si un futuro deploy usa rutas cacheadas
  hay que asegurarse de regenerar el caché en cada release, no solo en
  migraciones.
- No se instrumentaron más tipos que los 5 pedidos (ataque recibido, evento
  de expedición, compra en tienda, evento de mundo quedan para sus fases).
