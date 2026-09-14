# Deployment — GitHub → Vercel (frontend) + Railway (backend)

> Guía operativa para publicar una demo beta pública. Escrita después de una
> auditoría completa del repo (ver hallazgos y cambios en el reporte de la
> "Fase Deploy"). Este documento asume que ya se resolvió la reestructuración
> del repo a monorepo (`backend/`, `web/`, `docs/`, `art/` como hermanos en la
> raíz del repo git) — si estás leyendo esto antes de que eso pase, hacé eso
> primero.

---

## A. GitHub

### Qué debe estar versionado

Todo el código fuente y configuración no sensible:

```text
backend/app/          backend/routes/       backend/database/
backend/config/       backend/tests/        backend/composer.json
backend/Procfile      backend/.env.example
web/src/               web/public/           web/package.json
web/astro.config.mjs   web/tsconfig.json     web/.env.example
docs/                  art/                  CLAUDE.md
.gitignore
```

### Qué NO debe estar versionado

Ya cubierto por `.gitignore` (raíz del repo + `backend/.gitignore` propio de
Laravel, ambos activos y compatibles entre sí):

```text
backend/vendor/                    backend/.env
backend/storage/logs/*             backend/storage/framework/cache/*
backend/storage/framework/sessions/*   backend/storage/framework/views/*
web/node_modules/                  web/dist/
web/.astro/                        web/.env
```

Verificado con `git add --dry-run` sobre todo el árbol: `node_modules/`,
`dist/`, `.astro/` y ambos `.env` reales quedan correctamente excluidos: solo
se agregan los `.env.example` de cada lado.

### `.env.example`

Ambos (`backend/.env.example`, `web/.env.example`) están actualizados con
comentarios explicando qué cambia en producción — no contienen ningún secreto
real (la `APP_KEY` que antes venía hardcodeada en el ejemplo se dejó vacía).

### Estado actual

La reestructuración a monorepo y todos los cambios de esta fase ya están
`git add`-eados (staged) pero **no commiteados** — dejado así a propósito para
que los revises con `git status`/`git diff --cached` antes de confirmar el
commit vos mismo. Nada se pusheó a `origin`.

---

## B. Vercel (frontend — `web/`)

Astro está configurado sin adapter (`astro.config.mjs` no declara
`output`), por lo que el build es **estático puro** — confirmado corriendo
`npm run build` (`[build] output: "static"`, 12 páginas generadas en
`web/dist/`). Esto significa que Vercel puede servirlo directamente sin
ninguna función serverless, con el preset "Astro" que Vercel detecta solo.

### Configuración del proyecto en Vercel

| Campo | Valor |
|---|---|
| Root Directory | `web` |
| Framework Preset | Astro (autodetectado) |
| Build Command | `npm run build` (default) |
| Output Directory | `dist` (default) |
| Install Command | `npm install` (default) |

No hace falta `vercel.json` — es un sitio estático multi-página (cada ruta es
un `.html` real), no una SPA que necesite rewrites de fallback.

### Variables de entorno (Vercel → Project Settings → Environment Variables)

```env
PUBLIC_API_URL=https://<tu-dominio-de-railway>
```

**Importante — esto es lo que más fácil se pasa por alto:** Vite/Astro
INCRUSTA el valor de `PUBLIC_API_URL` dentro del JavaScript compilado en el
momento del build (confirmado inspeccionando `dist/_astro/ApiClient.*.js`
tras un build local: el valor queda literal en el bundle). No es una variable
que el navegador lea en tiempo real. Consecuencias prácticas:

- Configurala en Vercel **antes** del primer deploy.
- Si más adelante cambia la URL del backend, hay que **volver a hacer
  build/redeploy** — cambiar solo la variable en el dashboard no alcanza.
- Los deploys de preview (por PR/branch) de Vercel pueden necesitar la misma
  variable si querés que también hablen con el backend real.

### Después del deploy, revisar

- Que `/`, `/login`, `/register` carguen con la identidad visual esperada
  (no el scaffold default de Astro).
- Que el login/registro contra el backend real funcione (implica que CORS en
  Railway ya tenga el dominio de Vercel — ver sección C).
- Consola del navegador sin errores de red/CORS.
- Que los assets (`/assets/...`, `/backgrounds/...`) carguen — son parte de
  `web/public/`, Vercel los sirve como estáticos automáticamente.

---

## C. Railway (backend — `backend/` + MySQL)

### 1. Crear el servicio de MySQL

Desde el dashboard de Railway: **New → Database → MySQL**. Railway provisiona
la base y expone variables propias (entre otras, una URL de conexión
completa tipo `mysql://user:pass@host:port/database`).

### 2. Crear el servicio de Laravel

**New → GitHub Repo** → seleccionar este repo → **Root Directory: `backend`**.

Railway auto-detecta PHP/Composer vía Nixpacks. Este repo incluye
`backend/Procfile`:

```text
web: php artisan config:cache && php artisan route:cache && php artisan serve --host 0.0.0.0 --port $PORT
```

Esto alcanza perfectamente para el volumen de tráfico de una demo/beta con
testers externos (no es la configuración de máximo rendimiento para tráfico
alto — para eso más adelante se podría migrar a un `Dockerfile` con
PHP-FPM + Nginx, pero es sobreingeniería para esta etapa). Si Nixpacks no
levanta el servicio correctamente con el `Procfile` tal cual, la alternativa
documentada por Railway es su template oficial de Laravel (búsqueda "Laravel"
en Railway Templates), que sí trae un `Dockerfile` armado — usarlo como
referencia si hace falta.

### 3. Variables de entorno en Railway (servicio Laravel)

```env
APP_NAME="GameBackend"
APP_ENV=production
APP_KEY=                          # generar, ver paso 4
APP_DEBUG=false
APP_URL=https://<dominio-que-railway-te-asigne>

# Referenciar el plugin de MySQL por variable, no copiar valores a mano:
DB_CONNECTION=mysql
DB_URL=${{MySQL.MYSQL_URL}}

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# El/los dominio(s) reales de Vercel (separados por coma si hace falta
# más de uno — preview + producción). Nunca "*".
FRONTEND_URL=https://<tu-proyecto>.vercel.app

# Ver nota de Sanctum abajo — se dejan con su default, no tienen efecto
# real en esta arquitectura (auth es 100% Bearer token).
SANCTUM_STATEFUL_DOMAINS=localhost:4321
SESSION_DOMAIN=null

# Recomendado específicamente para Railway: así los logs aparecen en su
# visor de logs integrado en vez de quedar solo en storage/logs/ (que en
# Railway es efímero entre deploys).
LOG_CHANNEL=stderr
```

### 4. `APP_KEY`

Nunca reutilizar la que estaba en `.env.example` (ya se quitó de ahí). Generar
una nueva específica de producción:

```bash
# Opción A: correrlo localmente y pegar el resultado en Railway
php artisan key:generate --show

# Opción B: una vez el servicio esté arriba, desde la shell de Railway
php artisan key:generate
```

### 5. Migraciones y seed inicial

Desde la shell de Railway (o un "one-off command" del servicio), una sola vez
tras el primer deploy:

```bash
php artisan migrate --force
php artisan db:seed --force
```

`--force` es necesario porque `APP_ENV=production` bloquea estos comandos por
default sin esa bandera (protección propia de Laravel).

El seed en `production` queda automáticamente limpio: `DatabaseSeeder` ahora
salta la creación del "Test User" cuando `app()->environment('production')`
(cambio de esta fase), así que el resultado es exactamente lo que pedía la
tarea — catálogo completo (items, recetas, destinos de mascota, eventos
narrativos, equipamiento con sus bonos de stat) sin ningún personaje de
prueba ni usuario de desarrollo.

Los 7 seeders (`ItemSeeder`, `EconomyItemSeeder`, `RecipeSeeder`,
`RoomFurnitureSeeder`, `ArenaEquipmentSeeder`, `PetSeeder`,
`PetNarrativeEventSeeder`) usan `updateOrCreate` — correr `db:seed --force`
más de una vez no duplica nada.

### 6. Storage

Esta app no tiene subida de archivos de usuario (sin avatares, sin uploads),
así que **no hace falta** `php artisan storage:link`. Las carpetas
`storage/framework/*`/`storage/logs` ya existen vacías en git (cada una tiene
su propio `.gitignore` interno que preserva la carpeta pero ignora el
contenido) — Laravel las puede escribir apenas arranca.

### 7. CORS

`config/cors.php` ahora acepta una lista separada por comas en
`FRONTEND_URL` (cambio de esta fase) — nunca configurar `allowed_origins` como
`*`. Con `supports_credentials => true` (default de Laravel, sin efecto real
acá — ver Sanctum abajo) más un origin explícito, no hay combinación insegura
posible (los navegadores igual rechazan `*` + credentials).

### 8. Sanctum — por qué NO hace falta configurar nada especial cross-origin

Este es el punto que la tarea marcaba como crítico, y la auditoría encontró
algo tranquilizador: **este proyecto nunca usó el modo "SPA con cookies" de
Sanctum**. `ApiClient.ts` autentica 100% con `Authorization: Bearer <token>`
(personal access tokens de Sanctum, emitidos por `createToken()->
plainTextToken` y guardados en `localStorage`) — nunca manda cookies, nunca
pasa por `sanctum/csrf-cookie`, nunca depende de `SANCTUM_STATEFUL_DOMAINS` ni
de `SESSION_DOMAIN`. Confirmado revisando cada controller y el propio
`ApiClient.request()`.

Consecuencia práctica: **Bearer token funciona idéntico entre dominios
distintos** (Vercel + Railway) sin ninguna configuración extra de
cookies/SameSite/CSRF. Lo único que de verdad hace falta para que
Vercel↔Railway funcionen juntos es:

1. CORS (`FRONTEND_URL` en Railway apuntando al dominio real de Vercel).
2. `PUBLIC_API_URL` (en Vercel, apuntando al dominio real de Railway).

`SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` se dejan con su valor default sin
riesgo — son config muerta para esta arquitectura, no algo que haya que
"arreglar" para producción.

### 9. Rate limiting (agregado en esta fase)

Laravel 11 (a diferencia de 10) NO aplica `throttle:api` a las rutas de
`api.php` por defecto. Se agregó explícitamente:

- `bootstrap/app.php`: `$middleware->throttleApi()` — 60 req/min por usuario
  autenticado o IP (limiter definido en `AppServiceProvider`, mismo valor que
  Laravel usaba de default antes de la v11).
- `routes/api.php`: `/auth/login` y `/auth/register` además tienen
  `throttle:10,1` (10/min) — más estricto, porque son los blancos típicos de
  fuerza bruta/spam de cuentas.
- `/arena/attack` no necesitó un throttle dedicado: ya tiene su propio
  cooldown de 30 minutos POR OBJETIVO calculado server-side (`CombatService`),
  que es una forma de rate limiting más específica que un throttle genérico.

---

## D. Verificación

### Antes de dar el primer deploy por bueno

```text
[x] Landing (`/`) — identidad visual propia, ya no el scaffold de Astro
[x] Register — funcional, errores en español
[x] Login — funcional, errores en español
[x] Auth — Bearer token, funciona igual cross-origin (ver sección C.8)
[x] Play — mundo, colisión, NPC
[x] Inventory — equipar/desequipar/vender (sin panel de "Obtener" — quitado en esta fase)
[x] Crafting — 30 recetas, filtros
[x] Pet — expediciones + bitácora narrativa
[x] House — furniture colocable/movible, server-authoritative
[x] Arena — combate, ranking, cooldown
[ ] API pública real respondiendo (pendiente: recién se sabe con Railway desplegado)
[ ] MySQL de Railway con migrate+seed corridos (pendiente del primer deploy)
[ ] HTTPS en ambos extremos (Vercel y Railway lo dan por default)
[ ] CORS apuntando al dominio real de Vercel (pendiente: falta el dominio real)
[ ] Sanctum — no requiere acción extra (ver C.8)
[x] Assets — todos bajo /assets, sin rutas de Windows ni localhost hardcodeadas
[x] Sin errores de consola (verificado con Playwright, ver reporte)
[x] Sin referencias a localhost fuera de los defaults de env vars
[x] Sin secretos en el repo (`.env` nunca trackeado, `APP_KEY` real removida del .example)
```

Los últimos 4 ítems sin marcar son, por diseño, los que **no se pueden
verificar hasta que existan los dominios reales de Vercel/Railway** — esta
fase dejó todo preparado para que, apenas existan esos dominios, sea
cuestión de cargar 2 variables de entorno (`PUBLIC_API_URL` en Vercel,
`FRONTEND_URL` en Railway) y correr `migrate --force && db:seed --force` una
vez.
