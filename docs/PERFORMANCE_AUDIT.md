# Auditoría de rendimiento — Login / Chat / Arena

**Tipo de documento:** auditoría de solo-lectura. **Cero archivos de código
modificados** para producir este documento (cero refactors, cero cambios de
query, cero migraciones, cero cambios de configuración, cero commits/deploys).

**Fecha:** 2026-09-16. **Síntoma reportado:** en producción (Render), login
puede tardar 60+ segundos; abrir Chat y abrir Arena "tardan bastante"; una vez
cargado el juego funciona razonablemente bien.

**Método:** lectura directa de cada archivo involucrado en los 3 flujos
(controladores, servicios, modelos, migraciones/índices, middleware, config
de conexión, `ApiClient.ts`, páginas Astro), más lo ya documentado por el
propio equipo al migrar a Render+Neon (`docs/RENDER_DEPLOYMENT_ANALYSIS.md`).
Ninguna cifra de este documento es una medición en vivo contra el Render real
— eso queda en las secciones D/E como próximo paso explícito.

---

## Resumen ejecutivo (para no perderse en el detalle)

Hay **dos problemas de naturaleza completamente distinta** mezclados en el
síntoma reportado, y es importante no tratarlos como uno solo:

1. **LOGIN (60+ segundos) — el sospechoso principal NO es el código de Laravel, es la infraestructura.** `AuthController::login()` hace, como máximo, 2 queries triviales e indexadas (`users.email` es `unique()`, `personal_access_tokens` tiene su propio índice de Sanctum). No hay ningún bucle, ninguna llamada externa, ningún job. Que el endpoint MÁS liviano de toda la API pueda tardar 60+ segundos, mientras que "una vez cargado el juego funciona razonablemente bien", es exactamente la firma de un **cold start** — y el propio equipo ya documentó, antes de esta auditoría, que el plan es `plan: free` en Render (`render.yaml:18`), con cold-start de "~30-50s" ya anotado en `docs/RENDER_DEPLOYMENT_ANALYSIS.md`. A esto se le puede sumar el "despertar" del cómputo de Neon (Postgres serverless) si la base también estaba inactiva. Ver §1 y §A.

2. **CHAT y ARENA ("tardan bastante", ya con el contenedor despierto) — acá SÍ hay causas confirmadas en el código**, independientes del cold start:
   - `GET /v1/arena` ejecuta, en el peor caso, **cerca de 90-100 queries individuales** en una sola respuesta HTTP (N+1 de equipamiento por oponente + cooldown por oponente + `rankOf()`/`topRanking()` cada uno escaneando la tabla `characters` COMPLETA sin límite, dos veces, con una agregación sin índice sobre `combat_logs.winner_character_id`). Ver §3.
   - El chat, en cambio, está bien construido (eager load correcto, sin N+1, índices correctos) — su lentitud percibida es más probablemente compartida con el cold start / latencia de red hacia Neon que un bug propio. Ver §2.
   - El frontend de Arena además pide 2 endpoints **en serie** (`await getArena()` completo, después `await loadHistory()`) pudiendo ir en paralelo. Ver §4.

**No asumo que el problema es "Render" en el sentido de "es un tercero, no podemos hacer nada"** — la sección §3 muestra que hay trabajo real y evitable del lado de Laravel/Postgres, medible y arreglable, independiente de qué tan rápido sea el hosting.

---

## 1. LOGIN

### Endpoint
`POST /api/v1/auth/login`

### Archivo / controlador / método
[`AuthController::login()`](../backend/app/Http/Controllers/Auth/AuthController.php) (líneas 68-93).

### Flujo completo, sin omitir nada
```php
$data = $request->validate([...]);                          // en memoria, sin DB
$user = User::where('email', $data['email'])->first();      // Query 1
if (!$user || !Hash::check($data['password'], $user->password)) { ... } // CPU (bcrypt), sin DB
$token = $user->createToken('web')->plainTextToken;          // Query 2 (INSERT en personal_access_tokens)
return response()->json([...]);
```

### Consultas principales
1. `SELECT * FROM users WHERE email = ?` — `email` tiene `unique()` en la migración de `users` (línea 17 de `0001_01_01_000000_create_users_table.php`) → **usa índice**, coste trivial a cualquier escala razonable de usuarios.
2. `INSERT INTO personal_access_tokens (...)` — Sanctum, tabla con su propio índice sobre el hash del token (migración estándar del paquete, no tocada).

### Dependencias
Ninguna externa. No hay envío de email, no hay verificación en dos pasos, no hay llamada a un servicio de terceros. `Hash::check()` es CPU local (bcrypt, `BCRYPT_ROUNDS=12` en `.env.example` — coste típico de decenas de milisegundos, no segundos).

### Posibles puntos de bloqueo
- **Ninguno dentro del código de este método.** Es, literalmente, el endpoint más simple de toda la API (2 queries, sin relaciones, sin bucles).
- El único "trabajo" que puede tardar es **establecer la conexión a la base de datos** si es la primera query después de inactividad — ver más abajo.

### Qué podría explicar una espera de 60+ segundos
En orden de probabilidad, según evidencia:

1. **Cold start del web service de Render (plan Free).** Confirmado como plan real en `render.yaml:18` (`plan: free`). El propio equipo ya documentó en `docs/RENDER_DEPLOYMENT_ANALYSIS.md` (sección 10 y 13): *"el web service gratis de Render hace cold-start (~30-50s) tras 15 min de inactividad"*. Login/registro es, casi siempre, la PRIMERA acción autenticada que hace un visitante después de que la demo estuvo inactiva — es el candidato con más sentido para explicar por qué justo ESTE endpoint (el más liviano de todos) es el que se percibe más lento: no es lento el endpoint, es lento el contenedor despertando antes de poder atenderlo.
2. **Cold start / auto-suspend del cómputo de Neon (Postgres serverless).** Si la base también estuvo inactiva, la primera query real (`User::where('email')->first()`) puede ser la que dispara el "despertar" de Neon, sumándose al cold start del contenedor de Render. Ambos pueden solaparse (el contenedor despierta, y su primera query también tiene que esperar a que Neon esté listo).
3. **Sin timeout de conexión configurado hacia Postgres.** `config/database.php`, bloque `pgsql` (líneas 82-95): no hay ningún `options` con `PDO::ATTR_TIMEOUT` ni ningún `connect_timeout` en la connection string por defecto. Si por lo que sea el host de Neon tarda en responder al intento de conexión TCP/TLS (Neon despertando, o un problema de red puntual), PHP/libpq puede quedarse esperando bastante más de lo que un usuario toleraría, en vez de fallar rápido con un error claro. Esto no es la CAUSA de un cold start, pero si un cold start ocurre, **la ausencia de timeout hace que la espera sea silenciosa y sin límite superior conocido en vez de un error rápido**.
4. **Sin timeout en el frontend tampoco.** `ApiClient.ts::request()` (línea 35) hace un `fetch()` plano, sin `AbortController`/`signal`. Confirmado por búsqueda en todo el archivo: cero ocurrencias de `AbortController`/`timeout`. El usuario ve literalmente lo que tarde el backend en responder, sin un mensaje de "esto está tardando más de lo normal" ni un corte a los N segundos.

### Nivel de sospecha
- Cold start (Render + posible Neon): **ALTO** (ya documentado como comportamiento conocido del plan actual, encaja con el patrón exacto del síntoma: el endpoint más simple es el que "tarda más").
- Falta de timeout de conexión / de frontend: **ALTO** como *agravante* (no crea el problema, pero convierte un cold start esperable en una espera indefinida sin feedback).
- Algo lento en el código de `AuthController::login()`: **BAJO** (2 queries indexadas, sin bucles, sin dependencias externas — se puede prácticamente descartar por lectura de código).

### Cómo comprobarlo sin modificar comportamiento
- **Render:** revisar el dashboard → pestaña "Events"/"Metrics" del servicio `game-backend`, filtrando por el momento exacto del login lento — Render marca explícitamente cuándo un servicio Free "spun down"/"spinning up". Si el timestamp coincide, es cold start, no código.
- **Neon:** dashboard de Neon → "Monitoring"/"Operations" del proyecto — Neon muestra explícitamente eventos de "compute suspended"/"compute activated" y cuánto tardó cada activación.
- **Medición diferencial (sin tocar código):** golpear `GET /up` (health check de Laravel, ya existe, no toca DB) dos veces seguidas después de 20+ minutos de inactividad, cronometrando cada una. Si la primera tarda 30-60s y la segunda es instantánea, es 100% cold start del contenedor (no de la DB, `/up` no consulta Postgres). Después, repetir lo mismo contra `POST /auth/login` — si TAMBI�N la primera tarda mucho más que la segunda incluso con el contenedor ya despierto (por haber pegado primero a `/up`), la diferencia adicional es atribuible a Neon despertando en la primera query real.
- **Logs de Laravel:** con `LOG_CHANNEL=stderr` (ya configurado, `render.yaml:94`), revisar el visor de logs de Render alrededor del timestamp lento — si Laravel tarda en aparecer en los logs (el propio arranque de PHP/Apache), es cold start del contenedor, no una query lenta (una query lenta SÍ aparecería con el request ya loggeado, solo que demorado).

---

## 2. CHAT

### Endpoints
`GET /api/v1/chat/messages[?after_id=N]` (carga inicial y polling), `POST /api/v1/chat/messages` (enviar).

### Archivo / controlador / método
[`ChatController::index()`/`store()`](../backend/app/Http/Controllers/Api/ChatController.php).

### Consultas principales
```php
ChatMessage::query()->with('user:id,name')   // eager load explícito, correcto
    ->orderByDesc('id')->take(50)->get()->sortBy('id')->values();
```
- **2 queries totales, no más:** 1) `SELECT * FROM chat_messages ORDER BY id DESC LIMIT 50`, 2) `SELECT id, name FROM users WHERE id IN (...)` (batch del eager load, una sola query para todos los remitentes del lote — `with('user:id,name')` está bien usado, seleccionando solo 2 columnas).
- `id` es PK (índice automático) y se usa directamente para ordenar/paginar — no hace falta índice en `created_at` (correctamente, ya lo documenta el propio comentario de la migración, línea 24-25 de `create_chat_messages_table.php`).
- `user_id` está indexado (`$table->index('user_id')`, línea 27) aunque en este query puntual no se filtra por él — lo usa el eager load vía `whereIn('id', ...)` sobre `users`, que ya tiene PK.

### Dependencias
Ninguna. Sin colas, sin WebSockets (polling HTTP puro, documentado a propósito). `GlobalChat.astro` hace polling cada `POLL_INTERVAL_MS = 2500` (línea 78) — intervalo razonable, no agresivo.

### Posibles puntos de bloqueo
- **No until encontrados en el código de Chat.** Es, de los 3 flujos auditados, el mejor construido: eager load correcto (evita el N+1 que sí tiene Arena, ver §3), índices adecuados para el patrón de acceso real, sin agregaciones costosas.
- El único candidato real es **compartir la misma infraestructura lenta que Login/Arena**: si el usuario abre Chat justo después de un login que despertó el contenedor/Neon, la primera consulta de Chat puede todavía estar pagando esa misma ventana de "todavía no está todo caliente" (conexiones de Postgres, cachés de plan de consulta, etc.).
- **`throttle:api` (60/min) usa `CACHE_STORE=database`** (`.env.example:68`, `render.yaml:73-74`) — cada request a la API, Chat incluido, hace una lectura+escritura extra contra una tabla `cache` en la MISMA base Postgres para llevar la cuenta del rate limit. No es lento por sí solo, pero es una query adicional, siempre, en cada mensaje/poll — contribuye al conteo total de round-trips hacia Neon (relevante si el pooler agrega latencia por conexión, ver §5/E).

### Qué podría explicar una espera de 60+ segundos
Nada propio del código de Chat lo explica — 2 queries indexadas y baratas no producen minutos de espera. Si el usuario reporta que Chat específicamente tarda mucho, lo más probable (a falta de medición) es: (a) cold start todavía en efecto (mismo mecanismo que Login), o (b) confusión de percepción con Arena (que sí es pesado, ver §3) si ambos se prueban en la misma sesión.

### Nivel de sospecha
- Código de `ChatController`: **BAJO**. Bien construido, sin N+1, con los índices que necesita.
- Infraestructura compartida (cold start / latencia de pooler): **MEDIO** — mismo mecanismo que Login, pero Chat no tiene ningún agravante propio como sí tiene Arena.

### Cómo comprobarlo sin modificar comportamiento
- Repetir `GET /chat/messages` dos veces seguidas (sin recargar la página) cronometrando cada una — si la primera es notablemente más lenta que la segunda, es un tema de "primera conexión/plan de consulta todavía no cacheado", no del código.
- Activar temporalmente el log de queries de Laravel en un entorno de prueba (`DB::listen()` en `tinker`, o revisar el log de queries lentas de Neon, ver §E) para confirmar que en efecto son solo 2 queries por request — si aparecieran más de 2, sería una señal de que algo no leí bien y merece revisión adicional (no debería pasar según el código actual).

---

## 3. ARENA — el hallazgo más importante de esta auditoría

### Endpoint
`GET /api/v1/arena`

### Archivo / controlador / método
[`ArenaController::index()`](../backend/app/Http/Controllers/Api/ArenaController.php) (líneas 33-76), apoyado en [`ArenaRankingService`](../backend/app/Services/ArenaRankingService.php) y [`CombatStatsService::finalStats()`](../backend/app/Services/CombatStatsService.php).

### Consultas principales — conteo exacto, línea por línea

| Línea (`ArenaController::index`) | Qué hace | Queries |
|---|---|---|
| 41: `$this->ranking->statsFor([$character->id])` | wins/losses de MI personaje | **3** (ver tabla de abajo) |
| 43-46: `Character::where(...)->orderBy('level')->limit(20)->get()` | lista de oponentes | **1** — pero `level` **no tiene índice** (ver `create_characters_table.php`, solo hay índice implícito en `id` y `user_id`); a medida que crece `characters`, este `ORDER BY` sin índice se vuelve un sort completo de toda la tabla que matchea el `WHERE id != ?` (prácticamente toda la tabla). |
| 48: `$this->ranking->statsFor($opponents->pluck('id'))` | wins/losses de hasta 20 oponentes | **3** |
| 59: `$this->stats->finalStats($character)` (mi personaje) **+** 69 (dentro del `map` de `opponents`, ejecutado **una vez por cada uno de los hasta 20 oponentes**): `$this->stats->finalStats($opponent)` → cada una llama `$c->equipment()->with('inventoryItem.item')->get()` | **N+1 confirmado.** Cada llamada es una query fresca sobre la relación de ESE personaje puntual (no hay ningún `Character::with('equipment.inventoryItem.item')` cargado en lote antes del loop). Por personaje: 1 query a `character_equipment` + (si tiene algo equipado) hasta 2 más por el eager load interno (`inventory_items`, `items`). | **21 a 63** (1 self + 20 oponentes, × 1 a 3 queries cada uno) |
| 72 (dentro del `map` de `opponents`, una vez por oponente): `$this->combat->cooldownRemainingSeconds($character, $opponent)` → 1 `SELECT ... FROM combat_logs WHERE attacker_character_id=? AND defender_character_id=? ORDER BY created_at DESC LIMIT 1` | Un lookup independiente por cada oponente — no hay forma de batchearlo con el código actual. | **hasta 20** |
| 62: `$this->ranking->rankOf($character)` | `Character::query()->select('id','level')->get()` (**TODA la tabla, sin límite**) + `statsFor(todos los ids)` | 1 + **3** = **4**, y **escala con el total de personajes registrados** |
| 74: `$this->ranking->topRanking()` | `Character::query()->select('id','name','level')->get()` (**TODA la tabla otra vez, sin límite**) + `statsFor(todos los ids)` | 1 + **3** = **4**, y **también escala con el total de personajes**, de forma completamente independiente de `rankOf()` (no comparten resultado, se calculan dos veces por separado) |

**Total estimado para una sola respuesta `GET /arena`: entre ~57 y ~99 queries individuales**, de las cuales al menos 8 (`statsFor` × 4 llamadas × el `whereIn('winner_character_id', ...)` de cada una) **golpean una columna sin índice** (`combat_logs.winner_character_id` — la migración solo indexa `attacker_character_id` y `defender_character_id`, líneas 46-47 de `2026_09_13_000009_create_combat_logs_table.php`), y 2 de esas 8 (dentro de `rankOf()`/`topRanking()`) lo hacen sobre la lista de **TODOS los personajes de la base**, no solo los relevantes para esta pantalla.

Este no es un problema teórico: en la propia base de desarrollo compartida de este proyecto ya hay varios miles de personajes acumulados por pruebas automatizadas de fases anteriores (IDs de personaje ya por encima de 5800 al momento de esta auditoría) — `rankOf()`/`topRanking()` cargan y procesan esa tabla completa **en cada carga de Arena, de cualquier jugador**, sin caché ni límite.

### Dependencias
`CombatStatsService`, `CombatService::cooldownRemainingSeconds()`, `ArenaRankingService` (los 3 ya usados también por `POST /arena/attack` y `GET /ranking` — un cambio de rendimiento acá beneficiaría también a esos endpoints, ver `docs/COMBAT_SYSTEM_AUDIT.md` para el contexto de por qué `finalStats()`/`statsFor()` están escritos así).

### Posibles puntos de bloqueo
1. El N+1 de equipamiento (hasta 63 queries) — evitable con eager loading en lote.
2. `rankOf()` + `topRanking()` cargando la tabla `characters` completa DOS VECES, de forma independiente, en cada request — evitable compartiendo el cálculo, y sobre todo evitable estructuralmente (no debería requerir traer todas las filas a PHP para ordenar/buscar una posición).
3. La ausencia de índice en `combat_logs.winner_character_id`.
4. La ausencia de índice en `characters.level` (usado en `ORDER BY` para la lista de oponentes).

### Qué podría explicar una espera de 60+ segundos
Con una base de datos LOCAL (latencia de red ~0), 60-100 queries triviales normalmente se sienten en milisegundos-a-un-par-de-segundos, no minutos — así que esto **por sí solo probablemente no explica el "60+ segundos" reportado para Login** (ese es un problema de cold start, §1), pero **sí explica bien el "tarda bastante" reportado específicamente para Arena**, sobre todo si cada una de esas ~90 queries paga latencia de red hacia Neon a través del pooler (PgBouncer) en vez de una conexión local — a 20-40ms de latencia por round-trip (típico entre un servicio Render y una base Neon en otra infraestructura/región), 90 queries secuenciales ya son **1.8 a 3.6 segundos solo en latencia de red**, sin contar el tiempo de ejecución de cada query ni el trabajo de PHP/Eloquent armando ~20 objetos de stats y arrays por request. Si además coincide con un momento en que el pool de conexiones de Neon está bajo presión (otros procesos, u otro cold start parcial), el número puede crecer más.

### Nivel de sospecha
**ALTO** — a diferencia de Login (donde el código está limpio y el sospechoso es infraestructura), acá el problema está **confirmado por lectura directa del código**, no es una hipótesis: el conteo de queries de arriba se puede reproducir determinísticamente contando las líneas del archivo real.

### Cómo comprobarlo sin modificar comportamiento
- **`DB::listen()` en `tinker`** (no toca ningún archivo, es una sesión interactiva): `DB::listen(fn($q) => print($q->sql . "\n")); app(\App\Http\Controllers\Api\ArenaController::class)->index(request()->merge([...]));` con un usuario autenticado simulado, para contar las queries reales emitidas y confirmar el número exacto contra la estimación de la tabla de arriba.
- **Laravel Telescope o Clockwork** (si se quisiera instalar temporalmente en un entorno de prueba, NO en producción) — daría el conteo y el tiempo de cada query sin tocar el código de negocio.
- **`EXPLAIN ANALYZE`** en el SQL Editor de Neon sobre `SELECT winner_character_id, count(*) FROM combat_logs WHERE winner_character_id IN (...) GROUP BY winner_character_id` con la tabla real — confirmaría si Postgres efectivamente hace un Seq Scan (esperado, sin índice) en vez de un Index Scan.
- **Contar filas reales:** `SELECT count(*) FROM characters` y `SELECT count(*) FROM combat_logs` en producción — si son números grandes (como ya lo son en desarrollo), confirma que `rankOf()`/`topRanking()` están cargando esa cantidad de filas en cada request.

---

## 4. Arquitectura de `ApiClient.ts` y cómo el frontend hace las peticiones

### Estructura general
[`ApiClient.ts`](../web/src/game/net/ApiClient.ts) es el único punto de red del frontend (confirmado en todas las fases anteriores de este proyecto — ninguna página hace `fetch()` directo). Todas las funciones pasan por un único `request<T>()` (línea 35):

```ts
async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getToken();
  const headers = { Accept: "application/json", ... };
  const response = await fetch(`${API_BASE_URL}${path}`, { ...options, headers, cache: "no-store" });
  if (!response.ok) { throw new ApiError(...); }
  return response.json();
}
```

### Hallazgos

1. **Sin timeout de ningún tipo.** Cero `AbortController`, cero `signal`, cero `setTimeout` que aborte una request colgada (confirmado por búsqueda exhaustiva en el archivo). Cualquier endpoint que tarde 60+ segundos en el backend, el usuario lo ve tardar exactamente eso en el frontend, sin un mensaje intermedio de "esto está tardando" ni una forma de cancelar. Esto no CAUSA lentitud, pero es la razón por la que la lentitud del backend se percibe de forma tan cruda (una pantalla de carga colgada, en vez de un error rápido o un aviso).
2. **`cache: "no-store"` en todos los requests, siempre** (línea 48 del archivo, comentario propio: "el backend es la única fuente de verdad") — decisión correcta para la arquitectura del juego (evita datos stale de Coins/HP/etc.), pero significa que **no hay ningún nivel de caché HTTP del lado del navegador** que pueda absorber, aunque sea parcialmente, la lentitud de un endpoint como `GET /arena` si el jugador navega hacia y desde esa pantalla repetidamente.
3. **`arena.astro` pide 2 endpoints en serie, pudiendo ir en paralelo** (`refresh()`, líneas 399-411):
   ```ts
   latestState = await getArena();      // se espera completo...
   renderCharacter(latestState); renderOpponents(latestState); renderRanking(latestState);
   ...
   await loadHistory(true);             // ...antes de ni siquiera empezar este
   ```
   `getArena()` (pesado, §3) y `getCombatHistory()` (liviano, un solo `SELECT` con `OR` sobre dos columnas indexadas) son independientes entre sí — no hay ninguna razón de datos para esperar a que termine el primero antes de empezar el segundo. Ejecutados en paralelo (`Promise.all`), el tiempo total sería `max(getArena, loadHistory)` en vez de `getArena + loadHistory`.
4. **Login/Register no tienen este problema** — `login.astro` hace una sola llamada (`await login(...)`) y navega con `window.location.href` (línea 76), no hay una cadena de requests que paralelizar ahí. El "60+ segundos" de Login es 100% la única request, no una suma de varias.
5. **Chat** hace polling independiente cada 2.5s (`GlobalChat.astro`), no bloquea ni es bloqueado por ninguna otra request de la página — no se encontró un problema de secuenciación ahí.

### Nivel de sospecha (arquitectura de red del frontend)
- Falta de timeout: **MEDIO** (agrava la percepción, no la causa).
- Secuencia serial en `arena.astro`: **MEDIO** (contribuye una fracción real y medible al tiempo total de Arena, encima del problema de backend de §3, no en vez de él).

### Cómo comprobarlo sin modificar comportamiento
- Pestaña Network del navegador contra `/arena`: confirmar visualmente que la request a `arena/combats` empieza recién cuando la de `arena` termina (waterfall en serie, no en paralelo) — visible sin tocar ningún archivo.

---

## 5. Middleware, autenticación y configuración de conexión (aplica a los 3 flujos)

### Middleware ejecutado por request autenticado
1. `throttleApi()` (global, `bootstrap/app.php:22`) — usa el limiter `api` (`AppServiceProvider.php:33-35`, 60/min por usuario o IP) contra el store de cache configurado — **`CACHE_STORE=database`** (`.env.example:68`, `render.yaml:74`). Esto significa que **cada request a la API, sin excepción, hace al menos una lectura+escritura extra contra una tabla `cache` en la misma Postgres/Neon**, sumándose al conteo de round-trips de cualquier endpoint.
2. `auth:sanctum` (por grupo de rutas, `routes/api.php:50` y equivalentes) — resuelve el token Bearer contra `personal_access_tokens` (indexado por el propio paquete Sanctum) y After eso, cada controlador hace `$request->user()->character` (una query adicional, indexada por `user_id` único en `characters`).
3. Sanctum está configurado con `'guard' => ['web']` (`config/sanctum.php:40`) — el guard `web` es de sesión; como la app nunca manda cookies (confirmado en el propio comentario de `cors.php:29-34` y `.env.example:93-98`) y el grupo de rutas `api` de Laravel 11 no arranca `StartSession` por defecto (confirmado: `bootstrap/app.php` solo agrega `throttleApi()`, nada de `EncryptCookies`/`StartSession` a las rutas API), este guard debería fallar "gratis" sin tocar la sesión — pero no llegué a confirmar esto leyendo el código interno de Sanctum/Laravel línea por línea, así que lo dejo como **hipótesis de bajo riesgo a verificar** (§C), no como confirmado.

### Configuración de conexión a Postgres
`config/database.php`, bloque `pgsql` (líneas 82-95): **sin `options`, sin `PDO::ATTR_TIMEOUT`, sin `connect_timeout` en la URL por defecto**, `sslmode => 'prefer'` (intenta SSL, negocia si no — un round-trip extra de negociación TLS en el peor caso, no una causa de lentitud severa por sí sola). Confirmado que la app en producción usa el endpoint **pooled** de Neon (`-pooler`, `render.yaml:56-59`) para todo el tráfico normal — el equipo ya documentó (sección 14 de `docs/RENDER_DEPLOYMENT_ANALYSIS.md`) que ese mismo pooler, en modo transacción, causó un error real (`SQLSTATE[25P02]`) durante las migraciones por no soportar bien transacciones multi-statement. Eso ya está resuelto para migraciones (usan el endpoint directo aparte) — pero es una señal de que **el comportamiento del pooler con este proyecto ya mordió una vez**, y vale la pena no asumir que el tráfico normal (runtime) está libre de sorpresas similares sin medirlo.

### Jobs, colas, requests externos
Búsqueda exhaustiva en `backend/app/` de `dispatch(`, `Queue::`, `Http::`, `Mail::`, `Storage::`, `Bus::`, `ShouldQueue`: **cero resultados**. Se puede descartar con confianza alta que algo del backend esté esperando una cola que nadie procesa, o una llamada HTTP saliente a un tercero, o un envío de mail síncrono. `QUEUE_CONNECTION=database` está configurado pero no hay ningún `dispatch()` en el código — la infraestructura de colas existe pero no se usa, no es una fuente de bloqueo.

---

## A. Problemas confirmados (por lectura directa de código, no hipótesis)

1. **`GET /arena` ejecuta entre ~57 y ~99 queries individuales por request** (§3) — N+1 de equipamiento (hasta 63 queries evitables), más `rankOf()` y `topRanking()` cargando la tabla `characters` completa, cada uno por separado, sin límite ni caché.
2. **`combat_logs.winner_character_id` no tiene índice** — usado en `whereIn()` dentro de `ArenaRankingService::statsFor()`, llamada 4 veces por cada `GET /arena`.
3. **`characters.level` no tiene índice** — usado en el `ORDER BY` de la lista de oponentes.
4. **`ApiClient.ts` no tiene timeout de request en ningún punto** — cualquier lentitud de backend se percibe como una espera indefinida sin feedback.
5. **`arena.astro` pide `getArena()` y `getCombatHistory()` en serie**, pudiendo ir en paralelo.
6. **El plan de Render es Free** (`render.yaml:18`), con cold-start documentado (~30-50s) por el propio equipo — condición de infraestructura real, no hipotética, del entorno de producción actual.
7. **`config/database.php` (pgsql) no define ningún timeout de conexión** — confirmado por lectura directa, sin `options`/`PDO::ATTR_TIMEOUT`.
8. **No hay jobs, colas activas ni llamadas HTTP salientes en `backend/app/`** — se descarta esta categoría completa como causa.
9. **`CACHE_STORE=database`** — el rate limiter (aplicado a TODA la API) usa la misma base Postgres/Neon como store de cache, sumando queries extra a cada request, sin excepción.
10. **`ChatController` está bien construido** — eager load correcto, sin N+1, índices adecuados. Se descarta como fuente de un problema propio de rendimiento (dejar constancia explícita, ya que se pidió no asumir nada sin evidencia — la evidencia acá es "no hay problema").

## B. Problemas probables (evidencia fuerte, falta confirmar con medición en vivo)

1. **El cold start de Render (y posiblemente de Neon) es la causa dominante del "60+ segundos" en Login específicamente** — el código de `AuthController::login()` no lo explica por sí solo (2 queries indexadas), así que la explicación más consistente con "el endpoint más simple es el más lento" es infraestructura, no lógica de negocio.
2. **La latencia acumulada de ~90 queries secuenciales vía el pooler de Neon explica una parte relevante del "Arena tarda bastante"**, más allá del cold start puntual — aunque no tengo el número real de milisegundos por round-trip en producción para confirmar la magnitud exacta.
3. **Chat "tarda bastante" es más probablemente percepción compartida con el cold start de Login/Arena** que un problema propio — el código de Chat no muestra ninguna causa propia de lentitud.

## C. Hipótesis que necesitan medición (no descartadas, no confirmadas)

1. Si el guard `web` de Sanctum (`config/sanctum.php:40`) efectivamente NO toca el store de sesión en una request puramente Bearer, o si de alguna forma sí agrega overhead — no rastreé el código interno de Sanctum/Illuminate lo suficiente como para afirmarlo con certeza.
2. Si el `sslmode => 'prefer'` de la conexión pgsql agrega un round-trip de negociación medible, o si Neon fuerza SSL de entrada haciendo ese "prefer" irrelevante en la práctica.
3. Si el pooler de Neon (PgBouncer, modo transacción) agrega latencia propia por conexión más allá de la latencia de red pura — ya causó un problema de comportamiento (transacciones DDL) documentado; no está confirmado si también afecta la velocidad del tráfico normal de la app.
4. Cuánto pesa en la práctica cada uno de los ~90 queries de Arena en milisegundos reales contra Neon (vs. contra el MariaDB local, que es donde se escribió y probó todo el código hasta ahora) — la estimación de "1.8 a 3.6 segundos" en §3 es una cota basada en supuestos de latencia de red típica, no una medición.
5. Si existen otros endpoints con el mismo patrón de N+1 que Arena (ej. `POST /arena/attack`, que también llama `finalStats()` dos veces y `cooldownRemainingSeconds()`) — no se pidió auditarlos en detalle esta vez, pero comparten el mismo servicio subyacente.

## D. Qué medir en Render (próximo paso, no ejecutado en esta auditoría)

- Dashboard → Metrics del servicio `game-backend`: tiempo de respuesta p50/p95/p99 por endpoint, separando `GET /arena` de `POST /auth/login` de `GET /chat/messages`.
- Dashboard → Events: buscar marcas de "deploy"/"spin down"/"spin up" y correlacionarlas con los timestamps donde el usuario reportó lentitud.
- Logs (`LOG_CHANNEL=stderr`, ya configurado): timestamp del primer log de Laravel después de un período de inactividad, comparado con el timestamp real del request del usuario — la diferencia es tiempo de cold start puro, previo a que Laravel siquiera empiece a procesar.
- Repetir manualmente `GET /up` (no toca DB) vs. `POST /auth/login` (sí toca DB) después de 20+ minutos de inactividad, cronometrando ambos, para separar "cold start del contenedor" de "cold start/latencia de la DB".

## E. Qué medir en PostgreSQL / Neon (próximo paso, no ejecutado en esta auditoría)

- Neon dashboard → Monitoring: eventos de "compute suspended"/"activated" y su duración, correlacionados con los timestamps de lentitud reportada.
- `EXPLAIN ANALYZE` real (no solo lectura de código) sobre las 3 queries de `ArenaRankingService::statsFor()` contra los datos reales de producción, para confirmar Seq Scan vs Index Scan y el tiempo real de cada una.
- Activar temporalmente (en un entorno de prueba, no en producción sin coordinarlo) el log de "slow queries" de Postgres/Neon (`log_min_duration_statement`) para capturar, con datos reales, cuáles de las ~90 queries de Arena son efectivamente las más lentas.
- Confirmar en el dashboard de Neon si el plan actual es el free tier serverless con auto-suspend activo, y cuál es el "scale to zero delay" configurado (Neon permite ajustarlo) — un delay muy corto haría que la base se suspenda entre visitas normales de un usuario probando la demo, no solo tras largos períodos de inactividad.
- `SELECT count(*) FROM characters;` y `SELECT count(*) FROM combat_logs;` en producción, para dimensionar el costo real de `rankOf()`/`topRanking()` (§3) contra el volumen real de datos de producción (que puede ser muy distinto del volumen ya acumulado en la base de desarrollo compartida).

## F. Qué cambios recomendaría (SIN implementarlos en esta tarea)

En orden de impacto esperado / esfuerzo:

1. **Resolver el N+1 de equipamiento en `ArenaController::index()`**: cargar el equipamiento de "mi personaje" + los hasta 20 oponentes en una sola query batcheada (`Character::with('equipment.inventoryItem.item')`) antes del `map()`, en vez de que `finalStats()` dispare una query por personaje. Reduce el conteo de queries de esa parte de ~21-63 a ~3 (una por nivel de relación, batcheada).
2. **Evitar que `rankOf()` y `topRanking()` carguen la tabla `characters` completa, y evitar que se calculen dos veces por separado en el mismo request**: como mínimo, compartir un solo cálculo entre ambos dentro de `ArenaController::index()`; idealmente, resolver el ranking con agregación en SQL (`GROUP BY` + `ORDER BY` + `LIMIT` en la base, en vez de traer todo a PHP) para que deje de escalar linealmente con el total de personajes registrados.
3. **Agregar índice a `combat_logs.winner_character_id`** (migración nueva, aditiva, sin downtime) — beneficia a las 4 llamadas de `statsFor()` por request de Arena, y también a `GET /ranking`.
4. **Agregar índice a `characters.level`** (o un índice compuesto que cubra el patrón `WHERE id != ? ORDER BY level LIMIT 20`) — beneficia la carga de oponentes.
5. **Agregar un timeout explícito de conexión a Postgres** (`PDO::ATTR_TIMEOUT` u opción equivalente para pgsql/libpq) — no evita un cold start, pero convierte una espera indefinida en un error rápido y explicable, que además se puede mostrar como mensaje amigable en vez de una pantalla colgada.
6. **Agregar timeout + mensaje de "esto puede tardar la primera vez" en `ApiClient.ts`/pantallas de login** — mitigación de UX específicamente para el cold start conocido del plan Free, sin necesidad de cambiar de plan todavía.
7. **Paralelizar `getArena()`/`getCombatHistory()` en `arena.astro`** con `Promise.all()` — gratis en esfuerzo, reduce el tiempo total de carga de Arena en la magnitud del más rápido de los dos.
8. **Evaluar un store de cache/sesión/rate-limit que no sea la misma base de datos transaccional** (ej. si en algún momento se suma Redis) — reduciría la cantidad de round-trips hacia Neon en CADA request de la API, no solo en Arena. Es la recomendación de mayor esfuerzo/menor urgencia de la lista, y no es necesaria para resolver los problemas confirmados en A.1-A.3.
9. **Considerar subir el plan de Render (o configurar un "keep-alive" externo que pinguee `/up` periódicamente) si el cold start de 30-60s resulta inaceptable para la demo** — esto es una decisión de producto/costo, no técnica, y depende de qué tan seguido se espera que la demo esté inactiva 15+ minutos entre visitas.

Ningún ítem de esta lista fue implementado como parte de esta auditoría.
