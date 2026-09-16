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
| **13** | **Ranking real** | ✅ **Completada** |
| **14** | **Notificaciones toast** | ✅ **Completada** |
| **15** | **Navegación real desde el Mundo** | ✅ **Completada** |
| **16** | **Tienda** | ✅ **Completada** |
| **17** | **Historial de Combates + Ataques Recibidos** | ✅ **Completada** |
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

### FASE 13 — Ranking real ✅ COMPLETADA

**Objetivo:** conectar `/ranking` a `ArenaRankingService` ya existente.
**Backend:** ninguno nuevo, o `GET /v1/ranking` reusando el mismo service.
**Frontend:** `ranking.astro` deja de ser `PlaceholderView`.
**Dependencias:** ninguna.
**Qué NO hacer todavía:** temporadas, filtros, paginación.

---

### FASE 14 — Notificaciones toast ✅ COMPLETADA

**Objetivo:** toasts no invasivos para eventos importantes (+XP, level up,
mascota regresó, logro, etc.).
**Frontend:** `ToastHost.astro` en `AppShell.astro`. Dispatcher:
`window.dispatchEvent(new CustomEvent("notify", {detail:{type,message}}))`.
**Dependencias:** Fase 12 (mapa `type → mensaje` reusado).
**Qué NO hacer todavía:** no es un centro de notificaciones persistente (eso
es la Fase 12).

---

### FASE 15 — Navegación real desde el Mundo + formalización mínima de Tiled ✅ COMPLETADA

**Objetivo:** que tocar Dojo/Casa/Arena/Mascota en `/play` navegue de verdad,
leyendo una propiedad formal de Tiled (`type`/`class` + `targetPage`) en vez
de matching por nombre de objeto en TypeScript.
**Dependencias:** ninguna.
**Qué NO hacer todavía:** transiciones animadas, confirmación antes de salir.

---

### FASE 16 — Tienda ✅ COMPLETADA (versión simplificada, ver registro de cierre)

**Objetivo original (borrador de esta hoja de ruta):** vidriera compartida
con rotación horaria real, cupo de compra por jugador.
**Decisión tomada al momento de implementar (re-briefing explícito del
dueño del proyecto, reemplaza el borrador de arriba):** primera versión
deliberadamente más simple — catálogo **fijo** (`shop_products`, sin
rotación ni stock), reutilizando `EconomyService`/`InventoryGrantService`/
`ActivityLogger` tal cual. Rotación horaria + cupo por jugador quedan
documentados como evolución futura (ver "Deuda" en el registro de cierre),
no se descartaron, solo se pospusieron.
**DB real:** `shop_products` (`id`, `item_id` FK única a `items`
restrictOnDelete, `price`, `is_active`, timestamps).
**API real:** `GET /v1/shop` (catálogo activo + coins del personaje),
`POST /v1/shop/purchase` (valida producto activo + fondos server-side,
descuenta vía `EconomyService::buy()` nuevo, otorga vía
`InventoryGrantService::grant()`, todo en una transacción atómica).
**Frontend:** nueva página `shop.astro` + entrada en `NavBar.astro`
(icono `shop_cart.png`, ya curado en la auditoría de iconos).
**Dependencias:** Fase 12 (log de actividad), Fase 14 (toast de compra) —
ambas reutilizadas tal cual, sin cambios.
**Qué NO se hizo todavía (a propósito):** stock global, rotación horaria,
consumibles con efecto real, descuentos/eventos, mercado entre jugadores.

---

### FASE 17 — Historial de Combates + Ataques Recibidos ✅ COMPLETADA

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

### Fase 13 — Ranking real — ✅ Completada

**Implementado:**
- Nuevo `RankingController::index()` (`GET /v1/ranking`), delgado: orquesta
  los mismos 3 métodos que `ArenaController` ya usaba
  (`ArenaRankingService::topRanking/statsFor/rankOf`), sin lógica de
  ranking nueva. Devuelve `{ranking: [...], me: {character_id, rank, wins,
  losses} | null}` — `me` es `null` solo si el usuario autenticado no
  tiene personaje (caso defensivo, no ocurre en el flujo normal de
  registro).
- `web/src/game/net/ApiClient.ts`: `RankingMeDto`/`RankingStateDto` +
  `getRanking()`, reusando `ArenaRankingEntryDto` ya existente (mismo
  shape que `ArenaStateDto.ranking`, cero DTO duplicado).
- `ranking.astro` reemplaza el `PlaceholderView` — mismo patrón visual
  exacto que la lista "Ranking" ya existente en `arena.astro` (misma
  clase de fila, mismo resaltado del propio personaje), con loading/
  error/empty state propios.
- Top 10 (límite ya existente en el servicio, sin paginación/filtros
  nuevos, tal como pedía la fase). Sin datos mock: verificado en vivo con
  Playwright que el ranking real de la DB de desarrollo se renderiza tal
  cual (incluyendo cuentas de prueba de fases anteriores, cero jugadores
  falsos inventados).

**Deuda/observaciones detectadas, no resueltas en esta fase (fuera de
alcance de Fase 13):**
- `ChatTest.php` tiene 2 tests preexistentes que fallan contra la DB de
  desarrollo compartida (`chat_messages` ya tiene filas reales de uso
  real, y esos tests asumían tabla vacía). No relacionado con Ranking —
  no se tocó chat en esta fase, solo se documenta.
- El dev server de Astro (`astro dev`) tiene latencia de compilación en
  frío la primera vez que se pide una página recién modificada — no es un
  bug de la app (el build de producción no tiene este problema), pero vale
  tenerlo presente al verificar manualmente cualquier fase futura recién
  implementada: esperar un poco más en el primer request de una página
  nueva antes de asumir que algo no funciona.

### Fase 14 — Notificaciones toast — ✅ Completada

**Implementado:**
- `web/src/game/state/toast.ts` (nuevo): store mínimo, mismo mecanismo que
  `playerState.ts` (Fase 11) — `CustomEvent("notify")` en `window`, sin
  librería de estado nueva. API ergonómica `toast.success/error/info/
  warning(message, durationMs?)` pedida por la fase, implementada como
  wrapper fino sobre el evento (nunca una segunda fuente de verdad).
- `web/src/components/ui/ToastHost.astro` (nuevo), montado una vez en
  `AppShell.astro`: escucha `notify`, renderiza cada toast como un
  `game-panel` con acento de color + ícono (nunca solo color), auto-dismiss
  (4s por defecto, configurable por toast), cierre manual, múltiples toasts
  coexistiendo sin pisarse, animación fade+translateY respetando
  `prefers-reduced-motion` (mismo criterio que la bitácora de expedición,
  F7.1.1).
- `Icon.astro` gana 4 íconos SVG nuevos (`toast-success/error/info/
  warning`) — ningún ícono existente representaba estos conceptos; se
  agregaron como línea SVG (mismo criterio que chat/bell/chevrons: UI pura,
  no pixel-art) en vez de reusar uno ajeno o depender solo del color.
- Integraciones reales (las únicas 3 acciones que eran 100% silenciosas en
  éxito, confirmado por auditoría antes de tocar nada): crafting exitoso
  (`crafting.astro`), venta exitosa (`inventory.astro`), reclamo de
  expedición exitoso (`pet.astro`) — las 3 ganan toast de éxito Y migran su
  toast de error puntual (antes iba al banner de la página). Los banners
  inline de "no se pudo CARGAR la página" (arena/inventory/crafting/pet/
  ranking) quedaron sin tocar a propósito — son un estado persistente de
  fallo de carga, no feedback transitorio de una acción ya ejecutada.
- Deliberadamente NO integrado: resultado de combate en Arena (ya tiene su
  propio panel de resultado dedicado, incluido level-up — un toast ahí
  sería ruido redundante); equipar/desequipar y colocar/mover/retirar
  muebles (ya tienen confirmación visual inmediata propia — cambio de
  label del botón, ghost de Phaser).
- Responsive: en mobile el host queda anclado arriba de la navbar inferior
  fija (mismo `pb-20`/offset que ya reserva `AppShell.astro` para ella,
  Mobile UX) y con z-index por encima del chat drawer -un toast nunca
  queda oculto detrás de ninguno de los dos, verificado con el drawer
  abierto-. En desktop se ancla al hueco entre NavBar y GlobalChat
  (estáticos, sin flotar), sin superponerse a ninguno.

**Deuda/observaciones detectadas, no resueltas en esta fase:**
- Este proyecto no tiene ningún framework de test frontend instalado
  (ni Vitest ni `@playwright/test`) — toda la verificación de Playwright de
  esta fase (y de todas las anteriores) fue ad-hoc vía script, nunca un
  archivo de test persistido. Instalar `@playwright/test` para tener una
  suite real de E2E queda como decisión pendiente del dueño del proyecto
  (no se instaló nada nuevo sin confirmar primero, tal como se pidió).
- `ChatTest.php` sigue con sus 2 fallos preexistentes ya documentados en el
  cierre de Fase 13 (datos reales acumulados en `chat_messages`) — no
  relacionado con esta fase, backend no se tocó en absoluto.

### Fase 15 — Navegación World → Pages — ✅ Completada

**Implementado:**
- `web/public/assets/world/mapa_base.json` (artefacto real que carga
  Phaser) y `mapa_base.tmx` (fuente de diseño, mantenida en sync) ganan
  metadata formal en los 4 objetos de entrada: `type` pasa de `"collision"`
  genérico a `"navigation"`, más `properties: [{name:"targetPage",
  value:"<clave>"}]`. `"rio"` (obstáculo, no es punto de entrada) queda
  con `type="collision"` sin cambios.
- `WorldMap.ts`: el `TiledObject` interno ahora tipa `properties`; nueva
  `TARGET_PAGES` (única lista blanca clave→ruta real) y
  `resolveTargetPage()`. La inclusión de un objeto en `structures[]` pasa
  a depender de `obj.type === "navigation"` (antes: de que `obj.name`
  matcheara `STRUCTURE_LABELS`). `STRUCTURE_LABELS` se conserva, pero
  ahora es puramente cosmético (texto del prompt "E — Entrar a X"), con
  fallback si el nombre cambia — nunca decide destino.
- `WorldScene.ts`: `handleInteractKey()` navega con
  `window.location.href = activeStructure.targetPage` cuando existe;
  mismo fallback "Próximamente." de antes si no hay `targetPage`
  reconocido (objeto sin la propiedad, o con un valor no mapeado).
- Verificado que Phaser pasa `type`/`properties` sin transformar (leído
  directo del código fuente de `ParseObject.js`, `commonObjectProps`) —
  no hay ninguna capa intermedia que pudiera renombrar/perder estos
  campos.

**Destinos reales usados (ninguno inventado):**
`casa → /house`, `hotel → /arena` (Arena), `pet → /pet` (Mascota),
`edificio → /ranking` (Dojo — sin página propia todavía, pedido explícito
del dueño del proyecto: funciona como acceso al Ranking, no se creó
`/dojo`).

**Validación:**
- Casa, Arena y Dojo: verificados con **gameplay real completo**
  (caminar con el jugador real hasta la estructura + presionar E +
  confirmar la URL final) vía Playwright — los 3 navegaron correctamente
  al primer intento.
- Mascota: no logré scriptear un camino de movimiento confiable hasta ese
  extremo del mapa dentro de un esfuerzo razonable (headless, sin mapa de
  colisiones exhaustivo a mano) — verificado en cambio leyendo el estado
  real resuelto en runtime (`map.structures`, con un `console.log`
  temporal agregado y retirado en el momento, nunca deja rastro en el
  código final): `{"id":"pet","targetPage":"/pet",...}` correcto, mismo
  código de interacción ya probado en los otros 3. Confianza alta pero
  no es una prueba de gameplay 100% real como las otras tres — ver reporte
  de la tarea para el detalle completo.
- Casos "sin targetPage" e "inválido" verificados por lectura de código
  (`resolveTargetPage` devuelve `null` en ambos casos, mismo fallback sin
  crash) — no hay ningún objeto real en el mapa hoy sin `targetPage` para
  probarlo en vivo.

**Deuda/observaciones detectadas, no resueltas en esta fase:**
- El Dojo sigue sin página propia (decisión explícita del dueño del
  proyecto para esta fase, no una deuda involuntaria) — cuando exista,
  el cambio es una sola línea en `TARGET_PAGES` (`edificio` pasaría a
  apuntar a una clave nueva).
- No existe ningún conversor `.tmx → .json` reutilizable en el repo (se
  usó uno puntual y descartado en Fase 5) — mantener ambos archivos en
  sync fue manual esta vez. Si el mapa se vuelve a editar en Tiled y se
  reexporta, hay que reaplicar a mano cualquier `properties` agregado acá
  si el reexport no las trae (dependiendo de si se edita el `.tmx` real en
  Tiled o se regenera desde cero).

### Fase 16 — Tienda — ✅ Completada (primera versión, simplificada)

**Implementado:**
- Migración `shop_products` (`item_id` FK única a `items`, `price`,
  `is_active`) — catálogo mantenible, sin rotación/stock (fuera de
  alcance de esta versión, ver Deuda). `ShopProduct` (modelo) +
  `ShopProductSeeder` (idempotente, `updateOrCreate`, registrado en
  `DatabaseSeeder`): 6 productos reales (`wood`, `stone`, `wild_herb`,
  `wood_plank`, `wooden_chair`, `small_potion`), precios con margen simple
  sobre `sell_value` existente.
- `EconomyService::buy()` (nuevo, junto a `sell()` ya existente —
  extensión coherente de la MISMA clase, no un sistema paralelo): valida
  producto activo + fondos, y dentro de una única transacción descuenta
  `Character.coins` (la única columna de monedas que existe), otorga el
  item vía `InventoryGrantService::grant()` (sin lógica de inventario
  duplicada) y loguea `activity_events` tipo `purchase` vía
  `ActivityLogger` (Fase 12, sin cambios).
- `ShopController::index/purchase` (nuevo) + `PurchaseItemRequest`
  (mismo patrón exacto que `SellItemRequest`/`CraftRequest`). Rutas
  `GET /v1/shop`, `POST /v1/shop/purchase`.
- Frontend: `ApiClient.ts` gana `ShopProductDto`/`ShopStateDto`/
  `PurchaseResultDto`/`getShop()`/`buyItem()`. Nueva página `shop.astro`
  (mismo patrón que `crafting.astro`: catálogo en grid de `GamePanel`,
  stepper de cantidad, botón "Comprar"), entrada "Tienda" en
  `NavBar.astro` (aparece en sidebar desktop y en la barra inferior
  mobile automáticamente, mismo array). Ícono `shop` en `Icon.astro` →
  `shop_cart.png`, ya curado y parqueado sin usar desde la auditoría de
  iconos (Grupo B, reservado explícitamente para esta fase) — no se
  agregó ni reorganizó ningún pack.
- Compra exitosa dispara `toast.success(...)` + `refreshPlayerState()`
  (HUD se actualiza sin recargar, mismo mecanismo de Fase 11); fallo
  dispara `toast.error(...)` (fondos insuficientes, producto no
  disponible). `home.astro` gana el `case "purchase"` en
  `describeActivity()`.

**Economía — fuente única de verdad:** `Character.coins` es la ÚNICA
columna de monedas en todo el proyecto (sin `shop_coins` ni wallet nueva);
`InventoryItem` es el ÚNICO modelo de inventario (sin tabla paralela). El
precio de compra SIEMPRE se lee de `shop_products.price` server-side —
verificado con test dedicado que un `price` mandado por el cliente se
ignora silenciosamente.

**Validación funcional real (Playwright + backend, sin mocks permanentes):**
grant+venta real de un item (mismo mecanismo de datos de prueba ya usado
en fases anteriores) para conseguir monedas → compra real vía UI → coins
120→112 verificado en `/shop`, en el HUD y en la DB; item verificado en
`/inventory`; evento `purchase` verificado en Actividad Reciente y por
API directa; compra sin fondos rechazada con 422 sin tocar coins/
inventario; producto inexistente/desactivado rechazado con 404; `price`
manipulado ignorado. Probado en desktop (1280px) y en 360/390/430px sin
overflow horizontal y con el toast visible sin overlaps.

**Deuda/observaciones detectadas, no resueltas en esta fase (a propósito):**
- Sin rotación horaria ni cupo de compra — el borrador original de esta
  hoja de ruta preveía ambos; se simplificó explícitamente para esta
  primera versión (re-briefing directo del dueño del proyecto). Migrar a
  rotación real es aditivo: una tabla `shop_rotations` nueva + cambiar
  `ShopController::index` para resolverla perezosamente, sin tocar
  `EconomyService::buy()`.
- `EconomyService::buy()` no usa `lockForUpdate()` sobre `Character` al
  chequear `coins` (mismo patrón exacto que `sell()`, ya existente y en
  producción) — con dos compras concurrentes del mismo jugador existe una
  ventana teórica de carrera. No es nuevo de esta fase, es el mismo perfil
  de riesgo que ya tenía `sell()`; documentado, no resuelto.
- `ChatTest.php` sigue con sus 2 fallos preexistentes ya documentados
  desde Fase 13 (datos reales acumulados en `chat_messages`) — no
  relacionado con esta fase.

### Fase 17 — Historial de Combates + Ataques Recibidos — ✅ Completada

**Implementado:**
- Cero tabla nueva — `combat_logs` ya tenía todo lo necesario (verificado
  antes de tocar nada). `CombatService::attack()` ahora loguea
  `combat_attacked` (atacante, mismo payload que antes) y `combat_defended`
  (defensor, nuevo) en la MISMA transacción — el defensor se entera vía el
  Activity Feed ya existente (Fase 12), sin inbox/notificaciones/realtime
  nuevo. El `type` legado `combat` (Fase 12-16) queda documentado y
  soportado en `describeActivity()` (`home.astro`) para no romper filas ya
  guardadas en la DB compartida de desarrollo.
- Nuevo `GET /v1/arena/combats` (`ArenaController::combats`): mis combates
  como atacante O defensor, paginado por cursor descendente (`before_id`,
  distinto del `after_id` de Chat/Activity a propósito — ahí el caso de uso
  es "pollear lo nuevo", acá es "navegar hacia atrás en un historial que
  ya arranca mostrando lo último"). Cada fila ya resuelve rol/oponente/
  resultado relativos al jugador server-side (se lee 100% de
  `events_json`, sin joins a `characters`), para no duplicar esa lógica en
  el cliente.
- `ArenaController::show()` (ya existente, Fase 10) extendido con
  `attacker_appearance_json`/`defender_appearance_json` (apariencia ACTUAL
  de ambos personajes) — necesario para el botón "Repetir".
- Frontend `arena.astro`: panel "Historial de combates" junto al Ranking
  (grid de 2 columnas en desktop, apilado en mobile). Cada fila combina
  SIEMPRE texto + ícono, nunca solo color (requisito de la fase): rol
  ("Atacaste a X" / "X te atacó" con íconos `attack_fist.png`/
  `shield_holy.png`) y resultado ("Victoria" verde + `cup.png` / "Derrota"
  roja + `skull.png`). Los 5 íconos (`attack`, `shield`, `victory`,
  `defeat`, `history`) ya estaban curados en la auditoría original sin
  usar — no se agregó ni un pack ni un emoji nuevo. Nuevo token
  `--color-green` en `theme.css` (mismo criterio tonal que cyan/red,
  pixel-art desaturado) porque no existía ningún acento verde.
- Botón "Repetir": llama únicamente a `GET /arena/combats/{id}` (ya
  existente), NUNCA a `POST /arena/attack` — reutiliza la misma
  `BattleScene`/`startGame()` que el combate en vivo (cero motor de
  combate nuevo, cero recálculo, cero `combat_log` nuevo, cero gasto de
  recursos). Un badge "Repetición..." deja explícito que no se altera
  ningún resultado; al terminar NO se llama `refreshPlayerState()` (nada
  cambió server-side). Paginación del historial vía botón "Cargar más".

**Deuda técnica documentada (a propósito, no inventada):** `combat_logs`
nunca guardó `appearance_json` por combate (solo `character_id`/`name`/
`level`/`stats`, sección 18 del roadmap de Fase 10 lo congela así a
propósito). "Repetir" anima con la apariencia ACTUAL de cada personaje, no
la que tenía en el momento histórico del combate — si alguien cambió de
equipo/aspecto después, la repetición no lo refleja. Si el personaje ya no
existe (FK `nullOnDelete`), el campo llega en `null` y el frontend degrada
a una vista de solo texto (`#battle-text-fallback`, log completo de
eventos sin animar) en vez de inventar un aspecto — cubierto por test
(`test_show_combate_devuelve_apariencia_null_si_el_personaje_ya_no_existe`).

**Validación funcional real (Playwright + backend, sin mocks permanentes):**
25/25 tests de `ArenaTest` (incluye historial mixto atacante/defensor,
aislamiento entre jugadores ajenos, paginación con 17 combates > el tope
de 15 sin duplicar ni perder filas, `combat_attacked`/`combat_defended`
verificados en `/activity` de ambos personajes, apariencia actual y su
caso `null`); `ActivityTest` actualizado para reflejar que ahora AMBOS se
enteran (antes solo el atacante). En navegador real: combate real
atacante→defensor y defensor→atacante, panel de Historial con 2 filas
correctas (roles/colores/íconos), "Repetir" reproduce la animación
completa en `BattleScene` con el resultado correcto (verificado tanto
Victoria/verde como Derrota/rojo, con `computed color` exacto del token),
Activity Feed en `/home` muestra el texto correcto para ambos roles,
paginación "Cargar más" verificada con 17 combates reales (15 + 2, botón
desaparece al agotarse). Cero overflow horizontal y cero errores de
consola en 360/390/430/1280px. Build (`astro build`) y `tsc --noEmit`
limpios.

**Qué NO se hizo (a propósito, según el alcance de esta fase):**
- Sin aviso instantáneo en tiempo real — el defensor se entera en el
  próximo poll del Activity Feed (10s), tal como especifica la fase.
- Sin inbox/tabla de notificaciones/mensajería privada.
- `ChatTest.php` sigue con sus 2 fallos preexistentes ya documentados
  desde Fase 13 — no relacionado con esta fase.
