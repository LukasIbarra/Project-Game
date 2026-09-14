#!/bin/sh
set -e

# Render inyecta $PORT en runtime (no está disponible en build) — Apache
# viene configurado para escuchar en 80 por default, así que se reescribe
# acá, cada vez que arranca el contenedor.
PORT="${PORT:-80}"
sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# ============================================================================
# BLOQUE TEMPORAL DE DIAGNÓSTICO — investigando SQLSTATE[25P02] en
# users_email_unique (ver docs/RENDER_DEPLOYMENT_ANALYSIS.md). Revertir
# quitando todo entre BEGIN/END DIAGNOSTIC (y volver `-vvv` a nada) una vez
# resuelto. Solo lectura -ninguna sentencia acá escribe nada-, y no puede
# tumbar el arranque: cada query va en su propio try/catch, y el bloque
# entero tiene `|| true` al final para que, pase lo que pase acá, se siga
# igual a la migración real de abajo.
# ============================================================================
# BEGIN DIAGNOSTIC
#
# Ronda 2 de diagnóstico (la ronda 1 -config/current_database/search_path/
# tablas existentes- ya confirmó: conexión correcta a la DB "chibikko" como
# neondb_owner, search_path=public, public.users NO existe, única tabla
# existente es "migrations". Descarta tabla duplicada y search_path).
#
# Acá reproducimos, DENTRO DEL MISMO PROCESO PHP, exactamente el
# Schema::create() de la tabla `users` -mismos tipos, mismo orden de
# columnas que 0001_01_01_000000_create_users_table.php- pero contra una
# tabla con OTRO NOMBRE (`_diagnostic_users_test`) para no tocar nunca la
# tabla `users` real ni nada que la migration real vaya a usar. Se crea,
# se loguea cada query vía DB::listen() (SQL + bindings + tiempo), y se
# borra a sí misma al final -nunca se ejecuta contra `users`, `migrations`
# ni ninguna tabla real, así que no es un "comando destructivo" en el
# sentido de las reglas: es un objeto que este mismo bloque crea y destruye-.
#
# DB::listen() no sobrevive a un `php artisan migrate` en un proceso
# aparte (cada invocación de `php artisan` es un proceso PHP nuevo), así
# que en vez de intentar "engancharlo" al comando real de abajo, se
# reproduce la migration EN ESTE MISMO proceso -mismo Blueprint, mismo
# grammar de Postgres, mismo resultado esperado- para que el listener sí
# pueda capturar todo.
cat > /tmp/diagnose.php <<'PHP'
$queryLog = [];
DB::listen(function ($query) use (&$queryLog) {
    $entry = ['sql' => $query->sql, 'bindings' => $query->bindings, 'time_ms' => $query->time];
    $queryLog[] = $entry;
    echo '[QUERY] ' . $query->time . 'ms | ' . $query->sql . ' | bindings=' . json_encode($query->bindings) . "\n";
});

function printExceptionChain(\Throwable $e): void {
    $current = $e;
    $level = 0;
    while ($current && $level < 5) {
        $code = $current->getCode();
        echo "  [{$level}] " . get_class($current) . ": " . $current->getMessage() . " (code={$code})\n";
        if ($current instanceof \Illuminate\Database\QueryException) {
            echo "      SQL real de esta excepcion: " . $current->getSql() . "\n";
            echo "      Bindings: " . json_encode($current->getBindings()) . "\n";
        }
        $current = $current->getPrevious();
        $level++;
    }
}

echo "=== DIAGNOSTIC: reproduciendo Schema::create('users') contra _diagnostic_users_test ===\n";

Schema::dropIfExists('_diagnostic_users_test'); // limpieza defensiva por si quedo de un intento anterior

try {
    Schema::create('_diagnostic_users_test', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    echo "=== DIAGNOSTIC: Schema::create tuvo EXITO (la definicion de Blueprint no es el problema) ===\n";
} catch (\Throwable $e) {
    echo "=== DIAGNOSTIC: Schema::create FALLO -esta es la excepcion real, sin 25P02 encima- ===\n";
    printExceptionChain($e);
}

echo "=== DIAGNOSTIC: limpiando tabla temporal ===\n";
try {
    Schema::dropIfExists('_diagnostic_users_test');
} catch (\Throwable $e) {
    echo "  (no se pudo limpiar _diagnostic_users_test, revisar manualmente: " . $e->getMessage() . ")\n";
}

echo "=== DIAGNOSTIC: total de queries capturadas = " . count($queryLog) . " ===\n";
echo "=== DIAGNOSTIC: fin del bloque ===\n";
PHP
php artisan tinker < /tmp/diagnose.php || echo "[DIAGNOSTIC] el bloque de diagnostico fallo al ejecutarse (ver arriba); continuando igual con la migracion real"
rm -f /tmp/diagnose.php
# END DIAGNOSTIC
# ============================================================================

# Render Free no tiene Pre-Deploy Command ni shell/SSH ni one-off jobs -no
# hay forma de correr `migrate --force` por fuera del arranque del
# contenedor-, así que corre ACÁ, antes de servir tráfico. Usa las mismas
# variables de entorno que ya tiene el proceso (DB_CONNECTION=pgsql,
# DB_URL de Neon vía render.yaml) — no hace falta pasarlas aparte.
#
# Es seguro correrlo en cada arranque (deploy Y restart/spin-up desde
# sleep del free tier): las migraciones ya aplicadas quedan registradas
# en la tabla `migrations`, así que `migrate --force` sin nada pendiente
# es un no-op rápido, nunca las vuelve a correr. `set -e` (arriba) ya hace
# que un fallo acá aborte el entrypoint con su mismo exit code -nunca se
# levanta Apache con una migración fallida a medias-.
#
# -vvv y --path= TEMPORALES (ver bloque de diagnóstico arriba): -vvv para
# capturar el mayor detalle posible en los logs de Render, --path para
# correr SOLO esta migración puntual mientras se investiga el 25P02 -no
# las otras 23-. Revertir a `migrate --force` a secas (sin --path, todas
# las migraciones pendientes) una vez resuelto.
#
# Caveat conocido y aceptado (no hay forma de evitarlo sin Pre-Deploy/
# shell, que Free no ofrece): si Render llegara a solapar brevemente el
# contenedor viejo y el nuevo durante un deploy que agrega migraciones
# reales, ambos podrían intentar correr esa migración nueva al mismo
# tiempo -Postgres rechazaría el segundo CREATE/ALTER duplicado y ese
# contenedor fallaría al arrancar, por diseño (requisito: nunca ocultar un
# fallo de migración)-. Bajo riesgo en Free (una sola instancia, sin
# scaling horizontal), y de todas formas no hay otra opción disponible en
# este plan.
php artisan migrate --path=database/migrations/0001_01_01_000000_create_users_table.php --force -vvv

# config:cache/route:cache leen env() UNA VEZ acá -ya con las variables
# reales de Render disponibles en el proceso-, no en build time (ahí
# todavía no existen). Verificado antes de escribir esto: no hay ningún
# env() fuera de archivos config/ en toda la app, así que cachear config
# no rompe nada en runtime.
php artisan config:cache
php artisan route:cache
php artisan view:cache || true

exec "$@"
