# Análisis: migración de estrategia de deploy del backend a Render + Docker

> Las secciones 1-11 son la auditoría/análisis original (sin cambios de
> código, comparando PostgreSQL vs MySQL externo). La decisión final fue
> **Render + Docker + PostgreSQL (Neon)** — la implementación real está
> documentada al final, en "Implementación Render + Neon".

---

## 1-7. Cómo está estructurado el backend hoy

| Punto | Respuesta |
|---|---|
| Framework | Laravel **^11.0**, Sanctum **^4.0** |
| PHP | **^8.2** (local: 8.2.12 ZTS) |
| Extensiones PHP cargadas hoy | `pdo_mysql`, `pdo_sqlite`, `mysqli`, `mbstring`, `bcmath`, `openssl`, `curl`, `json`, `zip`, etc. — **`pdo_pgsql` NO está instalada localmente** (hay que agregarla al `Dockerfile` si se elige Postgres; es una línea, no un problema) |
| Cómo corre en local | XAMPP (Apache+MariaDB) + `php artisan serve` para la API; sin Docker, sin Nginx/PHP-FPM propios todavía |
| Nginx/PHP-FPM | No existe configuración propia — en Docker se resuelve con una imagen base (`php:8.2-fpm` + Nginx, o más simple, `php:8.2-cli` sirviendo con `php artisan serve` dentro del contenedor, suficiente para el volumen de una beta) |
| Variables de entorno | Las de Laravel estándar (`APP_*`, `DB_*`, `SESSION_*`, `CACHE_*`, `QUEUE_*`) + `FRONTEND_URL` (propia, para CORS) + `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` (sin efecto real, ver más abajo) |
| Tablas/migraciones | 23 migraciones, todas con `foreignId()->constrained()->cascade/nullOnDelete()`, columnas `unsignedInteger`, `json()`, `dateTime()`, 2 `CHECK` constraints agregados con `DB::statement()` crudo. Nada de `->enum()` nativo (el proyecto usa enums de PHP sobre columnas `string` a propósito, documentado en el propio código) |

## 8. Funcionalidades específicas de MySQL/MariaDB encontradas

Revisé **todo** `app/` y `database/` buscando `DB::raw`, `DB::statement`,
`whereRaw`, `selectRaw`, `orderByRaw`, `->enum(`, `->json(` y funciones
específicas de motor. Resultado:

### Encontrado y 100% portable sin cambios

- `ArenaRankingService.php`: `DB::raw('count(*) as c')` — SQL ANSI estándar,
  funciona idéntico en PostgreSQL.
- Dos `CHECK` constraints vía `DB::statement('ALTER TABLE ... ADD CONSTRAINT
  ... CHECK (...)')` (`inventory_items.quantity >= 1`,
  `pet_destinations.difficulty BETWEEN 1 AND 5`) — sintaxis SQL estándar,
  **PostgreSQL la soporta idéntica** (de hecho Postgres siempre enforceó
  CHECK constraints correctamente; MySQL recién lo hizo confiable desde
  8.0.16 — Postgres es, si acaso, más seguro acá).
- 7 columnas `json()` cast a `'array'` en los modelos Eloquent — Postgres
  tiene tipo `json` nativo, Laravel lo mapea igual, y como **no hay ninguna
  query con sintaxis de path JSON** (`->`, `->>` de MySQL vs Postgres son
  distintos, pero no se usa ninguna), no hay nada que traducir.
- `unsignedInteger`/`unsignedBigInteger` en 13 migraciones — PostgreSQL no
  tiene tipos unsigned. Confirmé en el código fuente de Laravel
  (`PostgresGrammar.php`) que el grammar de Postgres **ignora el modificador
  silenciosamente** (no rompe, no hace falta tocar la migración): la columna
  queda como `INTEGER`/`BIGINT` normal. Efecto práctico: Postgres no
  rechazaría un valor negativo a nivel de columna como sí lo haría MySQL —
  pero ninguna lógica de la app intenta escribir un negativo ahí (level/exp/
  stats/coins solo se incrementan vía servicios), así que es una diferencia
  teórica sin impacto real.

### Encontrado y SÍ requiere un cambio (el único real)

- `characters` (migración `2026_09_13_000001`): el default de
  `appearance_json` usa `JSON_OBJECT('body', 'base')` como expresión SQL
  cruda. **`JSON_OBJECT` con esa sintaxis es específico de MySQL/MariaDB** —
  PostgreSQL no tiene esa función con ese nombre/firma (Postgres usaría
  `json_build_object(...)`, y aun así depende de la versión). Si se corriera
  esta migración tal cual contra Postgres, **fallaría**.

  **Fix propuesto (no aplicado todavía)**: sacar el default de la base de
  datos y ponerlo en el modelo Eloquent (`Character::$attributes` o el
  constructor), algo como:
  ```php
  protected $attributes = [
      'appearance_json' => '{"body":"base"}',
  ];
  ```
  Esto es en realidad **más portable que la solución actual incluso si nos
  quedáramos en MySQL** — deja de depender de una función de motor
  específico para algo que Eloquent ya puede resolver solo. Es un cambio de
  ~3 líneas en un solo archivo, no una migración destructiva (los personajes
  ya creados no se tocan; solo cambia cómo se genera el default para
  personajes nuevos).

**Conclusión de compatibilidad: el código está a un cambio de 3 líneas de
ser 100% portable a PostgreSQL.** No hay ninguna otra dependencia de motor
en 24 migraciones, ~15 modelos y todos los services/controllers.

## 9. ¿Puede Laravel migrar a PostgreSQL sin cambios importantes?

**Sí.** Un solo punto de incompatibilidad real (arriba), ya identificado y
con fix trivial. Todo lo demás (relaciones, casts, transacciones,
`lockForUpdate()` usado en `InventoryGrantService`/`CraftingService`,
`DatabaseTransactions` en los tests) es funcionalidad de Eloquent/Laravel
que abstrae el motor — no hay SQL crudo dependiente de MySQL en ningún otro
lado del código.

---

## 10. Comparación: Render+Docker+PostgreSQL vs Render+Docker+MySQL externo

| Criterio | A) Render + Docker + PostgreSQL | B) Render + Docker + MySQL externo |
|---|---|---|
| **Dificultad** | Baja — 1 archivo a cambiar (default de `appearance_json`), `pdo_pgsql` en el Dockerfile, `DB_CONNECTION=pgsql` | Baja en Laravel, pero **alta en encontrar el proveedor** (ver abajo) |
| **Cambios en Laravel** | 1 migración (mover el default a modelo), agregar `pdo_pgsql` al Dockerfile | Ninguno — el driver `mysql` sigue igual |
| **Compatibilidad con código actual** | 99% ya compatible (ver sección 8-9) | 100% compatible (mismo motor) |
| **Costo/free tier** | **Render ofrece PostgreSQL como servicio nativo**, con free tier real (gratis, se borra a los 90 días si no se paga — pero da tiempo de sobra para un ciclo de beta, con upgrade a $7/mes si hace falta seguir) | **Render NO ofrece MySQL como servicio propio** — nunca lo tuvo. Hay que buscar un tercero. PlanetScale (que era la opción free más conocida) **discontinuó su free tier en 2024**. Alternativas actuales genuinamente gratis y confiables para MySQL externo son escasas (Aiven es trial, no permanente; Railway es justo lo que se quiere evitar) |
| **Limitaciones** | Free tier de Postgres expira a los 90 días si no se pasa a pago; el web service gratis de Render hace cold-start (~30-50s) tras 15 min de inactividad — esto pasa **igual en ambas opciones**, es del hosting del backend, no de la DB | Depender de un tercero fuera del ecosistema Render agrega latencia (DB en otra región/proveedor), un panel más para administrar, y el riesgo de que ese proveedor también cambie sus términos de free tier (como ya pasó con PlanetScale) |
| **Mantenimiento** | Un solo panel (Render) para backend + DB, backups nativos incluidos en el plan pago | Dos paneles distintos, backups a gestionar en el proveedor externo por separado |
| **Riesgo de pérdida de datos** | Bajo — sin datos reales de producción todavía (proyecto pre-lanzamiento, el plan ya era `migrate --force && db:seed --force` desde cero, no migrar datos existentes) | Igual de bajo por la misma razón, pero con un punto de falla más (el proveedor externo) |
| **Deploy desde GitHub** | Nativo: Render conecta el repo, detecta el `Dockerfile`, redeploya en cada push | Igual para el backend; la DB externa no se despliega desde Render, es una conexión aparte que hay que configurar a mano |
| **Adecuación para el MVP** | **Alta** — un solo proveedor, camino de upgrade claro, sin depender de que un tercero mantenga su free tier | **Baja/incierta** — el "free tier real" que pedís explícitamente es hoy más difícil de conseguir en MySQL que en Postgres, precisamente porque Render no lo ofrece nativo |

### C) Otra alternativa a considerar: Render (Docker) + Neon (Postgres externo, gratis sin vencimiento)

Vale la pena mencionarla porque resuelve la única limitación real de la
opción A (el límite de 90 días del Postgres nativo de Render):
**[Neon](https://neon.tech)** ofrece PostgreSQL serverless con un free tier
que **no expira** (con límites de cómputo/almacenamiento generosos para un
MVP), pensado exactamente para conectarse desde un backend externo por
connection string — funciona igual de bien con `config/database.php`
(driver `pgsql`, ya existe en el proyecto) que el Postgres nativo de Render.
Combinar "Render solo para el contenedor de Laravel" + "Neon para la DB" da
el free tier más duradero de las tres opciones, al costo de un panel más
para administrar (mismo trade-off que la opción B, pero con un proveedor
de Postgres serio y con free tier permanente, no un MySQL de dudosa
continuidad).

---

## Recomendación

**Opción A, con la variante C como refinamiento: Render (Docker) + PostgreSQL — nativo de Render para empezar rápido, con la puerta abierta a migrar la conexión a Neon si el límite de 90 días del free tier de Render se vuelve un problema real** (cambiar de Postgres-de-Render a Neon después es solo cambiar `DB_URL`, cero código, porque ambos son PostgreSQL estándar).

Por qué, en orden de peso:

1. **Es la que de verdad tiene un free tier confiable hoy.** Render no ofrece
   MySQL nativo, y el ecosistema de MySQL gratis de terceros se debilitó
   (PlanetScale cerró su free tier). Buscar "MySQL externo gratis" en 2026
   es un camino con más riesgo de que se corte que el propio Render/Neon
   para Postgres.
2. **La incompatibilidad de código es mínima y ya identificada**: un solo
   default de columna (`JSON_OBJECT`) en un solo archivo, con un fix de 3
   líneas que además deja el código más portable que hoy.
3. **Un solo proveedor (Render) para contenedor + DB** simplifica el
   mantenimiento de un MVP con recursos limitados — menos paneles, menos
   puntos de falla, deploy y rollback desde el mismo lugar.
4. El riesgo de pérdida de datos es bajo en cualquiera de las dos opciones
   porque no hay datos de producción reales que migrar todavía.

Si en algún momento se prefiere evitar por completo cualquier vencimiento de
free tier desde el día uno, arrancar directo con **Render + Neon** (en vez de
Render + Postgres-nativo) es la variante más conservadora, al mismo costo de
implementación.

---

## Qué se necesita, cuando decidamos implementar (no hecho todavía)

Checklist de lo que pediste para producción, ya identificado y listo para
cuando confirmes que avanzamos:

- [ ] Mover el default de `appearance_json` de SQL crudo (`JSON_OBJECT`) al
      modelo `Character` — el único cambio de código real.
- [ ] `Dockerfile` para `backend/` (PHP 8.2 + extensiones incl. `pdo_pgsql`
      o `pdo_mysql` según se decida, Composer, `artisan serve` o PHP-FPM+Nginx).
- [ ] `DB_CONNECTION=pgsql` (o mantener `mysql` si se elige la opción B) +
      `DB_URL`/`DB_HOST`/etc. como variables de entorno de Render — nunca
      hardcodeadas.
- [ ] `APP_KEY` generada para producción, vía variable de entorno de Render
      (no la del `.env.example`, que ya quedó vacía en la fase anterior).
- [ ] `APP_URL` = dominio real de Render.
- [ ] `FRONTEND_URL`/CORS apuntando al dominio real de Vercel (ya preparado
      en `config/cors.php` desde la fase anterior — soporta lista separada
      por comas).
- [ ] Migraciones + seed como *pre-deploy command* de Render (`php artisan
      migrate --force && php artisan db:seed --force`), no manual.
- [ ] HTTPS: Render lo da automático en su dominio `.onrender.com` (o con
      dominio propio + certificado gestionado).
- [ ] Logs: `LOG_CHANNEL=stderr` para que aparezcan en el visor de logs de
      Render (mismo razonamiento que se documentó para Railway — ya no
      aplica, pero el valor recomendado es el mismo).
- [ ] Health check: Laravel 11 ya trae `/up` registrado por default
      (`bootstrap/app.php` → `health: '/up'`) — Render puede usarlo tal cual
      como health check endpoint del servicio, sin crear uno nuevo.
- [ ] El `Procfile` armado para Railway en la fase anterior queda obsoleto
      con Docker+Render (Render usa el `Dockerfile` directamente, no
      Procfile) — se elimina cuando implementemos, no ahora.

**No implementé nada de esto todavía** — queda a la espera de que confirmes
la opción (A, la variante con Neon, o B) antes de tocar código, Dockerfile o
migraciones.

---

## Implementación Render + Neon

> Ya implementado en el repo. Esta sección documenta exactamente qué se
> hizo, qué quedó pendiente de acción manual, y qué se pudo/no se pudo
> verificar localmente.

### 1. Archivos creados/modificados

**Modificados:**
- `database/migrations/2026_09_13_000001_create_characters_table.php` —
  quitado el default `JSON_OBJECT('body', 'base')` (específico de MySQL/
  MariaDB); la columna `appearance_json` queda como `json()` simple, sin
  default a nivel de base de datos.
- `app/Models/Character.php` — agregado `protected $attributes =
  ['appearance_json' => '{"body":"base"}'];`, mismo valor que antes pero
  resuelto por Eloquent (portable a cualquier motor), no por SQL crudo.
- `backend/.env.example` — sección de `DB_*` reescrita para documentar
  explícitamente LOCAL (MariaDB/XAMPP, sin cambios) vs PRODUCCIÓN
  (PostgreSQL/Neon vía `DB_URL`); referencias a "Railway" reemplazadas por
  "Render" donde correspondía.
- `web/.env.example` — comentario actualizado de "Railway" a "Render".

**Nuevos:**
- `backend/Dockerfile`
- `backend/.dockerignore`
- `backend/docker/entrypoint.sh`
- `render.yaml` (raíz del repo — Render Blueprint)

**Eliminado:**
- `backend/Procfile` — era específico de Railway (que se descartó);
  Render con Docker usa el `Dockerfile` directamente, el Procfile quedaba
  obsoleto y potencialmente confuso. Ya estaba anticipado en la sección
  "Qué se necesita" de este mismo documento antes de implementar.

### 2. Configuración Docker

`backend/Dockerfile`: base `php:8.2-apache` (un solo proceso en el
contenedor — Apache+mod_php, sin Nginx/PHP-FPM/supervisord separados,
porque el proyecto no tiene necesidad real de esa complejidad para el
volumen de tráfico de una beta). Extensiones instaladas: `pdo_mysql`
(se mantiene por compatibilidad, no estorba), `pdo_pgsql` (la que
realmente se usa en producción), `mbstring`, `bcmath`, `zip` — verificadas
contra el uso real del código (sin GD/Imagick/colas Redis en este
proyecto). Docroot apuntado a `public/` vía `APACHE_DOCUMENT_ROOT` +
`sed` sobre la config de Apache. `storage/`/`bootstrap/cache/` con
ownership de `www-data`.

`docker/entrypoint.sh`: reconfigura el puerto de Apache a partir de
`$PORT` (Render lo inyecta en runtime, no existe en build time), corre
`config:cache`/`route:cache`/`view:cache` (verificado antes: no hay
ningún `env()` fuera de archivos `config/` en toda la app, así que
cachear config en runtime — después de que las variables de Render ya
están disponibles — es seguro), y **deliberadamente no ejecuta
migraciones** (eso es el Pre-Deploy Command de Render, no algo que deba
correr en cada arranque/restart del contenedor).

`.dockerignore`: excluye `vendor/`, `.env`, `tests/`, logs/cache de
`storage/`, `.git` — build context más chico y sin secretos locales
adentro de la imagen.

### 3. Variables de entorno

Ver sección 8 más abajo — están declaradas (sin valores reales) en
`render.yaml` y documentadas en `backend/.env.example`.

### 4. Configuración Render

`render.yaml` (raíz del repo, `rootDir: backend`) declara: `runtime:
docker`, `plan: free`, `healthCheckPath: /up`, y la lista completa de env
vars (con `sync: false` en las que son secretas — Render las deja vacías
para completar a mano). Es un punto de partida reproducible vía "Render →
New → Blueprint"; no reemplaza revisar la configuración real en el
dashboard tras crear el servicio. **Nota:** el plan Free no ofrece
`preDeployCommand` ni shell/SSH — ver sección 14 para dónde corren las
migraciones en la práctica.

### 5. Configuración Neon

No se creó ninguna cuenta/proyecto real en Neon (fuera del alcance de lo
que puedo hacer yo). Lo que se dejó preparado: `config/database.php` ya
tenía el bloque `pgsql` completo de fábrica (sin tocar), que lee `DB_URL`
de forma nativa — la connection string que Neon entrega
(`postgres://user:pass@host/db`) se puede pegar tal cual como `DB_URL` en
Render sin descomponerla en host/puerto/usuario/contraseña por separado.

### 6. Migraciones (ya no es un Pre-deploy command — ver sección 14)

```bash
php artisan migrate --force
```

**Desactualizado:** esto se planeó originalmente como
`preDeployCommand` en `render.yaml`, pero el plan Free de Render no lo
ofrece (ni shell/SSH ni one-off jobs). En la práctica corre dentro de
`docker/entrypoint.sh`, en cada arranque del contenedor — ver sección 14
para el detalle completo, incluida la causa raíz de un `SQLSTATE[25P02]`
real que apareció acá y cómo se resolvió. **No** incluye `db:seed`
automático a propósito: los seeders (`ItemSeeder`,
`EconomyItemSeeder`, `RecipeSeeder`, `RoomFurnitureSeeder`,
`ArenaEquipmentSeeder`, `PetSeeder`, `PetNarrativeEventSeeder`) ya usan
`updateOrCreate` (idempotentes, se pueden correr más de una vez sin
duplicar) y ya están gateados para no crear el "Test User" en
`APP_ENV=production` (cambio de la fase anterior) — pero correrlos es
una acción manual explícita la primera vez (`php artisan db:seed --force`
desde la shell de Render), no automática en cada deploy, para no
sorprender con un re-seed si en el futuro se agrega un seeder nuevo.

### 7. Start command

`CMD ["apache2-foreground"]` en el `Dockerfile`, envuelto por
`ENTRYPOINT ["entrypoint.sh"]` (que hace el setup de puerto/cache y
después hace `exec "$@"` para reemplazar su propio proceso por Apache —
Apache queda como PID 1 del contenedor, señales de Render (SIGTERM en
redeploy/scale-down) le llegan directo).

### 8. Health check

`/up` — ya registrado por Laravel 11 en `bootstrap/app.php`
(`health: '/up'`, sin tocar). Declarado en `render.yaml` como
`healthCheckPath: /up`. No se creó ningún endpoint nuevo.

### 9. Configuración Vercel

Sin cambios de código: `ApiClient.ts` ya leía `PUBLIC_API_URL` desde una
variable de entorno (desde la fase de deploy anterior), y sigue siendo el
único punto de entrada de red del frontend. Lo único que cambió es el
comentario de `web/.env.example` (decía "Railway", ahora dice "Render").
Cuando exista el dominio real de Render, la acción pendiente es
puramente de configuración en el dashboard de Vercel (`PUBLIC_API_URL`),
no de código — y, como ya estaba documentado, requiere rebuild porque
Astro lo incrusta en build time.

### 10. Pasos manuales pendientes (para vos)

1. Crear la base en [Neon](https://neon.tech), copiar la "Connection
   string" (`postgres://...`).
2. Crear el servicio en Render — vía Blueprint (`render.yaml`, "New →
   Blueprint") o manual ("New → Web Service" → Docker → root `backend`).
3. Completar en Render las variables marcadas `sync: false`: `APP_KEY`
   (generar con `php artisan key:generate --show`), `APP_URL` (el dominio
   que Render asigne), `DB_URL` (la connection string de Neon),
   `FRONTEND_URL` (el dominio de Vercel).
4. Tras el primer deploy exitoso: desde la shell de Render, correr una
   vez `php artisan db:seed --force` (las migraciones ya corren solas
   como pre-deploy).
5. En Vercel: configurar `PUBLIC_API_URL` con el dominio real de Render y
   redeployar (rebuild, no alcanza con solo guardar la variable).
6. Revisar `/up` responde 200 desde el dominio público de Render.
7. Revisar un login/registro real end-to-end desde el dominio de Vercel
   contra el backend de Render (confirma CORS + Bearer token cross-origin
   funcionando de verdad, no solo en teoría).

### 11. Checklist de deploy

```text
[ ] Cuenta/proyecto Neon creado, connection string copiada
[ ] Servicio Render creado (Blueprint o manual), Dockerfile buildea bien
[ ] APP_KEY generada y cargada en Render
[ ] DB_URL de Neon cargada en Render
[ ] APP_URL/FRONTEND_URL cargadas en Render
[ ] Pre-deploy corrió `migrate --force` sin errores
[ ] db:seed --force corrido una vez manualmente
[ ] /up responde 200 en el dominio público de Render
[ ] PUBLIC_API_URL configurada en Vercel + redeploy
[ ] Login/registro real funciona cross-origin (Vercel → Render)
[ ] Consola del navegador sin errores CORS
```

### 12. Pruebas realizadas (local, sin tocar el entorno de desarrollo)

Todo lo de abajo se corrió contra el **MariaDB de XAMPP existente, sin
tocarlo** (ninguna migración destructiva, ninguna base borrada/recreada):

- `php artisan test` → **93/93 passed**, corrido después de cada cambio
  (migración editada, modelo editado) — confirma que el fix de
  `appearance_json` no rompió nada de lo existente.
- `php artisan tinker` → `(new Character())->appearance_json` devuelve
  `['body' => 'base']` — confirma que el default de Eloquent funciona
  exactamente igual que el default de SQL que reemplazó.
- **Verificación de portabilidad real** (no solo teórica): corrí `php
  artisan migrate` completo contra un archivo SQLite **temporal y
  aislado** (creado en el scratchpad de la sesión, borrado al terminar —
  nunca tocó `database/database.sqlite` ni el MariaDB real). Resultado:
  la migración de `characters` (la que se editó) corrió **exitosamente**
  contra un motor que no es MySQL — confirma que ya no depende de
  `JSON_OBJECT`. La migración se detuvo más adelante en
  `inventory_items` por `ALTER TABLE ... ADD CONSTRAINT ... CHECK`, que
  es una **limitación de SQLite específicamente** (SQLite no soporta
  agregar constraints vía `ALTER TABLE` en absoluto) — no evidencia de un
  problema con PostgreSQL, que sí soporta esa sintaxis exacta de forma
  nativa (ya verificado en la sección de análisis original).
- Sintaxis de `docker/entrypoint.sh` validada con `bash -n` (sin errores).
- `bash -n`/revisión manual línea por línea del `Dockerfile` — no se pudo
  validar de forma automática porque **Docker no está disponible en este
  entorno** (confirmado: `docker --version` no encuentra el binario).

### 13. Limitaciones y riesgos que quedan

- **El `docker build` real nunca se ejecutó** — no hay Docker instalado en
  este entorno de trabajo. El Dockerfile se escribió y revisó a mano
  siguiendo el patrón oficial y ampliamente documentado de
  `php:8.2-apache` + Laravel, pero el primer build real va a pasar recién
  en Render (o localmente si tenés Docker Desktop y querés probarlo antes
  con `docker build -t game-backend backend/`).
- **No se probó una conexión real a Neon** — no hay credenciales de Neon
  disponibles en este entorno. `DB_URL` con el bloque `pgsql` de
  `config/database.php` es el mecanismo estándar de Laravel y debería
  funcionar sin sorpresas, pero recién se confirma con el primer deploy
  real.
- **Las 2 migraciones con `CHECK` constraints no se probaron contra
  PostgreSQL real** — la verificación fue por lectura del código SQL
  (sintaxis estándar, ya usada así en MySQL 8+) más el hecho de que
  Postgres es históricamente el motor MÁS estricto con constraints, no
  menos. Riesgo bajo, pero es el punto a mirar primero si `migrate
  --force` fallara en el primer deploy.
- El resto de los riesgos (cold start del free tier, expiración de
  disco/DB a los 90 días si se usa el Postgres nativo de Render en vez de
  Neon, etc.) ya estaban documentados en la sección de análisis original
  y no cambiaron.

## 14. Render Free sin Pre-Deploy/shell + SQLSTATE[25P02] en el primer deploy real

### 14.1 Migraciones movidas a `entrypoint.sh`

Al crear el servicio real se confirmó que el plan Free de Render **no**
ofrece Pre-Deploy Command, shell/SSH ni one-off jobs — no hay forma de
correr `migrate --force` por fuera del arranque del contenedor. Se movió
a `docker/entrypoint.sh`, antes de levantar Apache, corriendo en cada
arranque (deploy y restart/spin-up desde sleep). Es idempotente por
diseño de Laravel (tabla `migrations`), así que un restart sin
migraciones pendientes es un no-op. Riesgo aceptado y documentado en el
propio `entrypoint.sh`: si Render llegara a solapar brevemente contenedor
viejo/nuevo durante un deploy con migraciones nuevas, el segundo en
llegar fallaría al arrancar (Postgres rechaza el CREATE/ALTER duplicado)
— bajo riesgo en Free (una sola instancia, sin scaling horizontal), sin
alternativa disponible en este plan.

### 14.2 SQLSTATE[25P02] en la primera migración real y su causa raíz

El primer `migrate --force` real (contra Neon) falló en
`0001_01_01_000000_create_users_table` con
`SQLSTATE[25P02]: In failed sql transaction` en el
`ALTER TABLE ... ADD CONSTRAINT users_email_unique UNIQUE (email)`. 25P02
siempre significa que un statement *anterior*, en la misma transacción,
falló primero y la dejó abortada — el error visible es un síntoma
secundario, nunca la causa real.

Investigación en 3 rondas, cada una descartando una hipótesis con
evidencia directa (nunca asumida):

1. **Tabla `users` preexistente/stale** — descartada: probado a mano en
   el SQL Editor de Neon que `users` no existía y que el CREATE+ALTER
   equivalente corre sin errores ahí.
2. **Conexión/credenciales/schema mal resueltos** — descartadas leyendo
   config en runtime (`current_database()`/`current_user`/
   `current_schema()`/`search_path`, todos correctos) y confirmando que
   `ConfigurationUrlParser` de Laravel sí mergea `DB_URL` bien en el
   connection real (una lectura ingenua de `config('database.connections.
   pgsql')` engaña — muestra los defaults estáticos pre-merge, no lo que
   se usa para conectar de verdad).
3. **SQL/Blueprint incompatible con Postgres** — descartada: el
   `CREATE TABLE` + `ALTER TABLE ... ADD CONSTRAINT ... UNIQUE`
   exactamente como lo genera el Schema Builder de Laravel, reproducido
   en un proceso PHP real contra el mismo `DB_URL`, corrió perfecto
   **sin** una transacción explícita envolviéndolo.
4. **El dato decisivo:** `Migrator::runMigration()` envuelve el `up()` de
   cada migración en `Connection->transaction()` (confirmado en el stack
   trace real, `Migrator.php:440`). Reproducir la MISMA secuencia dentro
   de `DB::transaction()` (y también a mano con `beginTransaction()`/
   `commit()`) reprodujo el 25P02 tal cual — la falla es específica de
   correr varios statements DDL dentro de una transacción explícita.

**Causa raíz confirmada:** `DB_URL` apunta al endpoint *pooled* de Neon
(host con sufijo `-pooler`), que corre PgBouncer en modo `transaction`.
La [documentación oficial de Neon para
Laravel](https://neon.com/docs/guides/laravel-migrations) dice
explícitamente que usar el connection string pooled para migraciones "can
be prone to errors" y recomienda usar el connection string **directo**
(sin pooler) solo para migrar, reservando el pooled para el tráfico
normal de la app — exactamente el patrón de fallo reproducido acá
(multi-statement DDL en una transacción explícita, sobre el pooler).

### 14.3 Fix aplicado

- `render.yaml`: nueva env var `DB_URL_MIGRATE` (`sync: false`) — el
  connection string **directo** de Neon (Neon dashboard → Connection
  Details → desactivar "Connection pooling" → esa otra URL, host sin
  `-pooler`). `DB_URL` (pooled) se deja intacto, sigue siendo lo que usa
  la app en runtime.
- `docker/entrypoint.sh`: `php artisan migrate --force` ahora corre con
  `DB_URL` sobreescrito puntualmente a `DB_URL_MIGRATE` (override de
  proceso, vía `DB_URL="$DB_URL_MIGRATE" php artisan migrate --force` —
  no toca el `DB_URL` del resto del proceso/Apache). Si `DB_URL_MIGRATE`
  no está seteada todavía, cae de vuelta al `DB_URL` pooled con un
  `[WARN]` explícito en los logs, en vez de romper el arranque.
- Se quitó el bloque temporal de diagnóstico (rondas 1-3) de
  `entrypoint.sh` — ya cumplió su propósito. `migrate --force` volvió a
  su forma normal (sin `--path`, corre las 24 migraciones pendientes;
  sin `-vvv`).

**Pendiente (acción manual, no de código):** crear/copiar en el
dashboard de Neon el connection string directo y cargarlo como
`DB_URL_MIGRATE` en Render antes del próximo deploy. Sin ese paso, el
`[WARN]` de arriba avisa en los logs y el 25P02 puede repetirse.
