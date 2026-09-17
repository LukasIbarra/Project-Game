    # Auditoría: Chat en tiempo real (Reverb + Broadcasting) — Etapa 1

    **Tipo de documento:** auditoría de solo-lectura. **Cero archivos modificados,
    cero paquetes instalados, cero servicios reiniciados, cero cambios en Nginx,
    firewall o `deploy.sh`** para producir este documento. Todo lo de abajo sale
    de leer el repo real (`LukasIbarra/Project-Game`, rama `master`, working tree
    limpio salvo los `docs/*.md` de auditorías anteriores) — nada fue asumido sin
    verificar el código primero.

    **Fecha:** 2026-09-17. **Objetivo de esta etapa:** diagnóstico completo del
    estado actual + plan técnico, SIN tocar nada, para poder aprobar antes de
    pasar a Etapa 2.

    **Nota de alcance importante:** puedo auditar con certeza todo lo que vive en
    el repositorio (Laravel, frontend, migraciones, composer.json, package.json).
    **No tengo acceso a la VPS** — todo lo referido a Nginx/systemd/puertos/RAM
    real está marcado explícitamente como "confirmado por código" o "pendiente de
    verificar con vos" (ver §4, §5 y el checklist final con los comandos exactos
    que necesito que corras y me pegues).

    ---

    ## Resumen ejecutivo

    | Pregunta | Respuesta |
    |---|---|
    | ¿Broadcasting está instalado? | **No.** `config/broadcasting.php` no existe, `routes/channels.php` no existe, `app/Events/` no existe (ni un solo evento en todo el proyecto), y `composer.json` no tiene `laravel/reverb`, `pusher/pusher-php-server` ni ningún paquete de broadcasting. Es una instalación 100% desde cero. |
    | ¿El chat está preparado para este cambio? | **Sí, sorprendentemente bien.** El propio código de `GlobalChat.astro` ya aísla "cómo llegan los mensajes nuevos" en una función (`createPollingSource()`) con forma `{start, stop}`, con un comentario explícito escrito para este momento: *"Reemplazar esa función por una que abra un WebSocket y llame el mismo callback es el único cambio que haría falta."* Esto reduce mucho el riesgo del lado frontend. |
    | ¿Por qué el 429? | Confirmado con evidencia exacta (§2): `GET /chat/messages` cae bajo el limiter global `api` (60/min por usuario, `AppServiceProvider.php`), compartido con TODO el resto de la API. Un solo cliente pollenado el chat cada 2.5s ya consume 24 de esas 60 solicitudes/minuto, dejando 36 para absolutamente todo lo demás que el juego haga en el mismo minuto (HUD, Arena, Inventario, etc.). No hace falta que el chat esté "roto" para agotar el límite — con 2-3 pestañas abiertas o un usuario navegando activamente, alcanza. |
    | ¿Puertos nuevos en el firewall? | **Ninguno, si se implementa como se recomienda acá** (§9): Reverb bindeado solo a `127.0.0.1`, Nginx hace el proxy de WebSocket sobre el 443 que YA está abierto. |
    | ¿Redis/Docker/Supervisor son necesarios? | **No, ninguno de los tres es estrictamente necesario** para el caso de uso actual (chat, ~2-10 usuarios simultáneos). Ver §6-§8 para el razonamiento completo. |
    | ¿Choca con algo ya documentado en el proyecto? | **Sí, una cosa a tener presente** (no bloqueante): `CLAUDE.md` línea 31 dice explícitamente *"Explícitamente NO usamos en el MVP: Redis, WebSockets, colas, scheduler activo"*, y `ROADMAP.md` línea 16 dice *"Sin WebSockets obligatorios en el Demo"*. Es una decisión de arquitectura ya escrita que este cambio está revisitando a propósito (coherente con lo que pediste) — cuando se implemente de verdad (Etapa 2+), esos dos documentos van a necesitar una actualización para no quedar contradictorios con el código real. Lo dejo anotado, no es algo que deba resolverse en esta auditoría. |

    ---

    ## 1. Laravel — estado actual de Broadcasting/Reverb

    ### Versión y paquetes (`backend/composer.json`)
    ```json
    "require": {
        "php": "^8.2",
        "laravel/framework": "^11.0",
        "laravel/sanctum": "^4.0",
        "laravel/tinker": "^2.9"
    }
    ```
    Laravel **11.x** confirmado (mismo dato ya documentado en auditorías previas de este proyecto). **No hay `laravel/reverb`, no hay `pusher/pusher-php-server`, no hay `beyondcode/laravel-websockets`, no hay ningún paquete de broadcasting.** Es el skeleton `laravel/laravel` sin tocar en este aspecto.

    ### `config/broadcasting.php`
    **No existe.** Confirmado por listado completo de `backend/config/` (14 archivos: `app`, `auth`, `cache`, `filesystems`, `logging`, `mail`, `queue`, `services`, `session`, `sanctum`, `database`, `pet_events`, `cors` — ninguno es `broadcasting.php`). Es el comportamiento normal de un Laravel 11 nuevo: ese archivo se publica recién cuando corrés `php artisan install:broadcasting` (o `broadcasting:install`), no viene por defecto desde la versión que quitó varios config files del skeleton.

    ### `routes/channels.php`
    **No existe**, por la misma razón — se crea junto con el comando de arriba.

    ### `bootstrap/app.php` (contenido real completo)
    ```php
    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
            api: __DIR__.'/../routes/api.php',
            commands: __DIR__.'/../routes/console.php',
            health: '/up',
        )
        ->withMiddleware(function (Middleware $middleware) {
            $middleware->throttleApi();
        })
        ->withExceptions(function (Exceptions $exceptions) {})
        ->create();
    ```
    No hay `channels:` en `withRouting()` ni `->withBroadcasting()` — confirmado que Laravel hoy no carga ningún archivo de canales (porque no existe ninguno para cargar).

    ### Eventos existentes (`app/Events/`)
    **El directorio no existe.** Cero eventos custom en todo el proyecto — coherente con lo ya confirmado en la auditoría de rendimiento anterior (`docs/PERFORMANCE_AUDIT.md`): no hay `dispatch()`, no hay `ShouldQueue`, no hay jobs. `QUEUE_CONNECTION=database` está en `.env`/`.env.example` pero no se usa para nada hoy.

    ### `.env` local (equivalente al de producción en cuanto a estas claves)
    ```env
    BROADCAST_CONNECTION=log
    QUEUE_CONNECTION=database
    CACHE_STORE=database
    SESSION_DRIVER=database
    ```
    `BROADCAST_CONNECTION=log` es el default de Laravel cuando no hay nada de broadcasting configurado (los eventos "broadcast" solo se escriben al log, no se transmiten a nadie) — confirma una vez más que hoy no hay ningún transporte realtime activo.

    ---

    ## 2. Chat — estado actual completo

    ### Modelo (`app/Models/ChatMessage.php`)
    ```php
    class ChatMessage extends Model
    {
        protected $fillable = ['user_id', 'message'];
        public function user(): BelongsTo { return $this->belongsTo(User::class); }
    }
    ```
    Sin timestamps de "actualizado" custom, `created_at` normal.

    ### Migración (`database/migrations/2026_09_17_000001_create_chat_messages_table.php`)
    ```php
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('message', 150);   // límite a nivel de columna, no solo de validación
    $table->timestamps();
    $table->index('user_id');
    ```
    `message` limitado a 150 caracteres a nivel de base de datos (no solo en el FormRequest) — relevante para el payload que vas a broadcastear, va a ser siempre chico.

    ### Controlador (`app/Http/Controllers/Api/ChatController.php`)
    ```php
    // GET /v1/chat/messages[?after_id=N]  — MAX_MESSAGES = 50
    public function index(Request $request) {
        $query = ChatMessage::query()->with('user:id,name');   // eager load correcto, sin N+1
        // ... after_id > 0 ? posteriores : últimos 50
        return response()->json($messages->map($this->present(...)));
    }

    // POST /v1/chat/messages { message }
    public function store(SendChatMessageRequest $request) {
        $message = ChatMessage::create(['user_id' => $request->user()->id, 'message' => ...]);
        $message->setRelation('user', $request->user());
        return response()->json($this->present($message), 201);
    }

    private function present(ChatMessage $message): array {
        return ['id' => ..., 'user_name' => $message->user->name, 'message' => ..., 'created_at' => ...];
    }
    ```
    El usuario SIEMPRE sale de `$request->user()` (Sanctum), nunca del payload — mismo principio que el resto de la API. `present()` es exactamente la forma que va a necesitar el payload del evento de broadcasting (§11) — se puede reusar tal cual.

    ### Rutas (`routes/api.php`, dentro del grupo `Route::prefix('v1')->middleware('auth:sanctum')`)
    ```php
    Route::get('/chat/messages', [ChatController::class, 'index']);
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/chat/messages', [ChatController::class, 'store']);
    });
    ```

    ### La causa exacta del 429 (confirmada, no una suposición)
    - `GET /chat/messages` **no tiene throttle propio** — cae en el limiter global `api`:
    ```php
    // AppServiceProvider.php
    RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
    ```
    60 requests/min **por usuario autenticado**, compartidas entre CHAT y absolutamente todo el resto de la API (Arena, Inventario, HUD, Activity Feed, etc. — todos caen bajo el mismo limiter `api` salvo login/register que tienen uno propio de `10,1`, y el POST de chat que tiene su propio `20,1`).
    - El polling actual (`POLL_INTERVAL_MS = 2500`, ver §3) genera **24 GET/min solo del chat**, dejando 36/min para todo lo demás. En una sesión normal jugando (HUD refrescando, navegando entre pantallas, Activity Feed polleando cada 10s, etc.) es totalmente esperable agotar el resto en poco tiempo.
    - Esto confirma que **el diagnóstico que ya tenías era correcto** — no hace falta re-derivarlo, solo quería dejar la evidencia exacta (archivo/línea) documentada acá para el resto del equipo.

    ---

    ## 3. Frontend — ubicación exacta del polling y arquitectura actual

    ### Dónde se carga el chat
    [`web/src/components/chat/GlobalChat.astro`](../web/src/components/chat/GlobalChat.astro) — componente único, montado una vez en el layout compartido (`AppShell.astro`, fuera del alcance de esta auditoría puntual pero confirmado en fases anteriores del proyecto).

    ### Dónde está el polling, literalmente
    Líneas 172-218 del mismo archivo:
    ```ts
    const POLL_INTERVAL_MS = 2500;   // línea 78

    function createPollingSource(onMessages: (messages: ChatMessageDto[]) => void) {
    let lastId = 0;
    let timer: ReturnType<typeof setInterval> | null = null;
    let stopped = false;

    async function tick() {
        const messages = await getChatMessages(lastId || undefined);
        if (messages.length > 0) {
        lastId = Math.max(lastId, ...messages.map((m) => m.id));
        onMessages(messages);
        }
    }

    return {
        async start() { await tick(); timer = setInterval(tick, POLL_INTERVAL_MS); },
        stop() { stopped = true; if (timer) clearInterval(timer); },
        notifyLocalMessage(message) { lastId = Math.max(lastId, message.id); onMessages([message]); },
    };
    }

    const source = createPollingSource(renderIncoming);
    source.start();
    ```

    ### Por qué esto es una buena noticia para el plan
    El propio comentario del archivo (líneas 6-15), escrito en una fase anterior del proyecto, ya anticipaba este momento exacto:

    > *"Diseño pensado para poder migrar a WebSockets después sin rehacer esto... toda la lógica de 'cómo llegan los mensajes nuevos' vive aislada en `createPollingSource()`, con la forma `{start, stop}`. El resto del componente (render, scroll, input) solo consume mensajes ya resueltos vía el callback `onMessages` — no sabe ni le importa que hoy vengan de polling. Reemplazar esa función por una que abra un WebSocket y llame el mismo callback es el único cambio que haría falta."*

    Esto es exactamente correcto y se mantiene válido hoy: **cambiar `createPollingSource()` por un `createWebSocketSource()` con la misma forma `{start, stop, notifyLocalMessage}` es, literalmente, el único cambio que necesita este componente.** `renderMessage()`/`renderIncoming()` ya dedupean por `id` (línea 126: `if (renderedIds.has(message.id)) return;`) — esto es relevante para el punto de abajo.

    ### Qué función llama a `GET /api/v1/chat/messages`
    `getChatMessages(afterId?: number)`, en [`ApiClient.ts`](../web/src/game/net/ApiClient.ts) (confirmado en auditoría de rendimiento previa, sección Chat): `return request(\`/api/v1/chat/messages${query}\`)`.

    ### Intervalo actual
    **2500ms (2.5 segundos)**, constante `POLL_INTERVAL_MS`, línea 78 de `GlobalChat.astro`.

    ### Qué componente mantiene el estado de mensajes
    El propio `GlobalChat.astro`, en memoria del cliente: `const renderedIds = new Set<number>()` (línea 115) para deduplicar, y el DOM (`<ul id="global-chat-messages">`) como única "fuente de verdad" visual — no hay un store/estado global separado (coherente con el resto del proyecto: sin librería de estado, todo vía `CustomEvent`s en `window` cuando hace falta compartir entre componentes, cosa que el chat no necesita).

    ### Cómo se obtiene/guarda el Bearer token
    [`ApiClient.ts`](../web/src/game/net/ApiClient.ts): `localStorage`, clave `"auth_token"`, con `export function getToken(): string | null { return localStorage.getItem(TOKEN_STORAGE_KEY); }` ya exportada y reutilizable — necesaria para autenticar la conexión WebSocket/canal privado (§11).

    ### Dependencias WebSocket ya instaladas
    **Ninguna.** `web/package.json` completo:
    ```json
    "dependencies": { "@tailwindcss/vite": "...", "astro": "...", "phaser": "...", "tailwindcss": "..." },
    "devDependencies": { "typescript": "..." }
    ```
    Cero `laravel-echo`, cero `pusher-js`, cero `socket.io-client`. Instalación desde cero también del lado frontend.

    ---

    ## 4. Nginx — lo que se pudo confirmar y lo que falta

    **No tengo acceso a la VPS**, así que esta sección es más corta de lo que
    sería ideal. Lo único que puedo confirmar por el contexto que diste:

    - HTTPS ya funciona vía Let's Encrypt/Certbot sobre `https://45.7.228.4.sslip.io`.
    - Nginx sirve únicamente `backend/public` (el document root de Laravel) — es la config típica de un solo `server{}` con PHP-FPM vía `fastcgi_pass`.
    - No hay, hoy, ningún `location` para WebSocket (no había razón para que existiera).

    **Lo que necesito para completar esta sección con certeza** (no asumido, pedido explícitamente, ver checklist al final): el contenido real de `/etc/nginx/sites-available/chibikko-api` (y confirmar cuál archivo está efectivamente en `sites-enabled/`). Con eso puedo decirte el bloque exacto a agregar (no antes — no quiero proponerte un diff a ciegas sobre una config que no vi).

    Lo que ya sé, en términos generales, que un proxy de WebSocket en Nginx necesita (para que sepas qué esperar, sin que esto sea todavía una instrucción de aplicar nada):
    ```nginx
    location /app/ {   # o el path que Reverb use, revisar REVERB_APP_ID/path
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 60s;   # las conexiones WS son de larga duración, el timeout default de nginx (60s) puede cortar conexiones idle si no se ajusta o si Reverb no manda pings
    }
    ```
    Esto es un patrón estándar (no específico de este proyecto) — lo incluyo acá como referencia de lo que se va a proponer en Etapa 2/3, **no como un cambio ya decidido** sobre tu config real, que todavía no vi.

    ---

    ## 5. VPS — lo que se pudo confirmar y lo que falta

    ### Confirmado (por lo que describiste + lo que audité en el repo)
    - Ubuntu 22.04.5 LTS, 1 vCPU, ~1.9GB RAM, 2GB swap, ~48GB disco.
    - SSH endurecido (puerto no estándar, sin root, sin password auth) — no relevante para Reverb, solo contexto.
    - UFW activo, solo 60678/80/443 — **ningún puerto de MariaDB ni de Reverb expuesto hoy**.
    - MariaDB en `127.0.0.1:3306` únicamente — correcto, no se toca.
    - No hay Redis, no hay Supervisor (confirmado por vos, no por mí — lo doy por válido).
    - No hay jobs `ShouldQueue` en el código (confirmado por mí, ver §1 y `docs/PERFORMANCE_AUDIT.md`) — así que no hay ninguna razón ligada a colas para necesitar Supervisor hoy.

    ### No puedo confirmar sin acceso (pedido en el checklist final)
    - Qué servicios systemd están corriendo ahora mismo y con qué nombre exacto (para no chocar nombres con el nuevo servicio de Reverb).
    - Si el puerto 8080 (default de Reverb) está libre.
    - Uso real de RAM/CPU en reposo hoy (para saber cuánto margen real hay antes de sumar un proceso más).
    - Versión exacta de PHP-FPM y si las extensiones que Reverb prefiere (`ext-sockets` es opcional pero recomendada; `pcntl` para manejo de señales) están disponibles.
    - Contenido EXACTO de `deploy.sh` (el que pegaste dice "aproximadamente" — antes de proponer un diff necesito el archivo real, byte a byte).

    ### Impacto esperado de Reverb en esta VPS (con la salvedad de que esto es lo que documenta oficialmente Laravel/la comunidad para Reverb, no una medición mía sobre tu hardware)
    Reverb es un servidor WebSocket **en PHP puro** (usa `react/socket` vía Composer, sin necesitar extensiones nativas obligatorias como Swoole). Para un puñado de conexiones concurrentes (tu escenario: 2-4 normal, ~10 en demo), el consumo de memoria en reposo suele ser de un puñado de decenas de MB, y el uso de CPU es prácticamente nulo entre mensajes (el modelo es basado en eventos, no polling interno) — a esa escala, 1 vCPU / 1.9GB compartidos con PHP-FPM + Nginx + MariaDB debería alcanzar sin ajustes especiales. **Esto hay que confirmarlo en vivo una vez instalado** (Etapa 2), no antes — lo anoto acá como expectativa razonable, no como garantía.

    ---

    ## 6. Arquitectura propuesta (adaptada a tus restricciones exactas)

    ```text
                            VERCEL (sin cambios)
                    Astro + Phaser + TS — https://chibikko.vercel.app
                                    │
                    ┌──────────────┴───────────────┐
                    │                               │
                HTTPS (443)                    WSS (443, mismo puerto)
                    │                               │
                    ▼                               ▼
            ┌──────────────────────── NGINX ────────────────────────┐
            │  location /  → fastcgi_pass a PHP-FPM (sin cambios)   │
            │  location /app/ (o el path elegido) → proxy_pass a    │
            │  127.0.0.1:8080 (Reverb), con headers Upgrade/Conn.   │
            └──────────────────────────┬──────────────────┬────────┘
                                        │                  │
                                PHP-FPM 8.3          Reverb (proceso
                                (Laravel API,        systemd propio,
                                sin cambios de        SOLO 127.0.0.1:8080,
                                autenticación)         nunca expuesto
                                        │               directo a Internet)
                                        ▼                  │
                                    MariaDB 10.6 ◄───────────┘
                                (127.0.0.1:3306,
                                sin cambios, nunca
                                expuesta)
    ```

    ### Por qué esta forma y no otra
    - **Un solo puerto público nuevo: cero.** WSS viaja sobre el mismo 443 que ya está abierto y con certificado válido — Nginx distingue por `location`/path, no por puerto. Reverb nunca necesita estar expuesto directamente a Internet ni el UFW necesita una regla nueva.
    - **Sin Docker:** no hay ninguna razón técnica para contenerizar un solo proceso PHP adicional en una VPS que ya corre todo nativo — agregaría complejidad de build/orquestación sin resolver nada que no resuelva ya un binario/artisan command + systemd.
    - **Sin Redis:** Reverb no lo requiere para correr en una sola instancia (Redis para Reverb solo hace falta si en el futuro corrés **múltiples instancias/procesos de Reverb detrás de un balanceador** y necesitás que se sincronicen entre sí — no es tu caso con 1 vCPU y una sola instancia). Tampoco lo necesita el broadcasting del evento en sí si se usa `ShouldBroadcastNow` en vez de `ShouldBroadcast` (ver §11) — evita también la necesidad de un queue worker.
    - **Sin Supervisor:** ya tenés systemd administrando Nginx/PHP-FPM/MariaDB — agregar UN servicio más a systemd (con `Restart=always`) es más consistente con lo que ya existe que instalar un segundo gestor de procesos (Supervisor) solo para este único proceso. Ver comparación en §8.

    ---

    ## 7. Paquetes que habría que instalar

    ### Backend (Composer)
    ```bash
    composer require laravel/reverb
    ```
    Trae Reverb + las dependencias de broadcasting que Laravel necesita (`pusher/pusher-php-server` **como dependencia interna del lado servidor**, porque Reverb implementa el protocolo compatible con Pusher — esto es estándar y documentado por Laravel, no es una integración con el servicio cloud de Pusher, es solo el nombre del protocolo/formato de mensajes que Reverb habla). Después, `php artisan install:broadcasting` (comando oficial de Laravel 11) hace el scaffolding: publica `config/broadcasting.php`, crea `routes/channels.php`, y agrega el registro correspondiente en `bootstrap/app.php`.

    ### Frontend (npm/pnpm, `web/`)
    ```bash
    npm install laravel-echo pusher-js
    ```
    **Aclaración importante porque el nombre puede confundir:** `pusher-js` acá es únicamente la librería CLIENTE que sabe hablar el protocolo WebSocket que Reverb expone (mensajes tipo `pusher:subscribe`, etc.) — **no se conecta a ningún servicio de Pusher.com, no necesita una cuenta de Pusher, no manda datos a ningún tercero.** Es el mismo patrón que documenta oficialmente Laravel para "Reverb + Echo": Echo es la capa de conveniencia (canales, `.listen()`, reconexión), y usa `pusher-js` por debajo porque Reverb fue diseñado a propósito para ser compatible con ese protocolo/cliente ya maduro, en vez de reinventar uno nuevo. Cero paquetes adicionales de terceros de verdad.

    ---

    ## 8. Procesos/servicios que habría que añadir

    **Uno solo: Reverb**, como proceso persistente (`php8.3 artisan reverb:start`). No es una request HTTP normal — es un proceso long-running que debe seguir vivo entre deploys y sobrevivir a reinicios de la VPS.

    ### systemd (recomendado) vs Supervisor

    | | systemd (recomendado) | Supervisor |
    |---|---|---|
    | Ya disponible en el server | Sí, gestiona Nginx/PHP-FPM/MariaDB hoy | No, habría que instalarlo (paquete nuevo) |
    | Arranque automático al bootear la VPS | Nativo (`enable`) | Nativo también, pero requiere que Supervisor mismo esté como servicio systemd |
    | Reinicio automático si el proceso muere | `Restart=always` | `autorestart=true` |
    | Curva para vos | Cero — mismo patrón que ya usás para `php8.3-fpm` | Una herramienta nueva a aprender/mantener |
    | Cuándo tendría sentido Supervisor en cambio | Si en el futuro necesitás correr MUCHOS procesos worker (ej. varios queue workers) y preferís administrarlos todos desde un panel propio | No es tu caso hoy (cero queue workers activos) |

    **Recomendación: una unit de systemd dedicada** (ej. `/etc/systemd/system/reverb.service`), algo con esta forma (propuesta para Etapa 2, no aplicada ahora):
    ```ini
    [Unit]
    Description=Laravel Reverb (Chibikko chat realtime)
    After=network.target mysql.service

    [Service]
    Type=simple
    User=deploy
    WorkingDirectory=/var/www/chibikko-api/backend
    ExecStart=/usr/bin/php8.3 artisan reverb:start --host=127.0.0.1 --port=8080
    Restart=always
    RestartSec=5

    [Install]
    WantedBy=multi-user.target
    ```
    (`User=deploy` para no correrlo como root, mismo usuario que ya usa el deploy — a confirmar contra cómo corre `php8.3-fpm` hoy una vez tenga esa info.)

    ---

    ## 9. Puertos que habría que abrir

    **Ninguno en UFW**, si se implementa como se propone: Reverb bindeado explícitamente a `127.0.0.1:8080` (nunca `0.0.0.0`), y Nginx haciendo `proxy_pass` desde el 443 ya abierto. El puerto 8080 nunca sale del propio server, así que no necesita regla de UFW — de la misma forma que MariaDB en `127.0.0.1:3306` no la necesita hoy.

    **Único paso pendiente de verificar (no de "abrir"):** confirmar que el 8080 no esté siendo usado ya por otra cosa en la VPS (comando en el checklist final). Si estuviera ocupado, se usa otro puerto local cualquiera — es una elección arbitraria mientras quede en loopback.

    ---

    ## 10. Cambios necesarios en frontend (detalle para Etapa 4, no aplicado ahora)

    1. `npm install laravel-echo pusher-js` en `web/`.
    2. Nuevas variables de entorno **con prefijo `PUBLIC_`** (Astro solo expone al cliente las que tienen ese prefijo, mismo patrón que `PUBLIC_API_URL` ya usa) — algo como `PUBLIC_REVERB_APP_KEY`, `PUBLIC_REVERB_HOST`, `PUBLIC_REVERB_PORT`, `PUBLIC_REVERB_SCHEME`. Se definen en Vercel (variables de entorno del proyecto), igual que `PUBLIC_API_URL` hoy.
    3. Un módulo nuevo (ej. `web/src/game/net/Realtime.ts`, al lado de `ApiClient.ts`) que inicialice Echo UNA sola vez, reutilizando `getToken()` ya exportado de `ApiClient.ts` para autenticar canales privados (ver §11 sobre el `authorizer` custom con Bearer).
    4. En `GlobalChat.astro`: reemplazar `createPollingSource()` por una función con la MISMA forma (`{start, stop, notifyLocalMessage}`) que en vez de `setInterval`, hace `Echo.private('chat').listen('.ChatMessageCreated', (e) => onMessages([e.message]))`. El resto del componente (render, dedupe por `renderedIds`, scroll, formulario de envío) **no cambia**.
    5. El `GET /chat/messages` inicial se mantiene (pediste explícitamente conservarlo para el historial) — el flujo queda: `start()` hace el `await tick()` inicial (ya existe, trae el historial) y en vez de armar un `setInterval`, se suscribe al canal.
    6. Mantener `notifyLocalMessage()` tal cual — ya dedupea por `id` (línea 126), así que si Reverb además te devuelve tu propio mensaje por el canal (dependiendo de cómo se configure el broadcast, ver §11), no se duplica visualmente.

    ## 11. Cambios necesarios en Laravel (detalle para Etapa 3, no aplicado ahora)

    1. `composer require laravel/reverb` + `php artisan install:broadcasting` (scaffolding oficial).
    2. Nuevo evento `App\Events\ChatMessageCreated`, implementando **`ShouldBroadcastNow`** (no `ShouldBroadcast`) — la diferencia importa para tu VPS: `ShouldBroadcast` encola el broadcast (necesitaría un queue worker corriendo, que hoy no existe y que pediste explícitamente no agregar sin necesidad clara); `ShouldBroadcastNow` lo emite de forma síncrona, dentro del mismo request de `POST /chat/messages`, sin tocar `QUEUE_CONNECTION` ni levantar un worker nuevo. Para un mensaje de chat (payload chico, `broadcastWith()` liviano) el costo síncrono es despreciable.
    3. `broadcastOn()` del evento: **canal privado** (`new PrivateChannel('chat')`), no público — ver justificación de seguridad en §14. `broadcastAs()`: un nombre explícito (ej. `'ChatMessageCreated'`) para que Echo pueda escuchar con `.listen('.ChatMessageCreated', ...)`. `broadcastWith()`: reusar la misma forma que ya arma `ChatController::present()` (evita mantener dos formatos del mismo mensaje en paralelo).
    4. `routes/channels.php`: autorización del canal `chat` — cualquier usuario autenticado por Sanctum puede escuchar (no hace falta lógica de pertenencia como en un chat privado 1-a-1, es el chat global). Algo como `Broadcast::channel('chat', fn ($user) => true);` una vez que `$user` se resuelva correctamente (punto siguiente).
    5. **El punto más delicado de todo el plan:** la ruta que Laravel expone para autorizar canales privados (`/broadcasting/auth`, registrada por el broadcasting installer) normalmente asume que el usuario ya está autenticado por sesión/cookies. Como este proyecto es 100% Bearer token (sin cookies, confirmado en auditorías previas y en tu propio mensaje), hay que:
    - Asegurar que esa ruta use el middleware `auth:sanctum` (Laravel permite configurar esto al registrar `Broadcast::routes(['middleware' => ['auth:sanctum']])`), en vez del default pensado para sesión.
    - Configurar el `authorizer` custom de Laravel Echo (Echo lo soporta explícitamente para este caso) para que la request de autorización del canal mande el header `Authorization: Bearer <token>` (leyendo `getToken()` de `ApiClient.ts`) en vez de depender de una cookie de sesión. Este es un patrón oficialmente soportado por Laravel/Echo para APIs Bearer-only — no es una solución improvisada, pero sí es el paso que más atención necesita en la implementación real, y donde vale la pena probar a fondo (Etapa 5: "usuario sin autenticación" del plan de pruebas que ya armaste apunta exactamente a esto).
    - **No hace falta introducir cookies/sesión** para resolver esto — el `authorizer` custom es la forma correcta de mantenerse 100% Bearer, tal como pediste.
    6. `ChatController::store()` gana una línea: `event(new ChatMessageCreated($message))` después de guardar (no reemplaza el `response()->json(...)` que ya devuelve al que envió — ese sigue siendo HTTP normal, tal como pediste mantener).
    7. Variables nuevas en `.env` (producción, VPS): `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http` (el HTTPS lo termina Nginx, Reverb habla HTTP plano puertas adentro del loopback). Todas son variables NUEVAS y aditivas — no tocan ninguna variable existente (`DB_*`, `SANCTUM_*`, etc. quedan intactas).

    ## 12. Cambios necesarios en `deploy.sh` (detalle, no aplicado ahora)

    El flujo actual (`git pull` → `composer install` → `migrate` → `config:cache`/`route:cache`/`view:cache` → `reload php-fpm`) **no se rompe ni se reemplaza** — se le suma un paso al final:
    ```bash
    sudo systemctl restart reverb
    ```
    Necesario porque Reverb es un proceso PHP de larga duración: igual que `php8.3-fpm` necesita un `reload` para que las próximas requests corran el código nuevo, Reverb necesita un `restart` para que el proceso ya corriendo (que tiene el código VIEJO cargado en memoria) empiece a usar el evento/canal actualizado. A diferencia de PHP-FPM (`reload`, sin cortar conexiones en curso), reiniciar Reverb sí corta brevemente las conexiones WebSocket abiertas — el cliente (Echo) debería reconectar solo (comportamiento default de Echo/pusher-js), y por eso el plan de pruebas que ya armaste incluye explícitamente "reinicio de Reverb" y "reconexión" como casos a probar en Etapa 5.

    También hay que confirmar que `config:cache` no rompa nada: como las variables `REVERB_*` van a existir en el `.env` real de producción (nunca en el repo, mismo criterio que `DB_*`/`APP_KEY` hoy), cachear la config es seguro siempre que esas variables ya estén cargadas en el `.env` del servidor ANTES del primer deploy con broadcasting — punto a verificar manualmente la primera vez, no algo que el script deba automatizar.

    ## 13. Estrategia de rollback

    El plan de etapas que ya armaste (backend primero, frontend después) es, en sí mismo, la estrategia de rollback más simple y segura:

    - **Etapa 3 (backend) desplegada sola, con el frontend todavía polleando:** agregar el evento/canal es un cambio 100% aditivo — `ChatController::store()` sigue devolviendo la misma respuesta HTTP de siempre, el polling del frontend sigue funcionando exactamente igual, nadie en producción nota nada distinto todavía. Si algo del lado de Reverb falla (el servicio no levanta, la config está mal), el peor caso es que el `event()` nuevo lance una excepción — hay que decidir en la implementación real si se envuelve en un `try/catch` silencioso para que un fallo de broadcasting NUNCA tire abajo el guardado del mensaje (el mensaje ya se guardó en DB antes del `event()`, así que el chat HTTP normal debe sobrevivir aunque Reverb esté caído). Esto se implementa en Etapa 3, se anota acá como requisito de diseño.
    - **Rollback de Etapa 3 si hiciera falta:** `git revert` del commit + re-correr `deploy.sh` (mismo mecanismo que ya usás hoy para cualquier revert) — no requiere ningún paso especial porque no se tocó nada existente, solo se sumó código nuevo.
    - **Etapa 4 (frontend) se despliega por separado, después de validar que Etapa 3 funciona sola** (ej. disparando el evento manualmente desde `tinker` y confirmando en las DevTools/Websocket inspector del navegador que algo llega). Vercel mantiene el historial completo de deploys — si el swap a WebSocket tuviera un problema, el rollback es un click en el dashboard de Vercel para volver al deploy anterior (que todavía tiene el polling), sin tocar el backend en absoluto.
    - **Nginx:** antes de aplicar el bloque WebSocket (Etapa 2), hacer una copia del archivo actual (`cp /etc/nginx/sites-available/chibikko-api /etc/nginx/sites-available/chibikko-api.bak-pre-reverb`) y validar con `nginx -t` antes de recargar — si algo fallara, restaurar el backup y `systemctl reload nginx` revierte en segundos. Esto ya lo tenías anotado en tus consideraciones, lo confirmo como el mecanismo correcto.
    - **UFW:** no se toca en ningún escenario de este plan (§9), así que no hay nada que revertir ahí.

    ---

    ## 14. Seguridad del canal — pública, privada o presence

    **Recomendación: canal privado (`PrivateChannel`), no público.**

    - El chat de este proyecto **ya requiere autenticación hoy** (`auth:sanctum` en ambas rutas, `GET` y `POST`) — no hay ningún flujo actual donde un visitante anónimo vea mensajes. Usar un canal público para el WebSocket (que no valida nada) bajaría el nivel de seguridad respecto al HTTP actual, algo que no tiene sentido introducir en esta migración.
    - Un canal privado obliga a que CUALQUIER cliente que quiera escuchar pase primero por la autorización (`/broadcasting/auth` con Bearer, ver §11) — mismo principio que ya rige el resto de la API (CLAUDE.md #1: el servidor nunca confía en el cliente sin verificar).
    - **No hace falta un `PresenceChannel`** todavía para el chat en sí (presence agrega la lista de "quién está conectado ahora mismo", que es exactamente el objetivo FUTURO que mencionás para jugadores online — no algo que el chat necesite hoy). Vale la pena adoptar `PresenceChannel` recién cuando se aborde el objetivo de "jugadores en línea" (tu Etapa 6), reusando la misma infraestructura de Reverb ya validada con el chat — no antes, tal como pediste no adelantar movimiento/presencia todavía.

    ---

    ## 15. Nota aparte (no bloqueante): `render.yaml` en el repo

    Encontrado en la raíz del repo (`render.yaml`) un Blueprint de un despliegue anterior a Render+Neon que, por lo que describís ahora (VPS propia con Nginx/MariaDB), ya no es la estrategia de producción vigente. No lo toqué (no se pidió, y no es parte de esta auditoría) — lo anoto para que quede registrado que es un artefacto de una decisión de infraestructura anterior, probablemente candidato a eliminarse en una futura limpieza, sin relación con el trabajo de Reverb.

    ---

    ## Checklist — qué necesito de vos para cerrar el 100% de esta auditoría

    Nada de esto modifica el servidor, son todos comandos de lectura:

    ```bash
    # Nginx — config real (para proponer el bloque WebSocket exacto en Etapa 2)
    cat /etc/nginx/sites-available/chibikko-api
    ls -la /etc/nginx/sites-enabled/

    # Puertos en uso (confirmar que 8080 está libre, y confirmar 3306/mariadb solo local)
    sudo ss -tlnp

    # Servicios corriendo (nombres exactos, para no chocar con el nuevo "reverb.service")
    systemctl list-units --type=service --state=running | grep -E "nginx|php|maria|mysql"

    # RAM/CPU disponibles ahora mismo (línea base antes de sumar Reverb)
    free -h
    nproc

    # Confirmar ausencia de Supervisor (ya lo dijiste, esto solo lo verifica)
    dpkg -l | grep -i supervisor || echo "no instalado"

    # PHP y extensiones relevantes para Reverb (sockets/pcntl no son obligatorias pero sí recomendadas)
    php8.3 -v
    php8.3 -m | grep -iE "sockets|pcntl|posix"

    # UFW, tal cual está hoy (confirmar contra lo que describiste)
    sudo ufw status verbose

    # El deploy.sh REAL, byte a byte (el que pegaste dice "aproximadamente")
    cat /var/www/chibikko-api/deploy.sh

    # Disco disponible (contexto, no crítico)
    df -h /
    ```

    Con esos resultados puedo completar con certeza el bloque de Nginx propuesto (§4) y confirmar que no hay ningún choque de puertos/servicios antes de escribir la unit de systemd definitiva — recién ahí tendría sentido pasar a Etapa 2.

    **No instalé nada. No modifiqué Nginx. No modifiqué el firewall. No ejecuté deploy. No reinicié ningún servicio.**

    ---

    # Etapa 2 — cerrada y validada

    Patch mínimo aplicado a `/etc/nginx/sites-available/chibikko-api` (bloque
    `location ^~ /app/` → `proxy_pass http://127.0.0.1:8080`, sin las 3
    directivas de timeout/buffering, removidas a pedido). Confirmado por vos:
    backup creado, `patch --dry-run` sin errores, patch aplicado, `nginx -t`
    exitoso, reload exitoso, `/api/v1/ping` sigue en 200, `/app/` devuelve 502
    (esperado, Reverb todavía no existe), puerto 8080 no abierto en UFW.

    ---

    # Etapa 3 — Reverb + Broadcasting en Laravel (auditoría + plan, NADA APLICADO)

    **Estado del repo re-verificado antes de escribir esto** (no se asumió que
    seguía igual): `composer.json` sin `laravel/reverb`, `config/` sin
    `broadcasting.php`, `app/Events/` y `routes/channels.php` siguen sin
    existir. Nada cambió desde la Etapa 1 — confirmado por lectura directa,
    no por memoria de la conversación.

    ## Verificación de versiones (con fuente, no de memoria)

    - **`laravel/reverb` v1.11.1** (última estable) — compatible con Illuminate
      `^11.0` y PHP `^8.2`. `composer require laravel/reverb` la resuelve sola.
    - **`laravel-echo` — la doc oficial dice explícitamente: "The Laravel Echo
      `reverb` broadcaster requires laravel-echo v1.16.0+."** `npm install`
      sin pin va a traer una versión más nueva que eso, cumple sin más.
    - Fuentes: [Laravel Reverb — 11.x](https://laravel.com/docs/11.x/reverb),
      [Laravel Broadcasting — 11.x](https://laravel.com/docs/11.x/broadcasting),
      [Laravel Sanctum — 11.x, sección "Authorizing Private Broadcast
      Channels"](https://laravel.com/docs/11.x/sanctum#authorizing-private-broadcast-channels)
      (Packagist para el número de versión de Reverb).

    ## El punto delicado, ahora resuelto con la fuente oficial exacta

    La doc de Broadcasting dice, textual: *"When broadcasting is enabled,
    Laravel automatically registers the `/broadcasting/auth` route... The
    `/broadcasting/auth` route is automatically placed within the `web`
    middleware group."* — confirma lo que sospechaba: el default NO sirve para
    un proyecto 100% Bearer sin cookies como este.

    La solución no es una improvisación mía — está en la doc de **Sanctum**
    (no en la de Broadcasting), sección "Authorizing Private Broadcast
    Channels", verbatim:

    ```php
    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
            // ...
        )
        ->withBroadcasting(
            __DIR__.'/../routes/channels.php',
            ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
        )
    ```

    `withBroadcasting()` reemplaza el registro default de `/broadcasting/auth`
    (que usa el grupo `web`) por uno que corre bajo `auth:sanctum` — el MISMO
    guard que ya protege `/chat/messages` y todo el resto de la API. Confirmado
    también: *"Sanctum will first attempt to authenticate... using a session
    cookie. If that cookie is not present then Sanctum will... authenticate
    the request using a token in the request's `Authorization` header."* — un
    request 100% Bearer sin cookie (nuestro caso, siempre) autentica igual,
    sin necesitar `statefulApi()`, `SANCTUM_STATEFUL_DOMAINS` ni CSRF — nada de
    eso se toca ni hace falta.

    **Adaptación al prefijo de este proyecto:** el ejemplo oficial usa
    `'prefix' => 'api'` (deja `/api/broadcasting/auth`). Este proyecto ya versiona
    todo bajo `/api/v1/...` — propongo `'prefix' => 'api/v1'` para que el
    endpoint quede en `/api/v1/broadcasting/auth`, consistente con el resto.
    Es una preferencia de nombre, no algo que la doc exija — decime si preferís
    mantener el default sin `/v1`.

    El authorizer del lado cliente que muestra esa misma sección de Sanctum usa
    `axios` + cookies (es el ejemplo para SPA-con-cookies) — **no lo copio tal
    cual**, lo adapto para Bearer en el diseño de `Realtime.ts` más abajo,
    dejando explícito qué cambia y por qué.

    **Nginx no necesita ningún cambio adicional para esto:** `/api/v1/broadcasting/auth`
    es una ruta de Laravel, no de Reverb — cae en el `location /` → PHP-FPM que
    ya existe y ya se validó en Etapa 2. El único `location` nuevo que hizo
    falta es `/app/` (el WebSocket en sí), ya aplicado.

    ## Diseño para evitar el eco duplicado del propio mensaje

    Laravel ofrece `broadcast(...)->toOthers()` para esto, pero requiere mandar
    un header `X-Socket-ID` en el POST — mecanismo pensado para un cliente
    HTTP tipo Axios global (auto-adjunta el header). Este proyecto usa `fetch()`
    a mano vía `ApiClient.ts::request()`, sin Axios — adoptar `toOthers()`
    significaría sumar plomería nueva (exponer `Echo.socketId()`, mandarlo en
    cada POST de chat, leerlo en el evento) para resolver algo que **el código
    ya resuelve**: `GlobalChat.astro` dedupea por `id` en `renderedIds` (Etapa
    1, línea 126) — el mensaje optimista de `notifyLocalMessage()` y el que
    llegue por WS van a tener el mismo `id` real (el que devuelve `POST
    /chat/messages`), así que el segundo en llegar se descarta solo, sin
    tocar nada del componente. **Recomendación: no usar `toOthers()`, broadcastear
    a todos, confiar en el dedupe ya existente** — menos piezas nuevas, cero
    riesgo de que las dos mecánicas de dedupe (socket-id del lado servidor +
    id del lado cliente) queden desincronizadas entre sí.

    ## Archivos a crear/modificar (backend) — diffs completos, nada aplicado

    ### 1. `composer.json` — comando, no edición a mano
    ```bash
    composer require laravel/reverb
    php artisan install:broadcasting
    ```
    El segundo comando pregunta interactivamente si querés instalar Reverb (sí)
    — según la doc, esto crea `config/broadcasting.php`, `config/reverb.php`,
    `routes/channels.php`, agrega las variables `REVERB_*` al `.env`, y
    modifica `bootstrap/app.php` agregando un `channels:` al `withRouting()`
    existente (el mecanismo DEFAULT, con el grupo `web` — lo vamos a corregir
    en el paso 2). Dejo esto como el primer comando a correr, y reviso/corrijo
    lo que genere antes de seguir — no asumo que el resultado del instalador ya
    queda listo para Sanctum sin tocar nada.

    ### 2. `bootstrap/app.php` — corrección posterior al instalador
    ```diff
     return Application::configure(basePath: dirname(__DIR__))
         ->withRouting(
             web: __DIR__.'/../routes/web.php',
             api: __DIR__.'/../routes/api.php',
             commands: __DIR__.'/../routes/console.php',
             health: '/up',
    -        channels: __DIR__.'/../routes/channels.php',
         )
    +    ->withBroadcasting(
    +        __DIR__.'/../routes/channels.php',
    +        ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
    +    )
         ->withMiddleware(function (Middleware $middleware) {
             $middleware->throttleApi();
         })
         ->withExceptions(function (Exceptions $exceptions) {})
         ->create();
    ```
    (La línea `channels:` de arriba es lo que **espero** que agregue el
    instalador según la doc — la confirmo contra el archivo real que resulte
    antes de proponer el diff final, no antes.)

    ### 3. `.env` (VPS, producción) — variables nuevas, ninguna existente se toca
    ```env
    BROADCAST_CONNECTION=reverb

    REVERB_APP_ID=<lo genera reverb:install>
    REVERB_APP_KEY=<lo genera reverb:install>
    REVERB_APP_SECRET=<lo genera reverb:install>

    # Loopback a propósito (Etapa 1 §11.7): Laravel publica el evento
    # DIRECTO a Reverb sin pasar por Nginx/dominio público.
    REVERB_HOST=127.0.0.1
    REVERB_PORT=8080
    REVERB_SCHEME=http
    ```
    El instalador probablemente escriba `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME`
    con un valor default distinto (pensado para exponer Reverb públicamente) —
    hay que corregirlos a mano a estos tres valores después de correrlo, antes
    de dar por terminado este paso. No hace falta `REVERB_SERVER_HOST`/`_PORT`:
    el `--host=127.0.0.1 --port=8080` que ya va en el `ExecStart` del systemd
    (Etapa 1 §8) cumple la misma función sin duplicar la config en dos lugares.

    ### 4. `routes/channels.php` (nuevo, lo crea el instalador — contenido a confirmar/completar)
    ```php
    <?php

    use Illuminate\Support\Facades\Broadcast;

    // Chat global: cualquier usuario autenticado por Sanctum puede escuchar
    // -no hay lógica de pertenencia, es el mismo chat global que ya expone
    // GET /chat/messages sin filtrar por nadie.
    Broadcast::channel('chat', fn ($user) => true);
    ```

    ### 5. `app/Models/ChatMessage.php` — nuevo método, reusado por HTTP y por el evento
    ```diff
         public function user(): BelongsTo
         {
             return $this->belongsTo(User::class);
         }
    +
    +    // Misma forma que ChatController::present() usaba inline -ahora vive
    +    // acá para que el evento de broadcasting (app/Events/ChatMessageCreated.php)
    +    // y la respuesta HTTP nunca puedan desincronizarse en dos formatos
    +    // paralelos del mismo mensaje.
    +    public function toBroadcastArray(): array
    +    {
    +        return [
    +            'id' => $this->id,
    +            'user_name' => $this->user->name,
    +            'message' => $this->message,
    +            'created_at' => $this->created_at->toIso8601String(),
    +        ];
    +    }
     }
    ```

    ### 6. `app/Events/ChatMessageCreated.php` (nuevo)
    ```php
    <?php

    namespace App\Events;

    use App\Models\ChatMessage;
    use Illuminate\Broadcasting\Channel;
    use Illuminate\Broadcasting\InteractsWithSockets;
    use Illuminate\Broadcasting\PrivateChannel;
    use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
    use Illuminate\Foundation\Events\Dispatchable;

    // Sincrónico a propósito (ShouldBroadcastNow, no ShouldBroadcast): esta
    // VPS no tiene un queue worker corriendo (QUEUE_CONNECTION=database sin
    // worker activo) y no vamos a agregar Supervisor solo para esto -ver
    // docs/REALTIME_CHAT_AUDIT.md Etapa 1 §11. El payload es chico (mensaje
    // <=150 caracteres a nivel de columna), el costo síncrono es despreciable.
    class ChatMessageCreated implements ShouldBroadcastNow
    {
        use Dispatchable, InteractsWithSockets;

        public function __construct(private readonly ChatMessage $message)
        {
        }

        public function broadcastOn(): Channel
        {
            return new PrivateChannel('chat');
        }

        public function broadcastAs(): string
        {
            return 'ChatMessageCreated';
        }

        public function broadcastWith(): array
        {
            return $this->message->toBroadcastArray();
        }
    }
    ```

    ### 7. `app/Http/Controllers/Api/ChatController.php`
    ```diff
     namespace App\Http\Controllers\Api;

     use App\Http\Controllers\Controller;
    +use App\Events\ChatMessageCreated;
     use App\Http\Requests\SendChatMessageRequest;
     use App\Models\ChatMessage;
     use Illuminate\Http\Request;
    @@
         public function store(SendChatMessageRequest $request)
         {
             $message = ChatMessage::create([
                 'user_id' => $request->user()->id,
                 'message' => $request->validated()['message'],
             ]);

             $message->setRelation('user', $request->user());

    +        // Si Reverb está caído/mal configurado, el mensaje YA se guardó -
    +        // un fallo acá nunca debe convertir un 201 real en un 500. El chat
    +        // HTTP (polling todavía activo en el frontend en esta etapa) debe
    +        // sobrevivir intacto aunque el broadcasting falle.
    +        try {
    +            event(new ChatMessageCreated($message));
    +        } catch (\Throwable $e) {
    +            report($e);
    +        }
    +
             return response()->json($this->present($message), 201);
         }

         private function present(ChatMessage $message): array
         {
    -        return [
    -            'id' => $message->id,
    -            'user_name' => $message->user->name,
    -            'message' => $message->message,
    -            'created_at' => $message->created_at->toIso8601String(),
    -        ];
    +        return $message->toBroadcastArray();
         }
     }
    ```
    `index()` no cambia — el `GET /chat/messages` sigue exactamente igual,
    intacto para el historial inicial, tal como pediste (punto 10).

    ### 8. `chibikko-reverb.service` (systemd, ya diseñado en Etapa 1 §8, confirmado el nombre)
    Sin cambios respecto a lo ya propuesto — `User=deploy`, `--host=127.0.0.1
    --port=8080`, `Restart=always`. Se aplica recién cuando confirmes este plan.

    ### 9. `deploy.sh` — un paso nuevo al final, propuesto, no aplicado
    ```diff
     php8.3 artisan config:cache
     php8.3 artisan route:cache
     php8.3 artisan view:cache
     sudo systemctl reload php8.3-fpm
    +sudo systemctl restart chibikko-reverb
     php8.3 artisan up
    ```
    Reverb es un proceso de larga duración -a diferencia de PHP-FPM (`reload`,
    sin cortar requests en curso), reiniciarlo sí corta las conexiones WS
    abiertas un instante; Echo reconecta solo (comportamiento default), y por
    eso "reinicio de Reverb"/"reconexión" ya están en tu plan de pruebas de
    Etapa 5.

    ## Frontend — diseño de la integración Echo + pusher-js (auditado, NO aplicado a `GlobalChat.astro` todavía)

    Confirmado de nuevo antes de diseñar esto: `web/package.json` sigue sin
    `laravel-echo`/`pusher-js`, `GlobalChat.astro` sigue con el mismo
    `createPollingSource()` de la Etapa 1, `ApiClient.ts::getToken()` sigue
    exportado y reutilizable tal cual.

    ### Paquetes
    ```bash
    npm install laravel-echo pusher-js
    ```
    (En `web/`, no en `backend/`.)

    ### Variables de entorno nuevas (Vercel, prefijo `PUBLIC_` obligatorio — mismo criterio que `PUBLIC_API_URL`)
    | Variable | Valor | Por qué este valor y no otro |
    |---|---|---|
    | `PUBLIC_REVERB_APP_KEY` | el `REVERB_APP_KEY` generado por `reverb:install` | tiene que ser el mismo valor que el backend, es la clave pública que identifica la "app" de Reverb |
    | `PUBLIC_REVERB_HOST` | `45.7.228.4.sslip.io` | **el dominio público**, no `127.0.0.1` — es el navegador el que conecta, a través de Nginx (`/app/`). No confundir con `REVERB_HOST=127.0.0.1` del backend, que es un valor DISTINTO para un salto DISTINTO (Laravel→Reverb por loopback, nunca visto por el navegador) |
    | `PUBLIC_REVERB_PORT` | `443` | Nginx solo expone 443; Reverb en 8080 nunca es visible desde afuera |
    | `PUBLIC_REVERB_SCHEME` | `https` | fuerza `wss://`, coherente con que todo el sitio es HTTPS |

    ### Nuevo módulo `web/src/game/net/Realtime.ts` (propuesto, no creado)
    ```ts
    // Único lugar que sabe inicializar Laravel Echo -mismo criterio que
    // ApiClient.ts para HTTP: cualquier componente que necesite tiempo real
    // importa `getEcho()` de acá, nunca instancia Echo por su cuenta.
    import Echo from "laravel-echo";
    import Pusher from "pusher-js";
    import { authorizeBroadcastChannel } from "./ApiClient";

    // pusher-js lo necesita en window por compatibilidad de la librería
    // -no es una dependencia real de Pusher.com, ver Etapa 1 §7-.
    (window as any).Pusher = Pusher;

    let echo: Echo<"reverb"> | null = null;

    export function getEcho(): Echo<"reverb"> {
      if (echo) return echo;

      echo = new Echo({
        broadcaster: "reverb",
        key: import.meta.env.PUBLIC_REVERB_APP_KEY,
        wsHost: import.meta.env.PUBLIC_REVERB_HOST,
        wsPort: Number(import.meta.env.PUBLIC_REVERB_PORT ?? 443),
        wssPort: Number(import.meta.env.PUBLIC_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.PUBLIC_REVERB_SCHEME ?? "https") === "https",
        enabledTransports: ["ws", "wss"],
        // Canal privado autenticado por Bearer, NUNCA por cookie -adaptación
        // del ejemplo de la doc de Sanctum (que usa axios+cookies) a nuestro
        // ApiClient.ts existente, que ya adjunta Authorization: Bearer solo.
        authorizer: (channel: { name: string }) => ({
          authorize(socketId: string, callback: (error: boolean, data?: unknown) => void) {
            authorizeBroadcastChannel(socketId, channel.name)
              .then((data) => callback(false, data))
              .catch((err) => callback(true, err));
          },
        }),
      });

      return echo;
    }
    ```

    ### `ApiClient.ts` — una función nueva (propuesta, no agregada)
    ```ts
    // Autoriza un canal privado de broadcasting -mismo helper request() que
    // todo lo demás en este archivo, así el header Authorization: Bearer sale
    // automático (ver Realtime.ts). Nunca fetch() directo desde otro lado del
    // frontend, mismo principio que el resto de ApiClient.ts.
    export async function authorizeBroadcastChannel(socketId: string, channelName: string): Promise<unknown> {
      return request("/api/v1/broadcasting/auth", {
        method: "POST",
        body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
      });
    }
    ```
    `request()` ya es exactamente lo que la doc de Sanctum resuelve con
    axios+cookies -adjuntar el header correcto a esta llamada puntual- salvo
    que acá sale gratis: `request()` YA adjunta `Authorization: Bearer` a
    cualquier llamada, así que no hace falta escribir nada nuevo para el header
    en sí, solo esta función de una línea que apunta al endpoint correcto.

    ### `GlobalChat.astro` — SIN TOCAR todavía, esto es solo la forma que va a tener (Etapa 4, no ahora)
    ```ts
    function createWebSocketSource(onMessages: (messages: ChatMessageDto[]) => void) {
      const channel = getEcho().private("chat");
      return {
        async start() {
          await tick();               // historial inicial, GET sin cambios (punto 10)
          channel.listen(".ChatMessageCreated", (e: ChatMessageDto) => onMessages([e]));
        },
        stop() { getEcho().leave("chat"); },
        notifyLocalMessage(message: ChatMessageDto) { onMessages([message]); }, // sin cambios, dedupe ya existente hace el resto
      };
    }
    ```
    Mismo `{start, stop, notifyLocalMessage}` que `createPollingSource()` ya
    tiene — se podría cambiar cuál se usa con una sola línea
    (`const source = createWebSocketSource(renderIncoming)` en vez de
    `createPollingSource(...)`), pero **no lo aplico en esta etapa** — pediste
    específicamente auditar esto antes de tocar el archivo, y el polling debe
    seguir siendo el único mecanismo activo hasta que Etapa 3 esté
    desplegada y probada de punta a punta en el backend.

    ## Por qué esto no elimina ni debilita el rate limiting existente

    `/api/v1/broadcasting/auth` hereda `throttle:api` (60/min) porque su
    middleware incluye `'api'` (el grupo al que `throttleApi()` ya se aplica
    globalmente, `bootstrap/app.php`) — nada nuevo que excluir del limiter. En
    la práctica esto se llama una sola vez por conexión/reconexión de Echo, no
    por mensaje — muy por debajo de las 24 requests/min que el polling actual
    consume solo, lo cual es, dicho sea de paso, la razón real por la que esta
    migración resuelve el 429 (Etapa 1 §2) sin tocar el límite en sí.

    ## Orden de comandos propuesto (yo los ejecuto, vos confirmás cada uno)

    ```bash
    # 1) Backend — instalar
    composer require laravel/reverb
    php artisan install:broadcasting   # confirmar "sí" cuando pregunte por Reverb

    # 2) Revisar juntos qué escribió el instalador en bootstrap/app.php y .env
    #    ANTES de seguir -no asumo que coincide con el diff de arriba-
    cat bootstrap/app.php
    cat .env | grep -i reverb

    # 3) Recién ahí aplico (con tu aprobación) los diffs de bootstrap/app.php,
    #    ChatMessage.php, ChatMessageCreated.php, ChatController.php,
    #    routes/channels.php, y las correcciones de REVERB_HOST/PORT/SCHEME

    # 4) systemd
    sudo cp <contenido> /etc/systemd/system/chibikko-reverb.service
    sudo systemctl daemon-reload
    sudo systemctl enable --now chibikko-reverb
    sudo systemctl status chibikko-reverb

    # 5) Verificación SIN frontend todavía (el polling sigue siendo lo único activo)
    curl -sv https://45.7.228.4.sslip.io/app/   # debería dejar de dar 502
    php artisan tinker
    >>> event(new App\Events\ChatMessageCreated(App\Models\ChatMessage::with('user')->latest()->first()))
    # confirmar en el log de Reverb (--debug) o en journalctl que el evento salió
    ```

    No corro nada de esto — cada paso queda para que vos lo ejecutes y me
    pegues el resultado, exactamente como las etapas anteriores.

    **No instalé `laravel/reverb`, `laravel/echo` ni `pusher-js`. No creé
    ningún archivo nuevo. No modifiqué `ChatController.php`, `ChatMessage.php`,
    `bootstrap/app.php`, `.env`, `deploy.sh` ni `GlobalChat.astro`. No ejecuté
    nada en la VPS.**

    ---

    # Pausa en Etapa 3 — Auditoría de versión de Laravel

    `composer require laravel/reverb` + `composer install --no-dev
    --optimize-autoloader` corridos y confirmados por vos: `laravel/reverb
    v1.11.1` instalado en `require` (no `require-dev`), `laravel/framework
    v11.56.1`. `composer audit` reportó 3 advisories sobre `laravel/framework`.
    Broadcasting queda pausado hasta resolver esto — `php artisan
    install:broadcasting` **no se corrió**.

    ## Diagnóstico

    ### 1-2. Qué versión de 11.x corrige los advisories, y por qué Composer sigue en v11.56.1

    **Ninguna versión de Laravel 11.x los corrige, y nunca va a existir una
    que lo haga.** Confirmado contra dos fuentes independientes:

    - **Tabla oficial de soporte** ([laravel.com/docs/11.x/releases](https://laravel.com/docs/11.x/releases)):

      | Versión | Bug Fixes Until | Security Fixes Until |
      |---|---|---|
      | 11 | 3 sept. 2025 | **12 marzo 2026** |
      | 12 | 13 ago. 2026 | 24 feb. 2027 |

      Laravel 11 salió de soporte de seguridad el **12 de marzo de 2026**.
      Hoy es 17 de septiembre de 2026 — **más de 6 meses después**.

    - **Fechas de los dos advisories identificados:** el de CRLF
      (`GHSA-5vg9-5847-vvmq`, CVE-2026-48019) se publicó el **4 de
      septiembre de 2026**; el de Path Confusion en URLs firmadas
      (`GHSA-crmm-hgp2-wgrp`) el **8 de junio de 2026**. **Ambos se
      publicaron DESPUÉS de que Laravel 11 dejara de recibir parches de
      seguridad.** El equipo de Laravel nunca backporteó un fix a 11.x
      simplemente porque, según su propia política, ya no lo hacen para una
      versión fuera de la ventana de soporte — no es un descuido ni un hueco
      de documentación.

    - Confirmado además en las páginas GHSA de cada advisory (rangos de
      versión exactos, no inferidos): Path Confusion afecta `< 12.61.1` y
      `>=13.0.0 <13.12.0` (parchea en `12.61.1`/`13.12.0`); CRLF afecta
      `< 12.60.0` y `>=13.0.0 <=13.9.0` (parchea en `12.60.0`/`13.10.0`).
      **Ninguno de los dos rangos lista una versión 11.x afectada NI
      parcheada** — consistente con que 11.x ya estaba fuera de soporte
      cuando se publicaron.

    - **`composer show laravel/framework` ya confirmó `v11.56.1`, y esa ES la
      última versión 11.x jamás publicada** (Packagist no lista ninguna
      posterior). Por eso Composer "se queda" en 11.56.1: no es un
      `composer.lock` desactualizado — **es el techo real del proyecto
      `laravel/framework` mientras el `composer.json` diga `^11.0`.**

    ### 3-4. Versión máxima que permite hoy `composer.json`/lock, y si hay un update seguro dentro de 11.x

    `composer.json` (`"laravel/framework": "^11.0"`) técnicamente permite
    cualquier `11.x`, pero como `11.56.1` ya es la última que existe, **`composer
    update laravel/framework` no haría absolutamente nada** — ya estamos en el
    máximo posible. **No existe ningún "update seguro dentro de 11" que
    resuelva esto**, porque el problema no es una versión vieja dentro de una
    serie viva, es una serie completamente muerta. Confirma la opción (A) del
    menú que planteaste ("seguimos con Laravel 11 temporalmente") como
    "seguir exactamente como estamos hoy, sin ningún parche posible", no como
    "actualizar dentro de 11".

    ### 5-6. Qué implica migrar a Laravel 12, y a Laravel 13

    Revisé las guías oficiales completas de ambos upgrades (11→12 y 12→13),
    letra por letra, contra el código real de este proyecto (no en abstracto):

    **11 → 12** ([guía oficial](https://laravel.com/docs/12.x/upgrade),
    Laravel estima **5 minutos**):
    - **Alto impacto:** solo bump de versiones en `composer.json`
      (`laravel/framework ^12.0`, `phpunit/phpunit ^11.0` — hoy `^10.5`).
      `pestphp/pest` no aplica, no lo usamos (PHPUnit puro, confirmado en
      todo este proyecto).
    - **Medio impacto:** UUIDs v7 en el trait `HasUuids` — **no aplica**,
      ningún modelo de este proyecto usa UUIDs (todo es `$table->id()`
      autoincremental, confirmado en las 24 migraciones).
    - **Bajo impacto, revisado uno por uno contra nuestro código:** Carbon 3
      (transparente), instanciación manual de `Blueprint`/`Grammar` (no lo
      hacemos, solo usamos `Schema::create()` normal), validación de imagen
      excluyendo SVG (no tenemos validación de imágenes), default de
      `Storage::disk('local')` (no usamos Storage para uploads),
      `mergeIfMissing` anidado (no lo usamos), inspección multi-schema de DB
      (no aplica a MariaDB de un solo schema). **Ninguno de los ítems de
      "medio" o "alto" impacto toca código real de este proyecto.**

    **12 → 13** ([guía oficial](https://laravel.com/docs/13.x/upgrade),
    Laravel estima **10 minutos**, release más reciente `v13.32.0`,
    2026-09-15 — Laravel 13.0 salió ~Q1 2026, ya lleva ~7 meses de uso real,
    no es "recién salida"):
    - **Alto impacto:** bump de versiones (`laravel/framework ^13.0`,
      `laravel/tinker ^3.0` — hoy `^2.9`, `phpunit/phpunit ^12.0`) +
      renombre de `VerifyCsrfToken` → `PreventRequestForgery`. Este último
      **casi no nos toca**: `config/sanctum.php` referencia
      `ValidateCsrfToken` como alias de middleware disponible, pero nunca lo
      ejecutamos (no usamos el guard `web`/cookies, confirmado en toda la
      auditoría de Reverb) — y la doc confirma que el nombre viejo queda
      como alias deprecado, no roto.
    - **Medio impacto:** `cache.serializable_classes` (hardening de
      deserialización — no guardamos objetos PHP en cache, solo contadores
      del rate limiter, no aplica) y validación de `upsert()` con `uniqueBy`
      vacío (no usamos `upsert()`, usamos `updateOrCreate()` en los
      seeders, que es un método distinto, no afectado).
    - **Bajo impacto revisado:** nada de paginación Bootstrap (no hay vistas
      Blade, es una API pura), nada de eventos de cola (cero listeners),
      nada de rutas por dominio, ningún modelo con `boot()` custom que
      instancie `new static()`. `config/session.php` cambia el default de
      `serialization` a `json` — este proyecto no depende de deserializar
      objetos PHP de la sesión (Bearer-only), impacto esperado nulo pero
      vale confirmarlo si se migra.

    **Conclusión de 5-6: para el código REAL de este proyecto, ambos saltos
    son mecánicamente simples** — casi todo el "impacto" documentado
    corresponde a features que este proyecto no usa (UUIDs, Blade, colas,
    Storage de uploads, CSRF real, deserialización de objetos en cache).

    ### 7-8. Compatibilidad de `laravel/reverb` y `laravel/sanctum` con L12/L13

    Ambos son compatibles con Laravel 11, 12 **y** 13 simultáneamente — **no
    hace falta cambiar de versión de ninguno de los dos paquetes sin importar
    qué camino se elija**:
    - `laravel/reverb v1.11.1`: requiere `^10.47|^11.0|^12.0|^13.0`
      (Packagist).
    - `laravel/sanctum` última estable `v4.3.3`: requiere
      `^11.0|^12.0|^13.0` (Packagist). Seguimos en `^4.0` en `composer.json`,
      sin necesidad de tocarlo.

    ### 9. Qué partes de nuestro código podrían requerir cambios

    Con la revisión de arriba, **ninguna parte funcional real** — el único
    cambio mecánico sería el bump de versiones en `composer.json`
    (`phpunit/phpunit`, y `laravel/tinker` si se llega a 13). No identifiqué
    ningún archivo de `app/`, `routes/`, `database/migrations/` o `config/`
    de este proyecto que dependa de un comportamiento que cambie en 12 o 13.

    ### 10. ¿Los advisories afectan lo que realmente usamos?

    Aunque ya está resuelto que 11.x nunca los va a parchear, vale confirmar
    si aplican a nuestro USO real (relevante para decidir si el riesgo de
    quedarnos en 11 es alto o bajo mientras se decide el camino):

    - **CRLF Injection (CVE-2026-48019):** afecta la regla de validación
      `email` default cuando el valor viaje hacia envío de mail con
      direcciones suministradas por el usuario. Este proyecto: `MAIL_MAILER=log`
      (no manda mail real, confirmado en `.env.example`/auditorías previas),
      y no hay ningún flujo de "enviar correo a una dirección que el usuario
      escribe" (sin recuperación de contraseña por email, sin invitaciones,
      sin verificación de email — confirmado, no existe ese feature en el
      proyecto). **Superficie real: prácticamente nula hoy.**
    - **Path Confusion en URLs firmadas temporales:** afecta
      `URL::temporarySignedRoute()`/rutas firmadas de Storage. Este proyecto
      no genera URLs firmadas en ningún lado (sin descargas protegidas, sin
      links de verificación, confirmado por búsqueda de `signedRoute`/`signed
      middleware` en `routes/`). **Superficie real: nula.**

    **Esto es tranquilizador para el riesgo INMEDIATO, pero no cambia el
    diagnóstico de fondo:** el problema no es "estas 2 vulnerabilidades nos
    afectan hoy" (no lo hacen), es que **Laravel 11 ya no recibe NINGÚN
    parche de seguridad desde marzo, y la próxima vulnerabilidad que se
    publique —sea cual sea, toque lo que toque— tampoco va a tener nunca un
    fix 11.x.** Es una ventana de riesgo que solo crece con el tiempo, no un
    problema puntual de estos 2 CVEs.

    ## Pendiente para cerrar el diagnóstico al 100%

    `composer audit` reportó **3** advisories y hasta acá identifiqué 2
    (CRLF + Path Confusion). Necesito el **output completo y textual** de
    `composer audit` para identificar el tercero — no lo inventé ni lo
    asumí, prefiero pedirlo antes de dar la recomendación final por si
    cambia algo (ej. si el tercero SÍ afectara una versión 11.x, o afectara
    un paquete `require-dev` que ya no está en producción tras el `--no-dev`
    de recién).

    ## Recomendación técnica (preliminar, sujeta al tercer advisory)

    Con la información de arriba, mi lectura de las 3 opciones que planteaste:

    - **(A) Seguir en 11 temporalmente:** válido como decisión de corto plazo
      **si** se entiende que es "aceptar riesgo creciente sin parches
      posibles", no "seguro porque estos 2 CVEs no nos tocan hoy". Razonable
      si el objetivo inmediato es terminar Reverb primero y programar la
      migración de versión como una tarea aparte, ya planificada, en vez de
      bloquear Etapa 3 más tiempo.
    - **(B) "Actualizar dentro de lo posible":** no existe como opción
      real — ya se demostró que 11.56.1 es el techo. Esta rama del menú
      colapsa a la (A) o a la (C).
    - **(C) Migrar de versión antes de seguir con Reverb:** dado que (i) el
      código real de este proyecto no toca casi ninguno de los cambios
      documentados de impacto medio/alto, (ii) Reverb y Sanctum ya soportan
      12 y 13 sin cambios, y (iii) Laravel mismo estima 5-10 minutos de
      trabajo mecánico — el costo de migrar parece bajo. La decisión entre
      **12** (soporte de seguridad activo hasta feb. 2027, más tiempo en
      producción real) y **13** (más nueva, mayor ventana de soporte futura,
      pero ~7 meses de antigüedad real hoy) es una preferencia de
      estabilidad-vs-vigencia, no una diferencia técnica de riesgo para nuestro
      código — ambas están limpias de los 2 advisories ya identificados.

    Mi sugerencia, sujeta a lo que aparezca en el tercer advisory: **(C),
    saltando directo a Laravel 13** (evita hacer el trabajo de migración dos
    veces, y el propio Reverb/Sanctum ya lo soportan) — pero es una decisión
    tuya, no una que vaya a ejecutar sin tu confirmación explícita.

    **No corrí `composer update`, no toqué `composer.json` ni `composer.lock`,
    no corrí `install:broadcasting`, no cambié código, no toqué la VPS.**

    ---

    ## Corrección tras el `composer audit` completo — cierre del diagnóstico

    El tercer advisory (`PKSA-mdq4-51ck-6kdq`) **corrige algo que había
    concluido mal arriba**. Merece decirlo así de directo: antes de tener
    este output completo, verifiqué el rango de versiones afectadas
    consultando la página de GitHub del advisory (`GHSA-5vg9-5847-vvmq`) y
    concluí que Laravel 11.x no estaba en el rango afectado del CRLF
    injection. **Esa conclusión era incompleta.**

    Los 3 advisories son en realidad solo **2 vulnerabilidades distintas**
    (`PKSA-3r5d` y `PKSA-mdq4` apuntan al mismo `GHSA-5vg9-5847-vvmq`/CVE-2026-48019
    — el CRLF injection aparece dos veces en la base de datos que usa
    `composer audit`, con dos rangos de versiones distintos):

    | Entrada | Vulnerabilidad | Rango afectado según ESA entrada |
    |---|---|---|
    | `PKSA-m5cs` (= GHSA-crmm-hgp2-wgrp) | Path Confusion en URLs firmadas | `<12.61.1｜>=13.0.0,<13.12.0` — **sin 11.x** |
    | `PKSA-3r5d` (= GHSA-5vg9-5847-vvmq, sin CVE listado) | CRLF injection | `<12.60.0｜>=13.0.0,<=13.9.0` — **sin 11.x** |
    | `PKSA-mdq4` (= GHSA-5vg9-5847-vvmq, **con CVE-2026-48019**) | CRLF injection | `>=9.0.0,<10.0.0｜>=10.0.0,<11.0.0｜>=11.0.0,<12.0.0｜>=12.0.0,<12.60.0｜>=13.0.0,<13.10.0` — **incluye 11.x explícitamente** |

    La entrada con el CVE real asignado (`PKSA-mdq4`) tiene un rango mucho
    más completo que la página pública de GitHub que yo había consultado
    (que solo listaba 12.x/13.x). Lectura más probable: el código
    vulnerable (la regla de validación `email` por defecto) existe desde
    Laravel 9.x, pero como 9, 10 y 11 ya estaban fuera de soporte cuando se
    coordinó la publicación, el testing/disclosure público (la página GHSA)
    solo confirmó formalmente contra las versiones vivas en ese momento
    (12.x/13.x) — la base de datos de seguridad de PHP (`PKSA-*`, la que
    realmente usa `composer audit`) sí registra el rango histórico completo.

    **Conclusión corregida: el código vulnerable de CVE-2026-48019 (CRLF
    injection, severidad HIGH, CVSS 8.9) SÍ está presente en
    `laravel/framework v11.56.1`, la versión que corre este proyecto ahora
    mismo.** El resto del diagnóstico no cambia: sigue sin existir ni poder
    existir un parche 11.x (11 ya estaba fuera de soporte cuando se
    coordinó la publicación), y Path Confusion sigue sin afectar a 11.x
    (las 3 entradas de la tabla de arriba coinciden en eso, ninguna lista
    un rango 11.x para esa vulnerabilidad específica).

    Esto **no** cambia mi lectura de la explotabilidad real hoy (§10 más
    arriba sigue siendo válido como hecho): `MAIL_MAILER=log` (no se manda
    mail real) y no existe ningún flujo de email a una dirección
    suministrada por el usuario (sin recuperación de contraseña, sin
    invitaciones, sin verificación) en el proyecto actual — así que aunque
    el código vulnerable esté presente, la ruta de explotación real no
    existe hoy. Pero es una distinción importante que hay que sostener con
    precisión: **"el código vulnerable está en nuestra versión, pero hoy no
    tenemos el feature que lo dispara" es distinto de "no nos afecta"** — si
    en cualquier fase futura de este proyecto se agrega un flujo de email
    con dirección provista por el usuario (recuperación de contraseña,
    invitaciones, notificaciones), esa función nacería ya expuesta a un CVE
    conocido, público, de severidad alta, y sin ningún parche de framework
    posible mientras se siga en 11.x.

    ## Recomendación final

    Con el diagnóstico ahora completo, separo la respuesta en dos preguntas
    que el menú A/B/C mezclaba:

    **¿Hay que migrar de versión de Laravel? Sí, en algún momento —** no por
    los 2 CVEs puntuales (su explotabilidad real hoy es baja), sino porque
    **Laravel 11 lleva más de 6 meses sin ningún parche de seguridad posible
    y la próxima vulnerabilidad que aparezca —toque lo que toque— va a tener
    exactamente el mismo problema.** Confirmé que el costo real de migrar
    para ESTE código es bajo (nada de lo documentado como impacto medio/alto
    en las guías 11→12 y 12→13 toca funcionalidad que este proyecto usa), y
    que Reverb/Sanctum ya soportan 12 y 13 sin cambios.

    **¿Hay que migrar ANTES de seguir con Reverb, o después? Mi recomendación
    es DESPUÉS — terminar Etapa 3 en Laravel 11 y migrar como una iniciativa
    separada a continuación, no mezclada con esto.** Razones:

    1. **Ninguna pieza del diseño de Reverb que ya revisamos depende de la
       versión de Laravel.** `withBroadcasting()`, `Broadcast::channel()`,
       `ShouldBroadcastNow`, `PrivateChannel`, el patrón de `authorizer`
       custom de Echo — ninguno de los dos upgrade guides (11→12, 12→13)
       menciona cambios a broadcasting/Sanctum. El trabajo de diseño ya
       hecho no se pierde ni cambia si migramos después.
    2. **No hay una razón de urgencia real hoy** — ya establecido que la
       ruta de explotación de los 2 CVEs conocidos no existe en el feature
       set actual del proyecto.
    3. **Mezclar "instalar Reverb" + "migrar de major version de Laravel"
       en la misma ventana de cambios hace más difícil diagnosticar** si
       algo se rompe: no sabrías si el problema viene del bump de Laravel o
       del código nuevo de broadcasting. Separarlos mantiene cada cambio
       auditable y revertible de forma independiente — mismo criterio que
       ya venimos aplicando en cada etapa de esta migración.
    4. Es una VPS de producción activa con usuarios reales — dos cambios
       estructurales grandes en la misma sesión de trabajo es más riesgo
       acumulado que dos sesiones separadas, cada una con su propia
       verificación y ventana de rollback.

    **Camino sugerido:** cerrar Etapa 3 (Reverb) en Laravel 11.x, y agendar
    la migración a Laravel 13 (salteando 12, dado que ninguno de los cambios
    de la guía 12→13 tampoco toca este código, y evita hacer dos migraciones
    en vez de una) como el próximo trabajo después de validar el chat en
    tiempo real de punta a punta.

    Esto es una recomendación, no una ejecución — la decisión final
    (retomar Reverb ahora, o migrar Laravel primero) queda en tus manos. No
    corrí `composer update`, no toqué `composer.json`/`.lock`, no corrí
    `install:broadcasting`, no cambié código, no toqué la VPS.

    ---

    # Etapa 3 — Cierre (Reverb instalado, configurado y validado en el backend)

    ## 1. Instalación

    - **Laravel Reverb v1.11.1** instalado vía Composer (en `require`, no
      `require-dev` — corregido tras el incidente inicial que había traído de
      más el árbol de `require-dev`, ver más arriba en este documento).
    - Servicio systemd: **`chibikko-reverb.service`**, creado en
      `/etc/systemd/system/`, validado con `systemd-analyze verify` sin errores
      propios (la única advertencia que imprimió es preexistente de
      `snapd.service`, sin relación).
    - PHP CLI usado: **`/usr/bin/php8.3`** (confirmado con `which php8.3` antes
      de escribir la unit, no asumido).
    - Corre como usuario **`deploy`**, grupo **`deploy`** (confirmado con
      `id deploy` antes de aprobar la unit) — nunca como root, mismo usuario
      que ya ejecuta `deploy.sh`.
    - Bind confirmado en **`127.0.0.1:8080` únicamente** — verificado con
      `ss -tlnp` tras el primer arranque: una sola línea, `127.0.0.1:8080`,
      nunca `0.0.0.0:8080`.
    - **Sin Docker, sin Redis, sin Supervisor, sin queue worker.**
      `QUEUE_CONNECTION` sigue en `database`, sin ningún worker corriendo — el
      broadcasting es síncrono (`ShouldBroadcastNow`, ver §11 más arriba). Las
      dependencias de Redis que trae `laravel/reverb` en su árbol de Composer
      (`clue/redis-*`) siguen sin usarse: `REVERB_SCALING_ENABLED` no está
      habilitado.
    - Servicio **`enabled`** — arranca automático con el servidor.
    - `Restart=always` — se reinicia solo si el proceso termina, sea por una
      caída real o por una salida limpia (ej. la que genera
      `php artisan reverb:restart` al recargar código en un futuro deploy); no
      reinicia después de una parada administrativa explícita (`systemctl
      stop`), que systemd siempre respeta sin importar la política de
      `Restart=`.
    - Memoria observada en el primer arranque: **33.3M** — consistente con la
      expectativa de la auditoría original (Etapa 1 §5: "un puñado de decenas
      de MB"), ya no es una estimación, es lo medido en esta VPS.

    ## 2. Configuración final de `.env` (backend, VPS)

    ```env
    BROADCAST_CONNECTION=reverb
    REVERB_SERVER_HOST=127.0.0.1
    REVERB_SERVER_PORT=8080
    REVERB_HOST=127.0.0.1
    REVERB_PORT=8080
    REVERB_SCHEME=http
    ```

    `REVERB_APP_ID`, `REVERB_APP_KEY` y `REVERB_APP_SECRET` están configurados
    (generados por `reverb:install`) — sus valores no se documentan acá, ver
    el `.env` real en la VPS. Las 4 variables `VITE_REVERB_*` que el
    instalador agrega por default se eliminaron intencionalmente: el frontend
    real es Astro/Vercel, no hay Vite en este backend, y esas variables no
    tienen ningún motivo para vivir en el `.env` del backend.

    ## 3. Nginx (sin cambios nuevos en esta etapa — ya aplicado en Etapa 2)

    `location ^~ /app/` en `/etc/nginx/sites-available/chibikko-api`, dentro
    del `server{}` de 443, hace `proxy_pass http://127.0.0.1:8080` con los
    headers de upgrade de WebSocket (`Upgrade`, `Connection: Upgrade`,
    `proxy_http_version 1.1`, más `Host`/`Scheme`/`SERVER_PORT`/`REMOTE_ADDR`
    verificados contra la documentación oficial de Reverb). El puerto 8080
    **no está expuesto públicamente** — el único camino hacia Reverb desde
    Internet es ese `location`, y UFW sigue sin ninguna regla nueva. No se
    volvió a tocar Nginx en Etapa 3.

    ## 4. Validaciones realizadas

    ### A. Laravel → Reverb por loopback
    Vía `php artisan tinker`, se disparó manualmente
    `event(new App\Events\ChatMessageCreated($message))` sobre un mensaje real
    existente en `chat_messages`. Resultado observado: la llamada se ejecutó
    sin ninguna excepción — como el broadcast es síncrono
    (`ShouldBroadcastNow`), un fallo real de conexión hacia Reverb se habría
    visto ahí mismo, sin nada que lo silenciara. Confirma que Laravel publica
    correctamente hacia `127.0.0.1:8080`.

    ### B. Cliente simulado → Nginx → Reverb
    Vía `curl` con headers de upgrade de WebSocket contra
    `https://45.7.228.4.sslip.io/app/{REVERB_APP_KEY}`, se observó:
    - `HTTP/1.1 101 Switching Protocols`
    - `Sec-WebSocket-Accept` calculado correctamente (no un 101 genérico)
    - `X-Powered-By: Laravel Reverb`
    - El payload recibido inmediatamente después del upgrade:
      `{"event":"pusher:connection_established","data":"{\"socket_id\":\"...\",\"activity_timeout\":30}"}`,
      con un `socket_id` real asignado por Reverb.

    Esto confirma el handshake de WebSocket y el protocolo Pusher/Reverb
    funcionando de punta a punta a través de Nginx — no solo que el puerto
    responde, sino que el protocolo exacto que va a hablar Echo/pusher-js en
    Etapa 4 ya se confirmó funcionando en este tramo.

    ## 5. Pendiente explícito para Etapa 4

    **Todavía NO se probó el flujo end-to-end autenticado completo:** cliente
    → `POST /api/v1/broadcasting/auth` con Bearer → suscripción al canal
    privado `chat` → recepción real de un `ChatMessageCreated` emitido.
    `curl` completa el handshake inicial (validado en 4.B) pero no sabe
    mantener una conversación WebSocket completa (mandar el frame
    `pusher:subscribe` con el token de auth, escuchar eventos entrantes).
    Simularlo desde la VPS requeriría instalar una herramienta nueva de
    WebSocket (`websocat`, una librería PHP vía Composer, o Python) —
    deliberadamente **no se instaló nada de esto**: el cliente real
    (`laravel-echo` + `pusher-js`) que se va a instalar en Etapa 4 de todos
    modos es la forma correcta y más representativa de probar ese último
    tramo, no una herramienta descartable en la VPS.

    ## 6. Estado del servicio al cierre de esta etapa

    `chibikko-reverb.service` queda **enabled** (arranque automático con el
    servidor) y **active (running)** al momento de este cierre, con
    `Restart=always` (se reinicia solo tanto ante una caída real del proceso
    como ante una salida limpia). No se modificó código, `.env`, Nginx,
    frontend ni infraestructura para producir este cierre — es únicamente una
    actualización de este documento.
