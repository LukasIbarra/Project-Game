# Auditoría post-Fase 10 y Roadmap hacia Beta

> Documento de análisis y planificación. No implementa nada — es la base para decidir
> el orden de trabajo a partir de acá. Escrito inspeccionando el repositorio real
> (backend Laravel, frontend Astro/Phaser, `CLAUDE.md`) al cierre de la Fase 10.

---

## A. Estado actual — qué hay realmente construido

### Backend (Laravel 11 + Sanctum + MariaDB)

| Dominio | Modelos/tablas | Servicios | Endpoints | Estado |
|---|---|---|---|---|
| Auth | `User` | — | `/auth/register\|login\|logout\|me` | Funcional, sin verificación de email, sin recuperación de contraseña |
| Personaje | `Character` (level/exp/strength/agility/vitality/coins/appearance_json) | `CombatStatsService` (F10) | `GET /character` | Funcional; stats base recién adquirieron propósito real en F10 |
| Inventario/Equipo | `Item`, `InventoryItem`, `CharacterEquipment` | `InventoryGrantService` | `/items`, `/inventory*`, `/equipment*` | Funcional server-side; **sin reflejo visual** (ver D) |
| Mascota/AFK | `Pet`, `PetExpedition`, `PetDestination`, `PetNarrativeEvent` | `PetExpeditionService`, `PetProvisioningService` | `/pet*` | Completo, con bitácora narrativa (F7.1/7.1.1) |
| Economía/Crafting | `Recipe`, `RecipeIngredient` + columnas en `Item` | `CraftingService`, `EconomyService` | `/recipes`, `/crafting/craft`, `/inventory/sell` | Completo |
| Habitación | `Room`, `RoomItem` | `RoomProvisioningService`, `RoomPlacementService` | `/room*` | Completo, server-authoritative, con edición in-canvas |
| Combate | `CombatLog` | `CombatService`, `CombatStatsService`, `ArenaRankingService` | `/arena*` | Completo (MVP), sin PvP en tiempo real (por diseño) |

**93/93 tests backend pasan** (`php artisan test`), todos con `DatabaseTransactions` sobre
la DB de desarrollo real (nunca `RefreshDatabase`). Catálogo sembrado: ~44 items
(9 F6 + 35 F8), 30 recetas (24 F8 + 6 F9.1, 2 inactivas por falta de sprite real),
108 eventos narrativos, 3 destinos de mascota.

### Frontend (Astro + Tailwind v4 + Phaser 3)

| Página | Motor | Estado |
|---|---|---|
| `/` | — | **Scaffold default de Astro sin tocar** (`<h1>Astro</h1>`) |
| `/login`, `/register` | HTML plano | **Sin AppShell, sin theme.css, formularios sin estilo** (Fase 1, nunca revisitado) |
| `/home` | Astro/HTML | Funcional pero con datos 100% mock (`MOCK_PLAYER_PROGRESS`) |
| `/play` (Mundo) | Phaser (`WorldScene`) | Completo: mapa Tiled real, colisión, 1 NPC, 4 estructuras interactuables |
| `/house` (Casa) | Phaser (`RoomScene`) + overlay HTML | Completo: colocar/mover/retirar furniture, server-authoritative |
| `/pet` | Astro/HTML | Completo, con bitácora narrativa animada |
| `/inventory` | Astro/HTML | Completo (equipar/desequipar/vender), sin preview visual |
| `/crafting` | Astro/HTML | Completo, filtros por categoría, solo furniture real es colocable |
| `/arena` + Battle | Astro/HTML + Phaser (`BattleScene`) | Completo (MVP): stats, oponentes, ranking, replay de combate |
| `/social` | Astro/HTML | **`PlaceholderView`**, sin funcionalidad |
| `/ranking` | Astro/HTML | **`PlaceholderView`** — y ahora **redundante**: el ranking real ya vive en `/arena` |

Sistema de UI compartido: `AppShell` (HUD + Nav + Chat + `<slot/>`), `GamePanel`,
`GameButton`, `GameBadge`, `ProgressBar`, `ResourceCounter`, `Icon` (16 iconos SVG
propios, línea simple — no pixel-art), `GameView` (marco de Phaser + fondo ambiental).
Chat global (`GlobalChat.astro`) es **100% mock**, sin backend.

Sistema de personaje: `CharacterRenderer` (capas `body/hair/shirt/pants/shoes/weapon/
accessory`) existe desde Fase 3 y **recién se usó por primera vez en Fase 10**
(`BattleScene`). Hoy solo hay contenido real para la capa `body` (`base`) — ningún
item de cabello/ropa/arma tiene sprite propio en el manifest todavía, así que
equipar algo cambia `appearance_json` en DB pero **no cambia nada visualmente** en
ninguna pantalla.

---

## B. Sistemas completos (no volver a planificar)

Esto ya existe, funciona, tiene tests y fue verificado con Playwright. **No incluir
en ningún roadmap nuevo como "crear X"**:

- Autenticación por token Sanctum (Bearer, `localStorage`), guard de rutas en `AppShell`.
- Personaje base + stats columna (level/exp/strength/agility/vitality/coins).
- Inventario + equipamiento (grant/equip/unequip, slots incl. `shield` desde F10).
- Mundo social (`/play`): mapa Tiled real, colisión, NPC, estructuras.
- Mascota + expediciones AFK + bitácora narrativa (F7/7.1/7.1.1).
- Economía + crafting (venta, 30 recetas, categorías).
- Habitación personal + furniture colocable/movible/retirable, edición in-canvas.
- Combate asíncrono: Arena, cooldown por objetivo, ranking derivado, stats de
  combate con bonos de equipo, snapshot histórico, replay visual con `BattleScene`.
- Sistema de assets desacoplado (`manifest.json`), conversor `.tmx`→JSON para Tiled.
- Arquitectura data-driven de furniture/recetas/eventos (agregar contenido = sembrar
  datos, no tocar código).

## C. Sistemas funcionales pero provisionales (necesitan polish, no reconstrucción)

- **`/home`**: la estructura está bien, pero muestra `MOCK_PLAYER_PROGRESS` en vez de
  `GET /character` real — ya existe todo lo necesario para hacerlo real (F10 ya
  expone stats reales vía `/arena`, `/character` ya devuelve level/exp/coins).
- **`GlobalChat`**: UI ya construida y con buen lenguaje visual, pero sin backend.
  Decidir si entra a la beta (necesitaría un sistema mínimo real) o se oculta.
- **Iconografía**: coherente y minimalista, pero es line-art vectorial genérico, no
  pixel-art. Funciona como "placeholder de sistema", no como identidad final.
- **Ranking**: la lógica y los datos ya existen (`ArenaRankingService`), pero están
  encerrados dentro de `/arena`. `/ranking` sigue siendo un placeholder separado que
  ahora compite/duplica conceptualmente con eso.
- **HUD**: nombre y (parcialmente) monedas ya son reales; nivel/exp del HUD conviene
  verificar que lean de la misma fuente que `/arena` en vez de un mock separado.
- **Assets de mundo**: 1 solo NPC, 4 estructuras, un mapa. Funciona pero se siente
  poco poblado (esto es esperado en este punto, no es un bug).

## D. Gaps reales para una Beta (lo que falta de verdad)

1. **`/login` y `/register` sin ningún trabajo visual** — literalmente HTML sin
   clases, sin `AppShell`, sin manejo de estados de carga. Es la primera pantalla
   que ve cualquier tester externo. Esto es el gap más urgente y barato de cerrar.
2. **`/` (landing) es el scaffold default de Astro** — no hay página de entrada
   pública antes del login. Un tester que abre la URL raíz ve "Astro" / `<h1>Astro</h1>`.
3. **No hay creación/personalización de personaje.** El registro crea el Character
   con `appearance_json: {"body":"base"}` fijo, sin preguntar nada. No hay pantalla
   donde elegir ni un color. `CharacterRenderer` ya soporta capas, pero cero
   catálogo cosmético tiene sprite real todavía.
4. **No hay onboarding.** Un usuario nuevo llega a `/home` (con datos mock) y no hay
   ninguna guía de "andá a tu Casa", "conseguí tu mascota", "probá craftear".
5. **Cero infraestructura de despliegue.** Sin adapter de Astro (`output` es
   estático por defecto, lo cual en principio ALCANZA porque todo el renderizado es
   client-side vía `ApiClient`), sin `vercel.json`, sin Dockerfile/Procfile para el
   backend, sin CI. `FRONTEND_URL`/CORS ya son configurables por env pero nunca se
   configuraron para un dominio real.
6. **Sin verificación de email ni recuperación de contraseña** — aceptable para una
   beta cerrada con testers conocidos, pero hay que decidirlo explícitamente.
7. **`/social` y `/ranking` son placeholders visibles en la navegación** — un tester
   los va a clickear y encontrar "próximamente", lo cual está bien SI se comunica,
   pero hoy no hay ninguna señal de qué es MVP y qué no lo es.

## E. Deuda técnica relevante (solo la que importa antes de desplegar)

- `docs/documento-maestro-arquitectura.md`, referenciado desde `CLAUDE.md`, **no
  existe** en `/docs` (solo está `FASE_0_SETUP.md`). No bloquea nada técnico, pero
  es una referencia rota en la fuente de verdad del proyecto.
- `.env.example` del backend tiene un `APP_KEY` real committeado (en vez de vacío).
  Bajo riesgo (es un entorno local), pero mala práctica a corregir antes de
  publicar el repo o clonarlo para producción.
- Config de Sanctum "stateful" (cookies SPA) sigue con sus defaults de Laravel sin
  usarse — el proyecto entero usa Bearer tokens, nunca cookies. No rompe nada, es
  ruido de config sin efecto.
- El flake probabilístico ya documentado en `PetNarrativeTest` (rareza aleatoria,
  falla ~1 de cada N corridas) sigue ahí. No es urgente pero conviene una corrida
  con seed fija o un `retry` explícito antes de que se vuelva ruido en CI.
- El error intermitente de consola `Cannot read properties of null (reading
  'drawImage')` en `BootScene`, visto repetidas veces en Playwright desde Fase 9,
  nunca se investigó a fondo (se documentó como probable presión de WebGL del
  entorno de test). Vale la pena entenderlo antes de la beta, aunque sea benigno.

## F. Auditoría visual, pantalla por pantalla

| Pantalla | Estado actual | Problema principal | Prioridad | Recomendación |
|---|---|---|---|---|
| `/` | Scaffold Astro default | No existe landing | Alta | Construir landing mínima (logo/pitch/CTA a login-register) |
| `/login` | HTML sin estilo | Rompe la identidad visual desde el primer segundo | **Crítica** | Rehacer con `AppShell`-like/`GamePanel`, estados de error/carga |
| `/register` | HTML sin estilo | Igual que login | **Crítica** | Igual que login |
| `/home` | Astro/HTML, datos mock | Muestra números falsos junto a un juego con datos reales | Alta | Conectar a `/character` real; agregar CTA de onboarding |
| `/play` | Phaser, completo | Se siente algo vacío (1 NPC) | Media | Aceptable para beta; NPCs ambientales quedan para F11/F11.5 |
| `/house` | Phaser + overlay, completo | Ninguno funcional; falta pulir feedback de edición | Baja | Polish visual menor (sección G) |
| `/pet` | Astro/HTML, completo | Ninguno funcional | Baja | Ya tiene buen nivel de detalle (bitácora animada) |
| `/inventory` | Astro/HTML, completo | Equipar no cambia nada visualmente | Media | Depende de tener sprites cosméticos (F11) |
| `/crafting` | Astro/HTML, completo | Ninguno funcional | Baja | — |
| `/arena` | Astro/HTML + Phaser, completo | Resultado de combate queda "debajo del pliegue" (ya mitigado con scroll automático) | Baja | Pulido menor de layout |
| `/battle` | No es una ruta separada — es `BattleScene` embebida en `/arena` | — | — | Correcto por diseño, no crear una ruta nueva |
| `/social` | Placeholder | Visible en nav sin funcionalidad | Media | Decidir: ocultar de nav para beta, o versión mínima real |
| `/ranking` | Placeholder | Redundante con el ranking de `/arena` | Media | Fusionar/eliminar de nav, o convertir en vista ampliada del mismo ranking |

---

## G. Roadmap propuesto

La propuesta del prompt (F10.5 → F11 → F11.5 → F12) es fundamentalmente correcta.
La ajusto según lo que la auditoría encontró: el trabajo de **login/register/landing**
es tan barato y tan visible que conviene aislarlo como primer paso literal, y separo
"personaje/onboarding" de "visual polish" porque dependen de contenido distinto
(sprites cosméticos vs. sistema de diseño).

### F10.5 — Fundamentos de Beta: Auth, Landing y Onboarding

**Objetivo:** que un usuario nuevo pueda entrar, registrarse, entender qué está
viendo, y llegar a su primer "logro" (mascota + primera expedición, o primera
pieza de furniture) sin ayuda externa.

**Alcance:**
- Landing real en `/` (pitch corto + CTA a login/registro; no necesita ser
  elaborada, sí coherente con el lenguaje visual).
- `/login` y `/register` reconstruidos sobre `GamePanel`/`theme.css` — mismo nivel
  que `/pet` o `/crafting` hoy, con estados de error y carga reales.
- `/home` conectado a datos reales (`GET /character`, reusar lo que ya expone
  `/arena`) en vez de `MOCK_PLAYER_PROGRESS`.
- Onboarding mínimo: 3-4 pasos guiados post-registro ("Este es tu personaje",
  "Esta es tu mascota", "Esta es tu casa", "Andá a craftear/explorar") — puede ser
  tan simple como un modal de bienvenida con highlights de nav, no un tutorial
  interactivo complejo.
- Ocultar o marcar explícitamente `/social` y `/ranking` como "Próximamente" de
  forma consistente (ya existe `PlaceholderView` con esa semántica — usarlo bien,
  no dejarlos como error silencioso).

**Fuera de alcance:** verificación de email, recuperación de contraseña,
personalización visual de personaje (eso es F11), NPCs ambientales adicionales.

**Dependencias:** ninguna — es la fase más independiente del roadmap, no toca
backend de gameplay.

**Criterio de terminado:** un usuario que nunca vio el proyecto puede registrarse,
entender las 4-5 acciones principales disponibles, y llegar a `/house` o `/pet`
sin que nadie le explique nada por fuera de la UI.

### F11 — Identidad Visual + Personalización Mínima

**Objetivo:** que el juego se sienta como un solo producto con identidad propia, y
que el sistema de personalización (ya construido técnicamente) tenga contenido
real, aunque mínimo.

**Alcance:**
- Formalizar el sistema de componentes ya iniciado (`GamePanel`, `GameButton`, etc.)
  agregando los que falten (`GameItemCard`, `GameTab`, `GameModal`, `GameTooltip`)
  para dejar de repetir clases de Tailwind sueltas en cada página.
- Nameplates/ornamentos pixel-art mínimos (marcos con esquinas cortadas —
  `.game-panel--notched` ya existe, generalizar su uso donde tenga sentido).
- **2 peinados + 2 tops + 2 pantalones + 2 armas** con sprite real, cargados en el
  manifest bajo `hair/shirt/pants/weapon`, más `stat_bonus` donde corresponda
  (arma) — demuestra que `CharacterRenderer` + equipamiento + combate ya
  reaccionan a esto sin cambios de código, solo de datos (mismo patrón data-driven
  de F8/F9.1/F10).
- Reflejar visualmente el equipo puesto en `/inventory` (usar `CharacterRenderer`
  como preview, igual que ahora lo usa `BattleScene`).
- Reemplazar íconos de línea por versión pixel-art (o al menos un tratamiento
  visual más acorde — puede ser incremental, ícono por ícono).
- Auditar y unificar look-and-feel entre `/pet`, `/inventory`, `/crafting`,
  `/arena`, `/house` (mismos patrones de header/card/botón, mismos textos de
  estado vacío).

**Fuera de alcance:** decenas de items cosméticos, animaciones de ataque nuevas,
mundo poblado con NPCs adicionales (eso puede convivir con F10.5/F11 pero no es
bloqueante).

**Dependencias:** ninguna funcional; se apoya en el sistema de manifest/
`CharacterRenderer` que ya existe.

**Criterio de terminado:** las 8 pantallas principales comparten un lenguaje
visual reconocible sin excepciones evidentes; al menos un ítem de cada slot
cosmético relevante (pelo/top/pantalón/arma) se ve reflejado en el personaje.

### F11.5 — Despliegue de Producción/Beta

**Objetivo:** que personas externas puedan jugar desde internet, no solo en local.

**Alcance:**
- Elegir hosting concreto para Astro (Vercel es el candidato natural — el proyecto
  no necesita SSR, así que el build estático funciona sin adapter; confirmar con
  un build real) y para Laravel (cualquier hosting PHP+MySQL estándar, o
  contenedor).
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` real generado (no el
  committeado en `.env.example`), `FRONTEND_URL`/CORS apuntando al dominio real,
  HTTPS en ambos extremos.
- Revisar `SESSION_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION=database` funcionan
  igual en el hosting elegido (siguen sin necesitar Redis, por diseño).
- Seed de catálogo (items/recetas/eventos/destinos) reproducible en producción
  (`php artisan db:seed` ya es idempotente vía `updateOrCreate`, verificar que
  corra limpio contra una DB nueva).
- Definir estrategia de reset de beta (¿se resetean personajes entre rondas de
  testers, o es continuo?) — impacta si hace falta un comando `dev:reset`.
- Monitoreo mínimo de errores en producción (aunque sea logs, no hace falta un
  APM completo para una beta chica).

**Fuera de alcance:** CI/CD elaborado, staging environment separado, autoscaling,
CDN de assets (los PNGs actuales son livianos).

**Dependencias:** F10.5 y F11 idealmente completas antes (no tiene sentido
desplegar la versión sin pulir), aunque técnicamente esta fase es independiente.

**Criterio de terminado:** una persona fuera de la red local, con una cuenta
nueva, puede jugar el loop completo (registro → mundo → casa → mascota →
crafting → arena) desde una URL pública, sin errores de CORS/consola.

### F12 — Beta Feedback

**Objetivo:** observar, no programar.

Sin cambios respecto a la propuesta original del prompt: recolectar bugs, fricción
de onboarding, balance de combate/economía, qué sistemas se usan y cuáles no. La
salida de esta fase es un documento de hallazgos, no código. Recién ahí se decide
qué se convierte en F13+.

---

## H. Beta Definition of Done

La beta está lista para testers externos cuando **todo** esto es cierto:

1. `/` muestra una landing real (no el scaffold de Astro).
2. Login y registro tienen la misma identidad visual que el resto del juego, con
   manejo de errores legible.
3. `/home` muestra datos reales del personaje, no mocks.
4. Un usuario nuevo entiende, sin ayuda externa, al menos 3 acciones que puede
   tomar en sus primeros 5 minutos.
5. Al menos un slot de personalización (pelo, ropa o arma) tiene contenido real y
   visible en el personaje.
6. `/social` y `/ranking` no aparecen como errores confusos — están ocultos o
   claramente marcados como futuros.
7. El juego corre en una URL pública, servida por HTTPS, sin errores de CORS.
8. `php artisan test` (backend) y `tsc --noEmit` (frontend) pasan limpios contra
   la rama que se despliega.
9. Un playtest manual completo del loop principal (registro → mundo → casa →
   mascota → inventario → crafting → arena) no produce errores de consola nuevos.
10. Existe una forma de recibir feedback de los testers (aunque sea un canal de
    Discord/formulario externo — no hace falta construirlo dentro del juego).

## I. Riesgos (qué podría hacer perder tiempo si se ataca ahora)

- **Empezar por personalización visual completa (muchos items) antes que
  auth/landing**: los testers nunca llegan a apreciarlo si la primera pantalla ya
  los espanta. Orden importa.
- **Construir chat real o sistema social ahora**: no está pedido para la beta y
  compite en tiempo con lo que sí bloquea (F10.5/F11.5). El mock actual, ocultado
  u honesto sobre su estado, alcanza.
- **Elegir un hosting/arquitectura de despliegue compleja** (Kubernetes, colas,
  microservicios) cuando el proyecto explícitamente evita esa sobreingeniería
  (CLAUDE.md, stack fijo) — un hosting PHP+MySQL simple y un static host para
  Astro son suficientes y coherentes con el resto de decisiones del proyecto.
- **Invertir en balance de combate/economía antes del feedback real**: F10 ya es
  jugable; ajustar números sin datos de testers reales es prematuro (eso es
  literalmente el propósito de F12).
- **Dejar `/social`/`/ranking` como placeholders "invisibles" (sin decisión
  explícita)**: un tester que hace click y ve un placeholder sin contexto puede
  interpretarlo como un bug en vez de "todavía no existe". Es barato de resolver
  (ya existe el patrón `PlaceholderView`) y caro de ignorar (mala primera
  impresión).

## J. Recomendación final — próximo prompt

El siguiente prompt debería pedir **F10.5 completa**, en este orden interno:

1. Landing (`/`) + reconstrucción de `/login`/`/register` sobre el sistema de
   paneles existente (mismo patrón que `/pet`/`/crafting`: `GamePanel` + `<script>`
   contra `ApiClient`, sin frameworks nuevos).
2. `/home` conectado a datos reales.
3. Onboarding mínimo (modal o secuencia de 3-4 pasos post-registro).
4. Decisión + implementación sobre `/social` y `/ranking` (ocultar de nav vs.
   placeholder explícito vs. fusionar ranking con `/arena`).

Justificación: es la fase más barata, más independiente (no toca ningún sistema de
gameplay ya completo), y la que más impacta la primera impresión de un beta
tester — que es exactamente el problema que este documento identificó como más
urgente. F11 (personalización/identidad visual) y F11.5 (despliegue) dependen
mucho menos de secuencia estricta entre sí y pueden reordenarse según
disponibilidad, pero F10.5 debería ir primero en cualquier caso.
