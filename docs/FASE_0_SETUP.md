# Fase 0 — Arquitectura y Setup

Objetivo de esta fase: tener Astro + Phaser + Laravel + MySQL corriendo en tu máquina local, conectados entre sí, sin ningún sistema de gameplay todavía. El criterio de "terminado" es: `http://localhost:4321/play` muestra el mensaje **"API OK"** obtenido en vivo desde Laravel.

Todo lo de este documento se ejecuta en **tu máquina** (VS Code + terminal), no en un entorno remoto — es tu proyecto real, el que vas a versionar en Git.

---

## 0. Prerrequisitos

Verifica versiones en tu terminal:

```powershell
php -v          # 8.2 o superior (Laravel 11 lo requiere)
composer -V
node -v          # 22.12 o superior
npm -v
mysql --version
git --version
```

Si `php` o `composer` no existen en Windows, la forma más simple es instalar [Laravel Herd](https://herd.laravel.com/) (incluye PHP, Composer y un servidor local) o XAMPP/Laragon. Cualquiera sirve; no es una decisión de arquitectura, es solo tu entorno.

---

## 1. Estructura y Git

```powershell
mkdir project
cd project
git init
```

Copia dentro de `project/` el `.gitignore` raíz y la carpeta `web/` que te entrego en el archivo adjunto (ya trae Astro instalado + Phaser configurado). La carpeta `backend/` la generas tú en el siguiente paso, porque Composer necesita instalar contra Packagist y este entorno de chat no tiene salida a internet hacia Packagist.

---

## 2. Backend — crear el proyecto Laravel 11

Desde `project/`:

```powershell
composer create-project laravel/laravel backend "^11.0"
cd backend
php artisan install:api
```

`php artisan install:api` es el comando de Laravel 11 que:
- registra `routes/api.php` (en Laravel 11 no viene registrado por defecto, a diferencia de Laravel 10),
- instala y configura **Sanctum** automáticamente.

Esto nos deja listos para la Fase 1 (autenticación) sin trabajo extra, aunque en Fase 0 no lo usemos todavía.

### Configurar entorno

Copia los valores del `backend/.env.example` que te entrego (DB, `SANCTUM_STATEFUL_DOMAINS`, `FRONTEND_URL`) dentro del `.env` real que generó Laravel, y genera la key de la app:

```powershell
php artisan key:generate
```

### Base de datos

Crea la base vacía (ajusta usuario/password a tu instalación de MySQL):

```sql
CREATE DATABASE game_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Luego corre las migraciones **por defecto** de Laravel (usuarios, cache, jobs) — las nuestras (`characters`, `items`, etc.) llegan en la Fase 2 en adelante, a propósito, para no adelantar trabajo de otra fase:

```powershell
php artisan migrate
```

### Ruta de verificación (`/api/v1/ping`)

Abre `routes/api.php` y agrega esto (es el único código de negocio que toca esta fase, y es deliberadamente trivial):

```php
Route::get('/v1/ping', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'laravel-backend',
        'time' => now()->toIso8601String(),
    ]);
});
```

### CORS

Abre `config/cors.php` y ajusta:

```php
'paths' => ['api/*'],
'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:4321')],
'supports_credentials' => true,
```

Esto es necesario porque Astro (`localhost:4321`) y Laravel (`localhost:8000`) corren en orígenes distintos en desarrollo.

### Levantar el backend

```powershell
php artisan serve
```

Deberías poder abrir `http://localhost:8000/api/v1/ping` en el navegador y ver el JSON de estado.

---

## 3. Frontend — Astro + Phaser

La carpeta `web/` que te entrego ya incluye:
- Astro instalado (`astro.config.mjs`, `tsconfig.json`)
- `package.json` con `phaser` y `typescript` agregados
- `src/game/main.ts` + `BootScene.ts`: una escena Phaser mínima que pinta texto y llama a `pingApi()`
- `src/game/net/ApiClient.ts`: el único punto por donde el juego habla con Laravel (fase 0 = solo un `ping`)
- `src/pages/play.astro`: la página que monta el canvas
- `public/assets/manifest.json`: el manifest vacío del sistema de assets desacoplado (se empieza a llenar en Fase 3)

```powershell
cd project/web
copy .env.example .env
npm install
npm run dev
```

Abre `http://localhost:4321/play`.

---

## 4. Resultado esperado

En `http://localhost:4321/play` deberías ver, sobre fondo oscuro:

```
Fase 0: Phaser está corriendo
API OK: {"status":"ok","service":"laravel-backend","time":"..."}
```

Si en vez de eso ves **"API sin respuesta"**, casi siempre es uno de estos tres problemas, en este orden de probabilidad:
1. `php artisan serve` no está corriendo, o corre en otro puerto.
2. CORS mal configurado (`allowed_origins` no coincide con `localhost:4321`).
3. `PUBLIC_API_URL` en `web/.env` no apunta a `http://localhost:8000`.

---

## 5. Qué NO se hizo en esta fase (a propósito)

- Ninguna tabla de negocio (`characters`, `items`, etc.) — llegan en Fase 2+.
- Ninguna autenticación real todavía, aunque Sanctum ya quedó instalado y listo para usarse en Fase 1.
- Ningún asset real cargado — el `manifest.json` está vacío intencionalmente.
- Ningún deploy — esto es 100% entorno local.

---

## 6. Comandos Artisan de desarrollo (punto 7 de tus decisiones)

Estos comandos personalizados (`item:grant`, `character:set-level`, `expedition:complete`, `dev:reset`) se crean recién cuando exista algo que manipular — es decir, a partir de la Fase 5 (inventario) en adelante. Crearlos ahora estaría vacío de contenido; quedan anotados en el roadmap para no olvidarlos.
