# CLAUDE.md — Contexto del proyecto

Este archivo es la fuente de verdad de arquitectura para cualquier sesión de Claude
(chat o Claude Code) que trabaje en este repositorio. Léelo completo antes de tocar código.

Documentos de referencia completos en `/docs`:
- `docs/documento-maestro-arquitectura.md` — arquitectura, stack, DB, roadmap completo (26 secciones).
- `docs/FASE_0_SETUP.md` — guía de instalación ya ejecutada.

---

## Qué es este proyecto

Juego web 2D de progresión y personalización (estética pixel art), conceptualmente
inspirado en El Bruto / Habbo / Pet Society, con identidad propia. Sistemas centrales:
personaje personalizable por capas, combate PvP **asíncrono**, mascota con exploración
**AFK**, recursos/crafting, habitación personal.

## Stack (fijo, no renegociar sin discutirlo explícitamente)

- **Astro** — shell/web/UI (auth, landing, punto de entrada `/play`)
- **Tailwind CSS v4** (`@tailwindcss/vite`, desde Fase 4.1) — layout/spacing/
  responsive; el lenguaje visual propio (pixel art, bordes duros) vive en
  `web/src/styles/theme.css`, no se reemplaza por Tailwind
- **Phaser 3 + TypeScript** — gameplay y renderizado, vive en `web/src/game`
- **Laravel 11** — única autoridad de lógica de negocio
- **MySQL 8** — persistencia
- **Sanctum** — autenticación
- Git, monorepo con carpetas `backend/`, `web/`, `docs/`

**Explícitamente NO usamos en el MVP:** Redis, WebSockets, colas, scheduler activo,
microservicios. Ver razón abajo (resolución perezosa).

## Principios de arquitectura (no negociables)

1. **El servidor es la única autoridad.** El cliente nunca decide stats, daño, loot,
   ownership ni resultados de ningún tipo. Solo pide y reproduce visualmente.
2. **Resolución perezosa en vez de scheduler/colas.** Ej: una expedición de mascota
   guarda `started_at` + `ends_at`; el resultado se calcula la primera vez que alguien
   consulta ese recurso después de `ends_at`, no mediante un job programado. Esto aplica
   a expediciones, y aplicará a "tiempo de fabricación" en crafting cuando se implemente.
3. **`appearance_json` es JSON** (se lee entero para renderizar, nunca se filtra/ordena
   por él). **Los stats del personaje (`level`, `exp`, `strength`, etc.) son columnas
   normales**, no JSON — se necesitan para rankings, matchmaking de combate y balance.
4. **`items` (catálogo/definición) vs `inventory_items` (instancia que posee el
   jugador)** son conceptos separados. `inventory_items` incluye una columna
   `instance_data_json` (nullable, vacía en MVP) para permitir a futuro durabilidad o
   stats individuales por instancia **sin migrar el inventario**.
5. **Sistema de assets desacoplado vía `web/public/assets/manifest.json`.** El código
   nunca referencia rutas de archivo, solo claves lógicas (`hair:5`). Reemplazar
   assets provisionales por definitivos = editar el manifest, cero cambios de código.
6. **Combate:** el cliente hace `POST /combat/attack { target_character_id }`; el
   servidor calcula todo (incluye un `seed` para determinismo) y guarda un log de
   eventos en `combat_logs`; el cliente solo lo reproduce vía `GET /combat/{id}`.
   Funciona con el objetivo desconectado.

## Metodología de trabajo (importante, aplica también a Claude Code)

- **Trabajamos estrictamente por milestone/fase.** No implementar varias fases a la vez.
- Al terminar una fase, **detenerse** y reportar: qué se implementó, qué archivos se
  crearon/modificaron, cómo probarlo, qué comandos ejecutar, qué resultado esperar, y
  cualquier problema o decisión pendiente. Avanzar a la siguiente fase solo tras
  confirmación explícita del usuario.
- Si una decisión de esta arquitectura parece incorrecta o va a causar problemas
  futuros, decirlo y proponer alternativa **antes** de introducir un cambio
  arquitectónico importante — no seguirla ciegamente.
- No sobreingeniería: nada de Redis/colas/WebSockets/microservicios hasta que haya
  métricas reales que lo justifiquen (ver sección 17 del documento maestro).
- Comandos Artisan de desarrollo (`item:grant`, `character:set-level`,
  `expedition:complete`, `dev:reset`) se crean cuando exista contenido que manipular
  (a partir de Fase 6 — Inventario — en adelante), no antes.

## Estado actual del roadmap

- [x] **Fase 0 — Arquitectura y setup.** Completada y verificada: Astro + Phaser +
      Laravel + MySQL corriendo en local, `/play` muestra "API OK" con respuesta real
      de `GET /api/v1/ping`. `install:api` ya dejó Sanctum instalado para Fase 1.
- [x] **Fase 1 — Cuenta y autenticación.** Completada y verificada: Sanctum con tokens
      Bearer (`/api/v1/auth/register|login|logout|me`), páginas `/register` y `/login`
      en Astro, `/play` protegido (redirige a `/login` sin sesión).
- [x] **Fase 2 — Personaje base.** Completada y verificada: el sprite real del
      Character Template Pack (`web/public/assets/characters/player/base/`) se carga y
      anima (idle) dentro de Phaser vía la clase `Player`. Fuentes editables en
      `art/characters/player/base/`. Nota: las columnas de stats (`level`, `exp`,
      `strength`, etc.) y `appearance_json` en DB **todavía no existen** — quedan para
      cuando el Character Renderer de Fase 3 necesite persistir datos reales.
- [x] **Fase 3 — Sistema de assets + Character Renderer.** Completada y verificada:
      `CharacterRenderer` (`web/src/game/entities/CharacterRenderer.ts`) compone al
      personaje apilando capas (`body`, `pants`, `shoes`, `shirt`, `hair`, `weapon`,
      `accessory`) en un `Phaser.GameObjects.Container`; cada capa se resuelve por
      clave lógica (`body:base`) vía `web/src/game/assets/manifest.ts` leyendo
      `web/public/assets/manifest.json` — el código ya no referencia ninguna ruta de
      archivo a mano (cierra la deuda de la Fase 2, donde el path del PNG estaba
      hardcodeado). `PreloadScene` ahora carga en dos pasadas: primero el manifest,
      luego los spritesheets que la apariencia por defecto (`body: "base"`) resuelve.
      Solo existe contenido real en la capa `body`; el resto de capas se omiten
      silenciosamente si no están en el manifest (no rompen el render). Todavía NO hay
      editor de personaje, selector de ropa/pelo, inventario cosmético ni equipamiento
      — solo la base técnica del renderer, como estaba planeado.
- [x] **Fase 4 — Application Shell + HUD + Navegación.** Completada y verificada:
      `AppShell.astro` (`web/src/layouts/`) compone `Hud.astro` + `NavBar.astro` +
      `<slot />`; el guard de auth se centralizó ahí (antes duplicado en cada página).
      HUD con nombre real vía `ApiClient.me()` y nivel/exp/monedas/recursos como MOCK
      explícito (`MOCK_PLAYER_PROGRESS` en `Hud.astro`, comentado como temporal — esos
      sistemas no existen en el backend todavía). Nav global de 8 módulos; solo
      "Mundo" (`/play`) tiene contenido real hoy, el resto son placeholders
      (`PlaceholderView.astro`) con badge "Próximamente". `/play` se integró al Shell
      sin tocar `main.ts`, `PreloadScene`, `CharacterRenderer` ni el manifest — sigue
      recibiendo solo un id de contenedor DOM, no hay import inverso (Phaser nunca
      referencia el HUD/Shell). Sin Tailwind (no estaba configurado, se evitó sumarlo
      al stack sin discutirlo): paleta y componentes en CSS plano con tokens en
      `web/src/styles/theme.css`. Login/registro ahora redirigen a `/home` (la nueva
      "Inicio") en vez de `/play`. **No** se implementó personalización de personaje,
      ni funcionalidad real de Casa/Mascota/Arena/Social/Inventario/Ranking — según lo
      planeado. `index.astro` (scaffold default de Astro) quedó sin tocar, fuera de
      alcance de esta fase.
- [x] **Fase 4.1 — UI Foundation + HUD Redesign.** Completada y verificada: se
      incorporó **Tailwind CSS v4** vía `@tailwindcss/vite` (comando oficial
      `astro add tailwind`, que detectó solo la integración compatible con Astro 7 —
      ya no existe `@astrojs/tailwind` ni `tailwind.config.js` en v4, la config es
      CSS-first). Tailwind resuelve layout/spacing/flex/grid/responsive; el lenguaje
      visual propio (bordes duros, sombra sin blur, tokens de color) sigue viviendo en
      `web/src/styles/theme.css`, que ahora también es el único punto de entrada de
      Tailwind (`@import "tailwindcss"` + bloque `@theme` que expone los tokens como
      utilidades: `bg-surface`, `text-pink`, `shadow-pixel`, etc. — un solo archivo de
      verdad, sin duplicar `global.css`). Se agregó la fuente "Press Start 2P" (Google
      Fonts) solo para acentos cortos (logo, badges, títulos de panel), nunca para
      texto largo. HUD y Nav rediseñados sobre un set pequeño de componentes
      reutilizables nuevos en `web/src/components/ui/` (`GameButton`, `GameBadge`,
      `ProgressBar`, `ResourceCounter`, `IconButton`). El mock de nivel/exp/monedas
      /recursos se centralizó en `web/src/data/mockPlayerProgress.ts` (antes vivía
      solo en `Hud.astro`) para que Home y HUD muestren el mismo dato sin duplicarlo.
      Se agregó `web/src/components/chat/GlobalChat.astro`: UI de chat global 100%
      mock (mensajes hardcodeados, enviar solo agrega el mensaje al DOM local) — sin
      backend, sin WebSockets, sin persistencia; vive como columna acoplada al layout
      (no floating/`position: fixed`, eso tapaba contenido). `/home` ganó secciones
      mock ("Estado del personaje", "Actividad reciente", "Accesos rápidos"),
      claramente marcadas como datos de ejemplo. **No** se tocó `main.ts`,
      `PreloadScene`, `CharacterRenderer` ni el manifest — `/play` se ve y funciona
      igual que en Fase 4. **No** se agregó personalización, backend de chat ni
      ninguna mecánica nueva.
- [x] **Fase 4.1B — Visual Refinement / Game UI Identity.** Completada y verificada:
      segunda iteración exclusivamente visual sobre la Fase 4.1, sin nueva arquitectura
      ni dependencias (Tailwind y theme.css se mantienen). Cambios principales:
      **eliminados los emojis de navegación/HUD/placeholders**, reemplazados por un
      registro central de íconos SVG (`web/src/components/ui/Icon.astro`, placeholders
      vectoriales simples — reemplazables por pixel-art real sin tocar el layout que
      los usa). Sistema de paneles formalizado en `GamePanel.astro` (header con acento
      de color + tipografía pixel, modificador `--notched` con esquinas cortadas estilo
      diálogo RPG, borde interior sutil vía `theme.css`). HUD reorganizado como
      "player status bar" con grupos separados por divisores (`.game-divider`) en vez
      de una fila plana. Sidebar con label de sección y estado activo más marcado.
      Chat global envuelto en el mismo lenguaje de panel (header con acento, esquinas
      cortadas). Nuevo `web/src/components/game/GameView.astro`: marco de la Game View
      con header ("MUNDO" + indicador de estado decorativo) y una **capa de fondo
      ambiental** detrás del canvas vía CSS (`background-image` + `opacity-30` +
      `image-rendering: pixelated`), usando `public/backgrounds/fondo_1.png` (900×700,
      pixel-art de una calle japonesa con torii — la ruta real difiere de
      `public/assets/backgrounds/` que se había mencionado). El fondo es una prop
      (`background`) para poder asignar uno distinto por sección más adelante (Casa,
      Arena, etc.) sin tocar Phaser. **Bug encontrado y corregido en el momento:** el
      primer intento de `GameView` usaba `overflow-hidden` en el marco, y como el
      `<main>` del Shell estira sus hijos a su ancho disponible (`flex` + `align-items:
      stretch` por defecto), el canvas de 900px quedaba recortado en vez de mostrarse
      completo cuando la ventana no alcanzaba; se corrigió sizeando el marco a su
      contenido (`w-fit`) en vez de dejarlo estirarse. En viewports angostos (~1280px)
      el `<main>` scrollea horizontalmente para mostrar el Game View completo — es el
      fallback aceptado (prioridad desktop, sin deformar el canvas). **No** se tocó
      `main.ts`, `PreloadScene`, `CharacterRenderer`, `WorldScene` ni el manifest — el
      personaje se ve y anima exactamente igual que antes. **No** se implementó
      ninguna mecánica ni personalización nueva, solo UI/mock.
- [x] **Fase 5 — Mundo Social / Hub mínimo jugable (integración Tiled, cierre).**
      Reemplaza el plan original de "Habitación aislada" — `/play` ("Mundo") es el hub
      social top-down del juego. **El mapa real diseñado en Tiled
      (`web/public/assets/world/mapa_base.tmx`, 80×50 tiles de 16px = 1280×800px) es
      ahora la fuente de verdad del layout**, reemplazando por completo el prototipo
      hardcodeado de la iteración anterior de esta fase (tiles/árboles/estructuras a
      mano en código — ya no existen). Verificado y funcionando: cámara con
      follow+bounds sobre el mapa real, colisión derivada de los objetos de Tiled
      (bounding box de cada polígono/rectángulo de la capa "Capa de Objetos 1" — nada
      de coordenadas hardcodeadas por árbol/edificio), movimiento en 4 direcciones
      **sin diagonal** (un solo eje activo, horizontal con prioridad), 1 NPC con
      diálogo, 4 estructuras interactuables derivadas por nombre de objeto
      (`edificio`→Dojo, `casa`→Casa, `hotel`→Arena, `pet`→Mascota; `rio` queda como
      obstáculo de agua sin interacción). **Jugador oficial: `characters/player/base`,
      archivos con prefijo `16x16` (Idle/Walk/Run/Rotate-Sheet), frame real 24×24** —
      el pack Anokolisa se descartó como jugador (sigue usándose solo para el NPC y
      los tiles del mundo). Corrección posterior importante: los archivos con prefijo
      `16x32` (frame real 32×32, usados al principio por error) mostraban una sola
      vista sin direcciones reales; los `16x16` (frame real 24×24, **no 16×16 — otra
      vez el nombre del archivo no describe el tamaño real**, verificado con
      detección de componentes conectados) sí traen **5 direcciones reales** por fila
      (down, down-right, right, up-right, up) × 4-6 columnas de frames, en Idle/Walk/
      Run. Las 3 direcciones del lado izquierdo (down-left, left, up-left) no tienen
      fila propia — se obtienen con flipX sobre su contraparte derecha, técnica
      **verificada como fiel al asset y no inventada**: el Rotate-Sheet trae las 8
      direcciones en una fila y sus columnas izquierdas son, píxel a píxel (0% de
      diferencia medida), el espejo horizontal exacto de las derechas. Sistema de
      animación en `WorldPlayer.ts` con `animation` (idle/walk/run) y `direction` (8
      valores) desacoplados — `run` queda registrado y listo para usarse aunque el
      movimiento actual sigue siendo 4-direccional sin diagonal, por decisión de
      diseño vigente, no por limitación técnica. Los archivos `16x32` **no se
      borraron**: `manifest.json` → `body.base` (Fase 3, `CharacterRenderer`, para
      Arena/personalización futura) todavía los usa; borrarlos habría roto esa fase.
      **Problema técnico real resuelto:** el `.tmx` exportado desde Tiled
      referenciaba 14 de sus 16 tilesets como archivos externos bajo
      `../../Downloads/...` (rutas de la máquina del autor, inexistentes acá) y Phaser
      no tiene parser nativo de `.tmx`/XML (solo Tiled-JSON vía
      `load.tilemapTiledJSON`) — se escribió un conversor puntual (`.tmx` → JSON) que
      reconstruye la tabla de tilesets apuntando a los PNG reales del proyecto,
      **verificados por conteo exacto de tiles** (ancho×alto de cada imagen ÷16,
      comparado 1:1 contra el tilecount implícito por los `firstgid` del `.tmx`) — los
      14 coincidieron exactamente. El `.tmx` original queda intacto como fuente de
      diseño; `mapa_base.json` es el artefacto que Phaser realmente carga. Sistema de
      manifest extendido (no duplicado): `tiledMap`, `tiledTilesets`, `worldPlayer`
      (ahora `body/base` en vez de Anokolisa), `npcs`. `main.ts`, `CharacterRenderer`
      y el Application Shell/HUD/Nav/Chat de Fase 4.1B — intactos. Módulo
      `web/src/game/world/` (`WorldMap`, `WorldPlayer`, `Npc`, `InteractionUI`,
      `worldAssets`) reescrito para leer el mapa real en vez de datos hardcodeados.
- [x] **Pre-flight — Modelo de datos para Fases 6–10 (preparación, NO es Fase 6).**
      Completada y verificada: pase exclusivamente de esquema/DB para que Fases 6-10
      (inventario, equipamiento, mascota/AFK, crafting, habitación, combate) no
      requieran migraciones destructivas. **No** se implementó ninguna lógica
      funcional de esos sistemas — solo tablas y modelos Eloquent vacíos de
      comportamiento. Nuevas tablas/modelos: `characters` (stats como columnas
      normales — `level/exp/strength/agility/vitality` — y `appearance_json` JSON con
      default `{"body":"base"}`, 1:1 con `users`), `items` (catálogo genérico, enum
      PHP `App\Enums\ItemType` cast sobre columna string — deliberadamente NO un ENUM
      de MySQL, para poder agregar tipos sin `ALTER TABLE`), `inventory_items`
      (instancia poseída por un personaje: `character_id` + `item_id` + `quantity`
      con CHECK `quantity >= 1` vía `DB::statement` porque Laravel 11 no tiene
      `Blueprint::check()` + `instance_data_json` nullable para futura durabilidad/
      rolls sin re-migrar), `character_equipment` (slot equipado referencia
      `inventory_item_id`, no `item_id` — decisión explícita: así un futuro sistema
      de durabilidad/rolls por instancia se refleja automáticamente en lo equipado;
      enum `App\Enums\EquipmentSlot` espeja `LAYER_ORDER` de `CharacterRenderer.ts`
      menos "body"; unique en `[character_id, slot]`), `pets` + `pet_expeditions`
      (`started_at/ends_at/resolved_at` sin scheduler — preparado para resolución
      perezosa: el resultado se calculará la primera vez que se consulte después de
      `ends_at`, cuando Fase 7 implemente esa lógica), `rooms` + `room_items`
      (`inventory_item_id` nullable en `room_items` porque un objeto de habitación
      puede no provenir del inventario personal a futuro), `combat_logs`
      (`attacker_character_id/defender_character_id/winner_character_id` **nullable
      con `nullOnDelete()`** — decisión explícita para preservar el historial de
      combate aunque un personaje se elimine después). Cascade/restrict elegidos por
      tabla: `cascadeOnDelete()` en todo lo que es "propiedad exclusiva" de un
      character (inventory_items, character_equipment, pets, rooms), `restrictOnDelete()`
      en FKs hacia el catálogo `items` (no se puede borrar un item si sigue en algún
      inventario/habitación), `nullOnDelete()` solo en `combat_logs` por la razón de
      historial arriba. **Bug de Laravel/MySQL encontrado y corregido:** usar
      `$table->timestamp()` para más de una columna nullable sin default explícito en
      `pet_expeditions`/`combat_logs` causaba error 1067 ("Invalid default value") en
      modo estricto de MySQL/MariaDB — se resolvió usando `dateTime()` en su lugar.
      **Discrepancia de entorno detectada (no corregida, solo documentada):** la DB
      local corriendo es **MariaDB 10.4.32**, no "MySQL 8" como dice este archivo —
      ambas son compatibles con lo implementado aquí, pero si en el futuro se usa una
      feature exclusiva de MySQL (p.ej. `JSON_TABLE`) hay que verificar soporte en
      MariaDB primero. Verificación exhaustiva vía `php artisan tinker`: cascade,
      restrict y nullOnDelete probados con filas reales (no solo lectura de migración);
      constraint UNIQUE y CHECK probados intentando insertar duplicados/inválidos;
      grafo completo de relaciones Eloquent recorrido (`User→Character→
      InventoryItem/CharacterEquipment/Pet→PetExpedition/Room→RoomItem/CombatLog`).
      `php artisan test` (2 passed), `GET /api/v1/ping` y `/play` (Fase 5, verificado
      con Playwright sin errores de consola) confirmados intactos. Toda la data de
      prueba se limpió de la DB al terminar. **No** se tocó `WorldScene`, `WorldPlayer`,
      `CharacterRenderer`, `PreloadScene`, `main.ts`, `GameView`, `AppShell`, `HUD`,
      `NavBar` ni `manifest.json`. **No** hay comandos Artisan de contenido
      (`item:grant`, etc.), factories/seeders de catálogo, ni ninguna ruta/controller
      nuevo — eso es Fase 6 en adelante.
- [x] **Fase 6 — Inventario y equipamiento (items / inventory_items).** Completada y
      verificada: primera lógica funcional real sobre el esquema del pre-flight — sin
      migraciones nuevas (el esquema ya alcanzaba). Backend: `ItemController` (catálogo
      de solo lectura, `GET /v1/items`), `InventoryController` (`GET /v1/inventory`,
      `POST /v1/inventory/grant`), `EquipmentController` (`GET /v1/equipment`,
      `POST /v1/equipment/equip`, `DELETE /v1/equipment/{slot}`) — todos bajo
      `auth:sanctum`, todos derivan "el personaje" del usuario autenticado
      (`$request->user()->character`), **nunca** de un `character_id` que mande el
      cliente. `AuthController::register` ahora crea el `Character` del usuario en el
      mismo request (antes ningún flujo creaba uno — sin esto Fase 6 no tenía dónde
      vivir). `ItemSeeder` con catálogo mínimo (9 items: hair/shirt/pants/shoes/
      weapon/accessory cosméticos + 1 recurso stackable de prueba) — `items.subtype`
      guarda el valor de `EquipmentSlot` directamente, sin columna nueva. Comando de
      desarrollo `item:grant {character_id} {item_key} {quantity}` (primer comando de
      esta categoría, tal como preveía la Metodología de este archivo). Reglas de
      negocio server-side: `grant` respeta `stackable`/`max_stack` (parte el sobrante
      en varias filas, nunca una fila por encima del límite); `equip` valida ownership
      de la instancia, que el item tenga un `subtype` mapeable a `EquipmentSlot` y que
      su `type` sea `Equipment`/`Cosmetic`, y solo entonces escribe la clave del slot
      en `characters.appearance_json` con el `key` del item (el resto de las claves
      queda intacto); `unequip` borra la fila de `character_equipment` y pone esa
      clave en `null` (no la elimina, para que la forma de `appearance_json` sea
      estable). Frontend: `inventory.astro` reemplaza su `PlaceholderView` — HTML/CSS
      puro sobre el sistema de paneles existente (tabla de módulos de la Fase 4:
      Inventario es Astro/HTML, no Phaser), patrón idéntico a `Hud.astro` (esqueleto
      estático + `<script>` de cliente contra `ApiClient`, sin frameworks nuevos).
      Tres paneles: Equipamiento (6 slots con "Quitar"), Inventario (items propios con
      "Equipar"/"Equipado"), Catálogo ("Obtener" — ver limitación abajo). `ApiClient.ts`
      ganó las funciones/tipos de character/items/inventory/equipment, mismo patrón
      `request()` con Bearer token que ya existía. **Decisión: no se tocó
      `CharacterRenderer`/`Player`/`WorldScene` para mostrar capas equipadas en vivo.**
      Ninguno de los items del catálogo tiene sprite propio todavía (manifest
      `hair`/`shirt`/`pants`/`shoes`/`weapon`/`accessory` siguen `{}`), así que
      cualquier composición visual sería invisible de todos modos; la tabla de
      módulos de la Fase 4 ya asigna Inventario a Astro/HTML, así que "reflejar el
      cambio visualmente" se satisface con el estado de la UI (slot ocupado/vacío),
      no con el canvas de Phaser — evita ingeniería especulativa sin payoff visible
      hoy. **Cambio de formato de sprite del Mundo (pedido explícito de esta fase):**
      `manifest.json`/`manifest.ts` — `worldPlayer` ahora apunta a los sheets `16x32`
      (frame real 32×32, verificado por dimensión de imagen: Idle/Walk 128×160 = 4
      cols×5 filas @32px, Run 192×160 = 6×5 @32px, mismo layout direccional que
      16x16, solo escalado) en vez de `16x16`; el `16x16` (frame real 24×24) se
      preserva íntegro bajo la nueva clave `housePlayer`, reservada para una futura
      vista específica de Casa. Cambio hecho **enteramente en datos del manifest** —
      `WorldPlayer.ts`, `worldAssets.ts` y `PreloadScene.ts` ya eran agnósticos al
      tamaño de frame (leen `frameWidth`/`frameHeight`/`columns`/`rows` del manifest,
      nunca hardcodeados), así que no hicieron falta cambios de código para el swap;
      esto es lo que la fase pedía como "sin duplicar lógica de apariencia entre
      representaciones". **No** se tocó `WorldScene`, `CharacterRenderer`,
      `PreloadScene` (lógica), `main.ts`, `GameView`, `AppShell`, `HUD`, `NavBar`.
      Tests nuevos: `tests/Feature/InventoryTest.php` (5) y
      `tests/Feature/EquipmentTest.php` (6), usando `DatabaseTransactions` (no
      `RefreshDatabase`) para no migrar/dropear la DB de desarrollo real en cada
      corrida — cada test revierte su propia transacción. **Bug real encontrado y
      corregido durante la verificación con Playwright (no solo con tests
      unitarios):** `renderEquipment()` se llamaba antes que `renderInventory()` en
      `inventory.astro`, así que el estado "Equipado" que el primero intentaba pintar
      sobre los botones del inventario quedaba pisado por el re-render del segundo —
      se corrigió pasando el set de `inventory_item_id` equipados como parámetro a
      `renderInventory()` en vez de que `renderEquipment()` intente mutar el DOM ajeno
      después. Encontrado precisamente por seguir la metodología de este archivo
      (verificar en el navegador real, no solo confiar en que compile). Verificado:
      13/13 tests backend pasan, flujo completo end-to-end por curl y por Playwright
      (registro → personaje con appearance por defecto → grant → equipar → 422 al
      equipar un recurso no equipable → 403 al equipar instancia ajena → desequipar →
      404 al desequipar un slot vacío), `/play` sin errores de consola con el sprite
      16x32 nuevo, `GET /api/v1/ping` OK, `tsc --noEmit` sin errores. DB de
      desarrollo limpiada de todos los usuarios/personajes de prueba al terminar —
      el catálogo de 9 items sembrado por `ItemSeeder` se dejó (es contenido, no
      basura de test). **No** se implementó: mascota/AFK, recursos/crafting más allá
      del item de prueba, habitación/decoración, combate/PvP/matchmaking, ningún
      cálculo de stats derivado de equipamiento (el sistema queda preparado para que
      F10 lo consulte, pero no lo hace todavía), ni composición visual real de capas
      equipadas (sin assets, ver decisión arriba).
- [x] **Fase 7 — Mascota + exploración AFK.** Completada y verificada: loop completo
      Mascota → Destino → Expedición AFK → Eventos → Regreso → Loot → Reclamar →
      Inventario. **Decisión de diseño central:** todo el resultado de una expedición
      (eventos, retraso, loot, delta de vida) se calcula **una sola vez, al iniciar**
      (`PetExpeditionService::start`), no al consultarla después — mismo principio que
      `combat_logs` (CLAUDE.md #6, servidor calcula todo una vez). `ends_at` ya sale
      ajustado por cualquier retraso de evento desde el momento del `start`, así que
      "¿terminó?" es solo `now() >= ends_at` (`PetExpedition::isDue()`); esto hace la
      resolución perezosa (CLAUDE.md #2) trivialmente idempotente sin locks ni jobs —
      consultarla 1 o 100 veces da el mismo resultado. Esquema: el pre-flight ya tenía
      `pets`/`pet_expeditions` con la forma correcta; se AGREGARON columnas
      (`health/max_health/energy/max_energy/status` a `pets`) y se creó
      `pet_destinations` (catálogo, mismo espíritu que `items`) + `pet_expeditions.
      destination_id` (FK, reemplaza el `expedition_type` string suelto del
      pre-flight que nunca llegó a tener datos reales). `pets.key` se reutiliza como
      "species" (no se agregó columna redundante). 3 destinos sembrados (Bosque/
      Montañas/Castillo Sangriento) vía `PetSeeder`, con `loot_pool_json` apuntando a
      11 recursos nuevos + `resource_wood` reusado de F6 (`ItemSeeder` extendido).
      Eventos aleatorios en `config/pet_events.php` (config estática, no tabla — nada
      administra esto en runtime todavía). Todo Character nuevo recibe mascota
      automáticamente al registrarse (`PetProvisioningService`, mismo principio que
      el Character); personajes preexistentes sin mascota la reciben perezosamente la
      primera vez que se consulta `GET /pet` (unique constraint en `pets.character_id`
      cubre la carrera). **Refactor sin cambio de comportamiento:** la lógica
      stackable/max_stack de `InventoryController::grant` se extrajo a
      `InventoryGrantService` para que `claim` la reutilice sin duplicarla (pedido
      explícito de la fase) — tests de F6 verificados intactos tras el refactor.
      Endpoints nuevos (`GET /pet`, `GET /pet/destinations`, `GET /pet/expedition`,
      `POST /pet/expedition/start`, `POST /pet/expedition/claim`, `GET /pet/events`),
      todos bajo `auth:sanctum`, todos resolviendo `user→character→pet` (nunca un
      `pet_id`/`character_id` del cliente). `/pet/events` (historial) muestra
      solamente expediciones `claimed` — una `completed`-sin-reclamar ya se muestra
      como acción pendiente en `GET /pet`, listarla también en el historial mostraría
      loot como "recibido" antes de llegar al inventario (bug de UX encontrado y
      corregido durante la verificación con Playwright). Frontend: `/pet` reemplaza
      su placeholder, mismo patrón que `/inventory` (HTML/CSS + `<script>` contra
      `ApiClient`, sin Phaser — Mascota es Astro/HTML en la tabla de módulos de la
      Fase 4). Placeholder visual = `assets/pets/catsparin.png` vía `<img>` normal
      (no pasa por el manifest de Phaser); countdown local solo cosmético entre
      refrescos de 20s, el backend sigue siendo la fuente de verdad. **Bug real
      encontrado y corregido:** `ApiClient.request()` no pasaba `cache: "no-store"` —
      sin eso, algunas respuestas GET quedaban servidas desde caché del navegador
      entre acciones (start/claim) en vez de reflejar el estado recién cambiado.
      **Bug de test (no de la app) encontrado durante la verificación:** los tests de
      eventos asumían que un pool de eventos con una sola entrada SIEMPRE la
      dispararía — `rollEvents()` también tira cuántos eventos aplicar (puede ser 0
      aunque el pool tenga contenido), así que ~50% de las corridas fallaban
      (`flaky`). Se corrigió reintentando con un personaje nuevo cada vez hasta
      observar el evento, en vez de asumir determinismo que el propio diseño no
      garantiza. Verificado: 28/28 tests backend (estables en corridas repetidas),
      flujo real por Playwright completo (idle → explorar → esperar [`ends_at`
      adelantado vía tinker, no esperar 12hs reales] → completada con eventos/loot →
      reclamar → recursos visibles en `/inventory` real), `/play` y `/inventory`
      verificados sin errores de consola tras los cambios, `tsc --noEmit` limpio,
      `GET /api/v1/ping` OK. **No** se implementó: combate/PvP/breeding/evolución de
      mascotas, equipamiento de mascota, alimentación, crafting completo (F8),
      casa/decoración (F9), ni ningún sprite definitivo — el placeholder está
      estructurado para reemplazarse sin tocar backend ni modelo de datos.
- [x] **Fase 7.1 — Bitácora narrativa de expediciones.** Completada y verificada:
      extensión de F7, sin tocar su gameplay — los eventos narrativos NO dan loot, NO
      quitan HP, NO agregan tiempo, son puramente de sabor. **Mismo principio de
      diseño que el resto de F7:** la bitácora COMPLETA (qué evento, en qué categoría/
      rareza, y a qué minuto exacto ocurre) se decide una sola vez en
      `PetExpeditionService::start()` y se guarda en
      `result_data_json.narrative_log`; "revelarla" después es solo filtrar por
      `occurred_at <= now()` (`PetPresenter::visibleNarrativeLog`) — nunca se vuelve a
      tirar un dado. Catálogo nuevo `pet_narrative_events` (tabla, no config — a
      diferencia de `config/pet_events.php` que son los eventos MECÁNICOS de F7, este
      es contenido de texto extenso pensado para crecer, mismo criterio que "items es
      tabla y no config"): 108 eventos (18 universales + 30 por cada uno de los 3
      destinos), con `category` y `rarity` (enums PHP, mismo patrón que
      `ItemType`/`EquipmentSlot`) y `weight`/`is_active` para desempate y desactivar
      contenido sin borrarlo. Selección: se tira la rareza primero con probabilidad
      fija (common/uncommon/rare/very_rare, independiente de cuántas filas haya por
      rareza en el catálogo), degradando hacia "común" si esa rareza no tiene
      candidatos disponibles; recién después se elige un evento concreto dentro de
      esa rareza (ponderado por `weight`), evitando los últimos 3 usados en la misma
      expedición. Cantidad de eventos por expedición: `clamp(3, 20, duración/12min)` —
      nunca "spam" ni infinitos (sección 10 de la fase). Distribución en el tiempo:
      la duración total se reparte en franjas iguales con jitter (±15% del borde de
      cada franja) para que se sienta orgánico sin permitir que dos eventos caigan
      pegados ni que se amontonen al final. **Bug real encontrado y corregido por los
      tests (no a ojo):** `Carbon::diffInMinutes()` en esta versión de Carbon devuelve
      la diferencia CON SIGNO según el orden de la llamada — `$endsAt->diffInMinutes
      ($startedAt)` daba **-240** en vez de 240, y ese negativo hacía que `intdiv()`
      cayera siempre al piso de 3 eventos sin importar la duración real. Se corrigió
      llamando `$startedAt->diffInMinutes($endsAt, absolute: true)`. Encontrado por
      `test_los_eventos_narrativos_no_comparten_el_mismo_timestamp`, no por inspección
      manual. Endpoint reutilizado: `GET /v1/pet/expedition` (ya existía en F7) ahora
      también trae `narrative_log`; no se agregó ningún endpoint nuevo. UI: nueva
      sección "📖 Bitácora de aventura" en `/pet`, oculta cuando no hay expedición
      activa/completada-sin-reclamar, con auto-scroll al final — reutiliza el mismo
      polling de 20s que ya existía, sin timers nuevos. **No** se tocó el modelo de
      datos de F7 (`pets`, `pet_expeditions`, `pet_destinations` sin cambios de
      columnas) ni su lógica mecánica (`rollEvents`/`rollLoot`/`resolveIfDue`/`claim`
      intactos). Tests nuevos: `PetNarrativeTest.php` (10), cubriendo creación,
      seguridad (bitácora ajena inaccesible), no-timestamps-duplicados, tope de 20,
      no-repetición-consecutiva, exclusividad por destino (probado con destinos de
      prueba dedicados, no estadísticamente — la query en sí garantiza la exclusión),
      eventos universales, persistencia, reconstrucción tras "cerrar el navegador"
      (`Carbon::setTestNow`) y que `claim` no altera la bitácora. Verificado: 38/38
      tests backend (estable en corridas repetidas), flujo real por Playwright
      (expedición iniciada → bitácora vacía a t=0 (correcto, nada "ocurrió" todavía) →
      timestamps de la expedición desplazados vía tinker para simular avance real del
      reloj → 4/10 eventos revelados a mitad de camino, incluido un evento `very_rare`
      → expedición completada → 10/10 revelados → reclamado → bitácora se oculta al
      volver a `idle`, loot/HP mecánicos sin alterar), `/inventory` y `/play` sin
      errores de consola, `tsc --noEmit` limpio, `GET /api/v1/ping` OK. **No** se
      implementó combate/evolución/breeding/equipamiento de mascota ni ningún sistema
      de F8 en adelante — esto fue exclusivamente contenido narrativo cosmético.
      **Corrección F7.1.1 (UI/UX, mismo alcance, sin tocar backend):** la Bitácora
      pasó de ser un `GamePanel` propio debajo de "Mi mascota"/"Estado" a vivir
      **dentro** del panel "Estado" (separador + header, mismo panel que el
      countdown/resultado de la expedición) — visible desde el instante en que
      arranca la expedición, con un empty-state ("La aventura acaba de comenzar...")
      en vez de esperar al primer evento para mostrarse. El feed se actualiza solo
      con el polling de 20s que ya existía (`setInterval(refresh, 20000)`, sin
      WebSockets ni un mecanismo nuevo) y renderiza de forma **incremental** (solo
      agrega las entradas nuevas al DOM, nunca reconstruye la lista completa) para
      poder animarlas (fade + translateY, ~200ms, `prefers-reduced-motion` respetado)
      sin re-disparar la animación sobre entradas ya vistas; auto-scroll solo si el
      jugador ya estaba viendo el final del feed (no le mueve la lectura si estaba
      revisando entradas viejas más arriba). **Bug real encontrado y corregido:** la
      primera versión de este renderizado incremental usaba `event_id` como clave de
      "ya renderizado" — pero el mismo evento del catálogo puede aparecer más de una
      vez en una misma expedición (el anti-repetición de F7.1 solo evita las últimas
      3 consecutivas, no unicidad global), así que una repetición legítima
      compartía `event_id` con su aparición anterior y el poll nunca la mostraba
      -confirmado paso a paso: un `setInterval` de prueba SÍ disparaba a tiempo en el
      mismo contexto de página (headless no estaba limitando timers), y la API
      (`GET /v1/pet` y `/pet/expedition`) ya devolvía el dato correcto y actualizado;
      el problema estaba puramente en la clave de dedup del cliente-. Se corrigió
      usando la POSICIÓN en el array (`log.slice(renderedCount)`) en vez de
      `event_id`, ya que el orden del log es estable (el backend solo agrega
      entradas, nunca reordena). Verificado con Playwright real desplazando
      timestamps vía tinker MIENTRAS la página seguía abierta (sin recargar):
      9→10 entradas reveladas solo por el poll existente. `claim` sigue sin alterar
      la bitácora (10 entradas antes y después de reclamar); al volver a `idle` la
      sección se oculta. `/inventory` y `/play` verificados sin errores de consola,
      `tsc --noEmit` limpio, 38/38 tests backend intactos (no se tocó backend).
- [x] **Fase 8 — Economía, venta y crafting.** Completada y verificada: loop
      Expedición → Recursos → Crafting/Venta → Monedas. **Conflicto real detectado
      antes de implementar (consultado con el usuario, resuelto explícitamente):**
      F7 ya había sembrado 12 recursos genéricos `resource_*` (madera/hierba/piedra/
      etc.) para los mismos 3 destinos que F8 vuelve a definir con OTRAS claves
      (`wood`, `wild_herb`, `stone`...) — de no resolverse, el loot de expedición
      nunca habría alimentado el crafting real. Se optó por remapear: `PetSeeder`
      ahora entrega las 16 materias primas canónicas de F8 por destino, y
      `ItemSeeder` borra los 12 `resource_*` obsoletos al re-sembrar (con
      try/catch: 3 de ellos NO se pudieron borrar porque un usuario real
      preexistente —`tester@testing.cl`, no un usuario de prueba mío— todavía los
      tiene en su inventario; `restrictOnDelete()` los protegió solo, quedan como
      items inertes sin romper nada). **Esquema:** `characters.coins` (columna
      normal, la única moneda del juego, sección 10); `items` ganó
      `sell_value`/`icon`/`rarity` (icon = emoji placeholder en su propia columna,
      sección 27 — reemplazable después sin tocar lógica); nuevas `recipes` +
      `recipe_ingredients` (catálogo, mismo espíritu que `items`/
      `pet_destinations`). **Reutilización explícita (no se rehizo nada):**
      `InventoryGrantService` (ya existente desde F7) ganó `totalOwned()`/
      `consume()` como complemento de `grant()` -mismo criterio de stack/max_stack,
      así que ni `EconomyService::sell` ni `CraftingService::craft` duplican esa
      lógica-. **Seguridad:** venta y crafting solo reciben `item_key`/`quantity` y
      `recipe_key` respectivamente — precio e ingredientes SIEMPRE se resuelven de
      DB server-side (`ValidationException` con 422 si falta stock/material, nunca
      confía en `price`/`ingredients`/`output_item` si el cliente los manda, quedan
      simplemente ignorados por el `FormRequest`). Crafting valida `is_active`,
      `required_level` (gate real contra `character.level`) y `unlock_condition`
      (declarado para F9+, cualquier receta que lo traiga seteado queda bloqueada
      por defecto — ninguna receta de F8 lo usa). Todo dentro de transacciones;
      `consume()` usa `lockForUpdate()` como red de seguridad ante carreras.
      Endpoints nuevos: `POST /inventory/sell`, `GET /recipes`,
      `POST /crafting/craft`. **40 items + 24 recetas sembrados** exactamente como
      la fase los definió (`EconomyItemSeeder`, `RecipeSeeder`). Las 3
      espadas/hacha de equipamiento usan `subtype: "weapon"` -slot que F6 ya
      reconoce-, así que además de crafteables/vendibles ya son equipables sin
      cambios extra; el escudo se dejó sin `subtype` (ningún `EquipmentSlot`
      existente encaja bien con "escudo", mejor no forzar un mapeo falso).
      **Frontend:** `/crafting` nueva (Astro/HTML, mismo patrón que `/pet` e
      `/inventory` — catálogo + inventario ya cargado se cruzan en cliente, igual
      que `pet.astro` ya hacía con destinos+mascota), con búsqueda, tabs de
      categoría + "Disponibles", checklist de ingredientes con ✓/❌, y `unlock`
      correcto contra nivel/receta activa. `/inventory` ganó acción "Vender" con
      confirmación (stepper +/-, total calculado, Cancelar/Vender — nunca vende de
      un solo click). HUD: el contador de monedas (ya existía como mock desde
      Fase 4.1) pasa a ser real vía `ApiClient.getCharacter()`, y se actualiza en
      vivo con un evento `window` (`coins:changed`) que `/inventory` dispara tras
      vender — sin duplicar el fetch de coins en cada página. Nav ganó "Crafting"
      + ícono SVG nuevo (`Icon.astro`, mismo sistema vectorial de Fase 4.1B, no
      emoji — los emoji sí se usan a propósito dentro del contenido de items/
      recetas, sección 27 de la fase). Tests nuevos: `EconomyTest.php` (8) +
      `CraftingTest.php` (11), cubriendo venta/no-vender-ajeno/cantidad inválida/
      no-vendible/transaccionalidad, y crafting completo/materiales insuficientes/
      multi-ingrediente/no-ajeno/inputs-manipulados-ignorados/max_stack/
      transaccional-todo-o-nada/receta inactiva/nivel insuficiente/unlock_condition/
      receta inexistente. Verificado: 57/57 tests backend (estables en corridas
      repetidas), flujo real por Playwright (craftear "Tablones" con madera
      real → inventario reducido/tablones agregados confirmado en DB; vender 5
      Piedra → HUD pasa de 0 a 15 monedas EN VIVO sin recargar la página,
      confirmado con `waitForFunction`, no un timeout fijo), `/pet` y `/play` sin
      errores de consola, `tsc --noEmit` limpio, `GET /api/v1/ping` OK. **Arquitectura
      dejada preparada para F9/F10 (tienda) sin implementarla:** `items` ya
      distingue categorías (Resource/Consumable/Room/Equipment) y tiene
      `sell_value` — una futura tienda solo necesita un "precio de compra" nuevo
      (columna o tabla `shop_listings` aparte, no tocar `sell_value` que es
      unidireccional jugador→banco) y reusar `InventoryGrantService::grant()` tal
      cual para entregar lo comprado; `recipes.category` ya usa el mismo
      vocabulario (`house`/`equipment`) que tendría la tienda, y `coins` como
      columna en `Character` no necesita cambios para soportar gasto en la tienda.
      **No** se implementó: tienda/marketplace/trading/subastas, segunda moneda,
      colocación de muebles, árbol de recetas complejo, experiencia/niveles de
      crafting, ni ningún sistema de F9 en adelante.
- [x] **Fase 9 — Habitación personal jugable + muebles colocables.** Completada y
      verificada: primera versión funcional de `/house` ("Casa") con el personaje
      16x16 top-down caminando dentro de la habitación real diseñada en Tiled
      (`web/public/assets/world/room.tmx`, 32×24 tiles de 16px), con muebles
      dinámicos colocables/movibles/retirables desde el mismo canvas -sin página
      separada-. **Inspección previa (obligatoria antes de tocar nada):** el
      `.tmx` traía 5 tilesets (`Interior_Walls_01`, `Interior_Props_01`,
      `Workbench`, `Anvil`, y el `Furniture.png` de F5) con las mismas rutas
      `.tsx` externas rotas que `mapa_base.tmx` en Fase 5 -mismo conversor puntual
      `.tmx`→JSON, mismo método de verificación por conteo exacto de tiles
      (ancho×alto de cada PNG real ÷16 comparado contra el `tilecount` implícito
      por `firstgid`)-. De los 11 objetos de colisión que el `.tmx` ya traía
      dibujados, 4 (cofre, cama, velador, caja) fueron identificados como
      furniture **dinámico** -confirmado visualmente recortando y ampliando su
      región exacta en `Interior_Props_01.png` (608×384) antes de escribir
      ninguna metadata, nunca asumido por nombre- y sacados quirúrgicamente del
      `.tmx` (tiles CSV + objeto de colisión) para que nunca coexistan como
      "estático de fondo + dinámico encima" (riesgo explícito de la fase). Las
      paredes, el separador y el rincón de banco de trabajo/yunque (mesa de
      trabajo0/1) se decidieron **estáticos** (confirmado con el usuario vía
      pregunta directa) y quedaron intactos en el `.tmx`/`room.json`. Metadata de
      los 4 muebles dinámicos (`small_chest` -ya existía de F8, solo ganó
      `metadata_json`-, y `bed_frame`/`nightstand`/`wooden_crate` nuevos, agregados
      también por decisión confirmada con el usuario) vive en
      `items.metadata_json`: `tile_width`/`tile_height`, `collision` (un AABB en
      píxeles relativo al sprite, **no** todo el PNG -cada uno medido a ojo sobre
      el recorte real, no una dimensión genérica-), `placeable`, `rotatable`. El
      atajo pedido por la fase de "no fragmentar el PNG en archivos sueltos" se
      resolvió registrando frames irregulares (32×32 el cofre, 32×64 la cama,
      16×32 velador/caja -tamaños reales, no todos 16×16-) a mano vía
      `texture.add()` sobre el mismo `Interior_Props_01.png` cargado como imagen
      plana, ya que el `spritesheet` loader de Phaser exige frames del mismo
      tamaño. **Reutilización explícita (nada paralelo):** `rooms`/`room_items`
      -tablas y modelos que ya existían dormidos desde el Pre-flight- se usan tal
      cual, sin migración nueva; el inventario de muebles ES el inventario normal
      de F6/F8 (`InventoryGrantService`, ya existente) -colocar consume 1 unidad
      vía `consume()`, retirar la devuelve vía `grant()`, nunca se creó
      `RoomInventory`/`FurnitureInventory`-; `WorldMap`/`WorldPlayer` (Fase 5) se
      generalizaron con parámetros (`mapKey`, `namespace` de animación, tamaño de
      hitbox de pies) en vez de duplicarse -`RoomScene` los reusa con el sprite
      `housePlayer` (16x16 real, reservado desde F6) en vez del `worldPlayer`
      16x32 de `/play`, nunca se mezclan-. **Colisión dinámica:** cada
      `RoomFurniture` calcula su AABB de colisión a partir de su posición de tile
      actual + el offset de `metadata_json.collision`, recalculado en el mismo
      método que mueve el sprite (`applyPosition()`) -nunca puede quedar colisión
      "vieja" al mover un mueble, ambos cambian juntos-. **Modo edición** vive
      dentro del mismo canvas (`GameView.astro` ganó un `<slot/>` opcional para
      overlays HTML sobre el canvas, sin afectar `/play`): `RoomScene` emite
      eventos (`furniture-selected`, `place-requested`, `move-requested`) que
      `house.astro` escucha para dibujar sus propios paneles pixel-art y hacer las
      llamadas reales a la API -Phaser solo pinta el ghost/grid y decide si una
      posición **se ve** válida (verde/rojo), nunca si lo es de verdad-.
      **Seguridad server-authoritative real:** `RoomPlacementService` vuelve a
      validar TODO de cero en cada `place`/`move` -límites de habitación, contra
      `STATIC_COLLISIONS` (la misma geometría que ya traía el `.tmx`, no
      inventada), y contra AABB de cualquier otro mueble ya colocado-, todo dentro
      de transacciones (`place`/`remove` mueven inventario + `RoomItem` juntos,
      nunca uno sin el otro). Endpoints nuevos, todos bajo `auth:sanctum` y
      resolviendo el personaje desde el usuario autenticado (nunca un
      `character_id` del cliente): `GET /room`, `POST /room/objects`,
      `PATCH /room/objects/{id}`, `DELETE /room/objects/{id}` (estas dos últimas
      verifican `room_id` contra la habitación del usuario, 403 si no coincide).
      `RoomProvisioningService::ensureForCharacter` sigue el mismo patrón perezoso
      que `PetProvisioningService` (F7): cualquier personaje sin habitación la
      recibe la primera vez que se consulta. **Bug real encontrado y corregido
      por los tests (no a ojo), mismo patrón que el bug de Sanctum de fases
      anteriores:** `RoomProvisioningService` cacheaba `$character->room` como
      `null` (lazy-load de Eloquent) antes de crear la fila, y no actualizaba esa
      relación cacheada tras el `create()` -dentro de un mismo proceso de test
      (Laravel no reinicia el contenedor entre requests de un mismo test, a
      diferencia de producción) esto hacía que llamadas posteriores con el mismo
      objeto `$character` vieran `room` como `null` y devolvieran 403 donde
      correspondía 200/422-; se corrigió con `$character->setRelation('room',
      $room)` antes de retornar. **Segundo bug real encontrado por Playwright (no
      por los tests unitarios, que no cubren interacción de mouse):**
      `RoomScene.setEditMode()` llamaba `sprite.setInteractive(false)` para
      desactivar el furniture al salir de modo edición -Phaser no interpreta
      `false` como "desactivar", lo toma como un `hitArea` inválido y esa sprite
      queda rota para siempre (`input.hitAreaCallback is not a function` en
      cualquier evento de puntero futuro)-; further compuesto por un tercer bug
      relacionado: `refreshRoomObjects()` solo registraba el listener de
      `pointerdown` de cada mueble si `editMode` ya era `true` **en el momento en
      que corría**, pero esa función corre primero al cargar la escena -antes de
      que el usuario entre a modo edición-, así que el furniture cargado al
      abrir/recargar la página quedaba para siempre sin listener aunque después
      se activara "Editar habitación". Ambos se corrigieron juntos: el listener
      ahora se registra siempre (el propio handler chequea `editMode`/`ghost` en
      el momento del click) y desactivar interactividad usa
      `disableInteractive()`, nunca `setInteractive(false)`. Encontrados
      exactamente por seguir la metodología de este archivo -reproducir el flujo
      real en el navegador con Playwright, no confiar en que "compila" ni en los
      tests de backend, que no ejercitan clics sobre sprites-. **Depth/Z-order:**
      Y-sort simple (`setDepth` = borde inferior del sprite en píxeles) tanto
      para el jugador como para cada mueble -sin partir ningún sprite en partes,
      decisión explícita de no complicar esto para muebles simples de 1-2 tiles,
      tal como pedía la fase-. Tests nuevos: `RoomTest.php` (12), cubriendo
      obtener habitación propia/ajena, colocar item propio/ajeno/no-furniture,
      fuera de límites, sobre pared, overlap con otro mueble, mover
      propio/a-posición-inválida, eliminar-devuelve-a-inventario, y persistencia
      tras cerrar/reabrir. Verificado: 69/69 tests backend (estables en corridas
      repetidas), flujo E2E completo por Playwright con coordenadas de clic
      verificadas matemáticamente contra `RoomPlacementService::STATIC_COLLISIONS`
      (no adivinadas a ojo): colocar cofre en tile libre → confirmado en pantalla
      → recargar página → sigue ahí → seleccionarlo → moverlo → recargar → nueva
      posición persiste → seleccionarlo → retirarlo → inventario +1 confirmado por
      API. `/play`, `/inventory`, `/crafting`, `/pet` verificados sin errores de
      consola tras generalizar `WorldMap`/`WorldPlayer` (ningún cambio visual ni
      de comportamiento en `/play`), `tsc --noEmit` limpio. El único error de
      consola que aparece intermitentemente (`Cannot read properties of null
      (reading 'drawImage')` durante `BootScene`) es un flake pre-existente del
      entorno -reproducido igual en `/play`, que nunca se tocó de forma relevante
      en esta fase-, no una regresión de esta fase. **No** se implementó: tienda/
      marketplace/trading/decoración comprable/cosméticos/profesiones/crafting de
      muebles nuevo/multijugador en la habitación/visitas/WebSockets/muebles
      animados complejos -la arquitectura (metadata en `items.metadata_json` +
      grid de tiles + objetos dinámicos server-authoritative) queda preparada
      para agregar un mueble nuevo (p.ej. `royal_bed`) sin reescribir nada del
      sistema, solo sembrando el item con su metadata-.
- [x] **Fase 9.1 — Escalado de la habitación + crafting real de furniture.**
      Completada y verificada: ajuste puntual sobre Fase 9, sin rehacer nada de
      lo ya construido. **Escalado (sección 1-2 de la fase):** la habitación
      (512×384px lógicos, 32×24 tiles @16px, sin cambios) se veía chica en la
      esquina superior izquierda del canvas compartido de 900×700 que usa
      `/play` (que sí necesita ese tamaño porque el Mundo es grande y la
      cámara scrollea). Solución 100% vía el Scale Manager de Phaser, sin
      tocar tile size, geometría ni assets: `RoomScene.create()` llama
      `this.scale.resize(map.pixelWidth, map.pixelHeight)` (el canvas pasa a
      tener exactamente el tamaño lógico de la habitación) seguido de
      `this.scale.setZoom(2)` (el buffer de dibujo sigue en 512×384 -sin
      sub-píxeles, sin blur-, pero el CSS del canvas se duplica a 1024×768).
      Verificado en el código fuente de Phaser 3.90 (`ScaleManager.resize`/
      `setZoom`) antes de tocar nada: `CameraManager.onResize` reajusta sola
      la cámara principal al nuevo tamaño (estaba en 0,0 con el tamaño previo,
      condición que dispara el ajuste automático), y `InputManager` ya
      convierte coordenadas de puntero con `ScaleManager.transformX/Y`
      (`pageX - canvasLeft) * displayScale`), así que NINGUNA lógica de
      grid/ghost/colisión/click de `RoomScene` necesitó cambiar -confirmado
      con Playwright recalculando a mano las coordenadas de clic
      (`tile*16*zoom`) para todo el flujo colocar/mover-. Zoom entero (2x,
      no fraccionario) para evitar shimmer/jitter en el movimiento del
      jugador. `/play` no se tocó (usa su propio tamaño de siempre en cada
      carga de página nueva -cada página es un `Phaser.Game` nuevo, no hay
      estado compartido entre `/house` y `/play` que restaurar-).
      **Crafting real de furniture (secciones 3-14):** se analizó
      `Interior_Props_01.png` recorte a recorte (mismo método que Fase 9:
      `sharp` + `Read` para confirmar cada sprite antes de escribir
      metadata). De los 6 items de Casa sembrados en F8
      (`wooden_chair/simple_table/small_chest/lantern/decorative_pot/
      ancient_totem`), **3 tenían un sprite real correspondiente** que nunca
      se les había conectado (`metadata_json` era `null` desde que se
      sembraron, antes de que existiera el análisis del atlas de Fase 9):
      una silla con respaldo (16×32px), una mesa rectangular con refuerzos
      metálicos (48×32px, 3×2 tiles) y una maceta con flores (16×32px) -a
      los 3 se les agregó `metadata_json` (tile size + AABB de colisión
      medido sobre el recorte real, nunca genérico) en
      `RoomFurnitureSeeder` sin tocar su `name`/`icon`/`rarity`/`sell_value`
      (esos siguen siendo responsabilidad de `EconomyItemSeeder`, F8). Los
      otros 2 (`lantern`, `ancient_totem`) **no tienen un sprite real que
      los represente** en este atlas -se revisó el spritesheet completo; lo
      más parecido a "linterna" es un candelabro de techo y a "tótem" son
      cabezas de trofeo de lobo/oso, ninguno es honestamente el objeto que
      el nombre/ícono describen- así que sus recetas quedaron con
      `is_active = false` en `RecipeSeeder` (el `Item` se conserva intacto,
      nada se borra): `RecipeController::index` ya filtraba por
      `is_active`, así que desactivar la receta alcanza para que
      desaparezcan de `/crafting` sin tocar el frontend. `bed_frame`/
      `nightstand`/`wooden_crate` (F9, ya tenían metadata/colisión pero
      nunca habían tenido receta) ganaron una en `RecipeSeeder`, con costo
      de materiales proporcional a su `sell_value` real (caja 20 < velador
      35 < cama 80 → 2 tablones+1 cuerda < 3 tablones+1 cuerda < 8 tablones+
      2 tela reforzada+2 cuerda). Arquitectura data-driven confirmada
      (sección 10): agregar un mueble nuevo sigue siendo (1) sembrar el
      `Item`, (2) agregarle `metadata_json`, (3) sembrar su `Recipe` -sin
      ningún `if item === "x"` nuevo en ningún controller/service, todo
      sigue resolviéndose desde `items.metadata_json`/`recipe_ingredients`
      igual que antes de esta fase-. **Bug real encontrado por Playwright
      durante la verificación (no por los tests unitarios, que no capturan
      screenshots):** al confirmar un place/move exitoso, `house.astro`
      llamaba a `refreshRoom()` pero nunca destruía el sprite fantasma
      (`ghost`, alpha 0.6 con tinte verde de preview) -quedaba huérfano en
      la escena para siempre, superpuesto al mueble real, dando la
      impresión de un mueble con un tinte verdoso permanente-. Se corrigió
      llamando `scene.cancelGhost()` también en el camino exitoso de
      `place-requested`/`move-requested` (antes solo se llamaba en el catch
      de error). Investigado con instrumentación directa del estado del
      sprite en Phaser (no solo capturas de pantalla) para descartar una
      hipótesis intermedia equivocada -que el sprite real no renderizaba-
      antes de confirmar la causa real. Tests nuevos:
      `RoomFurnitureCraftingTest.php` (5), con una regla de invariante
      explícita que es el corazón de esta fase (`test_toda_receta_activa_
      de_casa_produce_furniture_realmente_colocable`: para TODA receta
      activa de categoría Casa, su item resultado debe tener
      `metadata_json.placeable = true` con `tile_width/tile_height/
      collision` -exactamente la regla que esta fase existe para
      garantizar-), más verificación de que `lantern`/`ancient_totem`
      quedan inactivas y fuera de `GET /recipes`, que `bed_frame`/
      `nightstand`/`wooden_crate` tienen receta activa, y dos flujos
      end-to-end completos (Craft → Inventory → Place) para `wooden_chair`
      y `nightstand`. Verificado: 74/74 tests backend (69 previos + 5
      nuevos), flujo real por Playwright completo (craftear silla en
      `/crafting` → `/house` con el canvas ahora en 1024×768 CSS/512×384
      lógicos → mover personaje → modo edición → colocar en tile libre →
      recargar → sigue ahí, sin tinte → seleccionar → mover → recargar →
      nueva posición persiste → retirar → inventario +1), pestaña "Casa" de
      `/crafting` verificada mostrando exactamente los 6 muebles reales
      (silla/mesa/cofre/maceta/velador/cama/caja) sin linterna ni tótem,
      `/play`/`/inventory`/`/crafting`/`/pet` sin errores de consola nuevos,
      `tsc --noEmit` limpio. El único error de consola observado
      (`Cannot read properties of null (reading 'drawImage')` en
      `BootScene`, una vez por carga de página) es el mismo flake
      pre-existente del entorno ya documentado en Fase 9, no una regresión.
      **No** se creó ningún asset/sprite nuevo (regla explícita de la
      fase), no se tocó `RoomPlacementService`, `InventoryGrantService`,
      el modelo de datos, ni ningún endpoint -todo el trabajo fue de
      escalado de presentación (Scale Manager) + datos de catálogo
      (seeders) + un bug de limpieza de sprite fantasma-.
- [x] **Fase 10 — Combate asíncrono MVP.** Completada y verificada: loop
      completo Arena → Atacar → Laravel resuelve todo el combate → Battle
      Scene reproduce → XP/monedas/cooldown. **Inspección previa (sección
      22 de la fase):** el Pre-flight ya había dejado `combat_logs`
      completo (`attacker/defender/winner_character_id` nullable+
      nullOnDelete, `seed`, `status`, `started_at/finished_at`,
      `events_json`) y `characters` ya tenía `level/exp/strength/agility/
      vitality/coins` sin usar desde que se crearon -esta es la primera
      vez que esas columnas tienen un propósito real-. Conclusión de la
      inspección: **cero migraciones nuevas** hicieron falta para toda la
      fase -ni tabla de cooldowns (se deriva del último `combat_log` entre
      ese par de personajes), ni columnas `wins`/`losses` (se derivan con
      `COUNT` agregado sobre `combat_logs`, evita que un contador se
      desincronice del historial real), ni columna de "stat_bonus" en
      `items` (ya existía `metadata_json`, igual que Fase 9.1 hizo con
      furniture). **Stats de combate (sección 7):** `CombatStatsService`
      deriva `max_hp/attack/defense/crit_chance/dodge_chance` de las
      columnas base (`strength/agility/vitality`) con una fórmula simple y
      documentada en el propio archivo (constantes nombradas, no números
      mágicos sueltos), más los bonus de equipamiento leídos
      genéricamente desde `items.metadata_json.stat_bonus` de cada pieza
      puesta -nunca `if item->key === "x"`-. `ArenaEquipmentSeeder` agrega
      ese `stat_bonus` a los 4 equipables que F8 ya había sembrado sin
      tocar su `name/icon/rarity/sell_value` (responsabilidad de
      `EconomyItemSeeder`), mismo patrón exacto que
      `RoomFurnitureSeeder::attachMetadataToExistingFurniture` en Fase
      9.1. `reinforced_wooden_shield` (F8) se había sembrado a propósito
      con `subtype: null` porque "ningún `EquipmentSlot` encajaba con
      escudo" -ahora que el equipamiento sí necesita modificar
      estadísticas de verdad, se agregó el caso `EquipmentSlot::Shield`
      (sin migración: es un enum de PHP sobre columna string) y el
      escudo pasó a ser equipable por primera vez. **Progresión (sección
      8):** XP requerida por nivel lineal (`nivel × 100`), subir de nivel
      sube `strength/agility/vitality` por igual (+2 cada una) -sin árbol
      de puntos de atributo todavía, tal como pedía la sección-.
      **Resolución del combate (sección 5/9/10):** `CombatService::attack`
      calcula TODO de una sola vez (mismo principio que
      `PetExpeditionService::start`, CLAUDE.md #6): valida cooldown/auto-
      ataque, resuelve stats finales de ambos personajes, corre
      `simulate()` (función pura, sin tocar DB, turnos alternados
      atacante-primero hasta 6 rondas = máximo 12 eventos
      attack/critical/dodge, sección 10: "6-12 eventos, nunca 50-100"), y
      recién ahí persiste todo dentro de una transacción (combat_log +
      xp/monedas de ambos personajes). Si nadie muere en 6 rondas, gana
      quien conserve mayor % de HP -desempate exacto a favor del
      atacante, documentado como decisión arbitraria mínima-. Los rolls
      usan `random_int()` sin sembrar el generador global a propósito
      -el `seed` guardado es solo un id de auditoría (la migración ya lo
      preveía como string genérico), no hace falta poder re-tirar los
      mismos dados porque el log completo ya queda persistido verbatim
      (CLAUDE.md #6 real: "guarda un log de eventos", no exige
      determinismo replayable)-. **Snapshot histórico (sección 18):** las
      stats de ambos personajes en el momento del combate quedan
      congeladas dentro de `events_json.attacker/defender.stats` -un
      combate viejo no cambia si el personaje sube de nivel o cambia de
      equipo después, verificado con test dedicado-. **Seguridad
      server-authoritative real (sección 4/6):** `AttackRequest` solo
      declara `defender_character_id` -cualquier otro campo que mande el
      cliente (`attacker_character_id`, `damage`, `winner`, `xp`,
      `coins`) ni siquiera está declarado, así que Laravel lo descarta
      solo, mismo patrón que `CraftRequest` (F8) con
      `ingredients`/`output_item`-. Endpoints nuevos, todos bajo
      `auth:sanctum` derivando siempre el atacante de
      `auth()->user()->character`: `GET /arena` (personaje + stats +
      ranking + oponentes con su cooldown ya resuelto en la misma
      respuesta), `POST /arena/attack`, `GET /arena/combats/{id}` (base
      mínima para "consultar combates anteriores", sección 15 -no se
      construyó una pantalla `/arena/history` completa, no hacía falta
      para el MVP-, protegido a que solo el atacante o el defensor
      puedan verlo). **Frontend:** `/arena` reemplaza su
      `PlaceholderView` -Astro/HTML puro para personaje/oponentes/ranking
      (mismo patrón que `/pet`/`/crafting`: `GamePanel` + `<script>`
      contra `ApiClient`, tabla de módulos de Fase 4 sigue vigente para
      esta parte), pero la PANTALLA DE COMBATE sí usa Phaser
      (`BattleScene` nueva, vía el mismo `GameView`/`startGame()`
      compartido que ya usan Mundo/Casa) -mismo split que Fase 9 (Room):
      Phaser dibuja sprites/HP bars/tweens, el log de texto del combate
      vive en HTML (mismo lenguaje visual que la bitácora de F7.1),
      comunicados por eventos de Phaser (`combat-event`/
      `combat-finished`) igual que `RoomScene` ya hacía-. `BattleScene`
      reutiliza `CharacterRenderer` (Fase 3) tal cual -el propio comentario
      de `WorldScene.ts` desde Fase 5 decía "el Player/CharacterRenderer
      sigue existiendo tal cual para Arena/combate/personalización", esta
      es la primera vez que se usa para eso-: cero sprites/animaciones de
      ataque nuevos (sección 25), los golpes se representan con un lunge
      corto + shake + daño flotante + tween de la barra de HP sobre el
      idle ya existente. `main.ts`/`PreloadScene` se generalizaron con un
      `sceneData` de paso libre (registry) para que `BattleScene` reciba
      atacante/defensor/eventos sin ensuciar `WorldScene`/`RoomScene`, y
      con `preloadCharacterLayersForMany()` (nuevo, en
      `CharacterRenderer.ts`) para precargar las apariencias de AMBOS
      combatientes sin encolar la misma textura dos veces cuando
      comparten capas (hoy todos comparten `body:base`, no hay cosméticos
      con sprite propio todavía). El ritmo del replay lo controla
      `BattleScene` con `time.delayedCall` (1.4s/evento) -Laravel nunca
      usa `sleep`/delays, sección 10-. Tests nuevos: `ArenaTest.php` (19),
      cubriendo autenticación/no-suplantación de atacante/no-autoataque/
      cooldown activo-y-expirado/cooldown-es-por-objetivo/daño dentro de
      rango esperado/crítico forzado/esquive forzado/ganador registrado/
      xp+monedas otorgados/equipamiento-modifica-stats/snapshot guardado/
      snapshot-no-cambia-retroactivamente/resultado-reproducible-sin-
      recalcular/ownership de `GET /arena/combats/{id}`/manipulación de
      payload ignorada/forma de `GET /arena`/defender inexistente.
      Verificado: 93/93 tests backend (74 previos + 19 nuevos, estables en
      corridas repetidas -el único fallo visto en una corrida fue
      `PetNarrativeTest` reproduciendo su propio flake probabilístico ya
      documentado en F7.1, no relacionado con esta fase, confirmado
      estable al re-correr-), flujo real por Playwright completo dos
      veces (una por cada personaje de prueba) sin ningún error de
      consola: `/arena` → stats/oponentes/ranking reales → ATACAR → Battle
      Scene con dos personajes enfrentados (uno espejado) → barras de HP
      bajando en vivo → log de texto progresivo → golpe crítico visible →
      pantalla de VICTORIA/DERROTA con recompensas → volver a la Arena →
      XP/monedas/ranking actualizados en vivo → objetivo recién atacado
      queda en cooldown (botón deshabilitado mostrando el conteo
      regresivo) → el OTRO oponente sigue disponible. `/play`,
      `/inventory`, `/crafting`, `/pet`, `/house` verificados sin errores
      de consola nuevos (el único que aparece en `/house`,
      `Cannot read properties of null (reading 'drawImage')` en
      `BootScene`, es el flake pre-existente del entorno ya documentado
      desde Fase 9), `tsc --noEmit` limpio. **No** se implementó: PvP en
      tiempo real/WebSockets/matchmaking complejo/habilidades o combos/
      estados o buffs permanentes/defensa manual/espectadores/clanes/
      torneos/ELO/pantalla `/arena/history` completa -la arquitectura
      (equipamiento vía `metadata_json.stat_bonus` genérico, ranking
      derivado de `combat_logs`, snapshot congelado por combate) queda
      preparada para todo eso sin romper lo hecho acá.
- [ ] Fase 11 — Pulido de MVP

MVP recortado recomendado (aprobado con matices): personaje → progresión →
mascota/exploración → recursos/crafting → habitación → combate PvP asíncrono es el
núcleo diferencial completo del Alpha jugable; el roadmap incremental fase por fase
sigue siendo la forma de llegar ahí, sin saltarse pasos.

---

## Fase 4 — Application Shell + HUD + Navegación (implementada, ver checklist arriba)

**Objetivo:** crear la estructura principal de la aplicación web que permite navegar
entre las distintas partes del juego. La aplicación **no** debe estar construida
completamente dentro de Phaser.

```text
ASTRO
│
├── Application Shell
├── Header / HUD
├── Navigation
├── Views / Pages
└── Game View
       └── Phaser
```

Astro es responsable de la aplicación web y su interfaz. Phaser es responsable
únicamente de las experiencias 2D interactivas que necesiten un motor de juego.

**HUD:** HTML/CSS/Astro, **nunca** dibujado dentro del canvas de Phaser. A futuro debe
poder mostrar logo, monedas/recursos y perfil. El diseño visual definitivo se decide
durante la Fase 4, esto solo fija el principio arquitectónico.

**Navegación:** módulos como Casa, Arena, Mascota, Mundo, Social — no todos usan
Phaser:

| Módulo       | Motor       |
|--------------|-------------|
| Casa         | Phaser      |
| Arena        | Phaser      |
| Mundo        | Phaser      |
| Mascota      | Astro/HTML  |
| Social       | Astro/HTML  |
| Ranking      | Astro/HTML  |
| Inventario   | Astro/HTML  |
| Perfil       | Astro/HTML  |

La arquitectura debe permitir que una vista web muestre una experiencia Phaser cuando
sea necesario, no al revés.

**Principio no negociable de esta fase:** Phaser **no** controla navegación global,
HUD global, perfil, inventario, ranking, chat, configuración ni autenticación — esos
sistemas pertenecen a la aplicación web (Astro).

Estructura conceptual futura (no crear todas estas páginas ahora, solo dejar la
arquitectura preparada):

```text
Application
├── HUD
├── Navigation
├── Home/Profile
├── House    → Phaser
├── Arena    → Phaser
├── Pet
├── World    → Phaser
├── Social
├── Inventory
└── Rankings
```

## Desviaciones ya acordadas respecto al documento maestro original

- Phaser vive dentro de `web/src/game` (consumido por la isla de Astro), no como
  paquete `/game` separado — para evitar monorepo con workspaces desde el día uno.
  Reversible si el juego crece mucho.
