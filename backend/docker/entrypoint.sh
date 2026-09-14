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
cat > /tmp/diagnose.php <<'PHP'
function diag(string $label, callable $fn): void {
    echo "=== DIAGNOSTIC: {$label} ===\n";
    try {
        foreach ((array) $fn() as $row) {
            echo '  ' . json_encode($row) . "\n";
        }
    } catch (\Throwable $e) {
        echo '  FAILED: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        $prev = $e->getPrevious();
        $depth = 0;
        while ($prev && $depth < 5) {
            echo '  CAUSED BY: ' . get_class($prev) . ': ' . $prev->getMessage() . "\n";
            $prev = $prev->getPrevious();
            $depth++;
        }
    }
}

echo "=== DIAGNOSTIC: config/database.php -> connections.pgsql (password oculta) ===\n";
try {
    $cfg = config('database.connections.pgsql');
    foreach (['host', 'port', 'database', 'username', 'sslmode', 'search_path', 'charset'] as $k) {
        echo "  {$k} = " . (array_key_exists($k, $cfg) ? var_export($cfg[$k], true) : '(unset)') . "\n";
    }
    $urlMasked = isset($cfg['url']) ? preg_replace('#://([^:]+):[^@]+@#', '://$1:***@', $cfg['url']) : '(unset)';
    echo "  url (password oculta) = {$urlMasked}\n";
    echo '  DB_CONNECTION efectiva = ' . config('database.default') . "\n";
} catch (\Throwable $e) {
    echo '  FAILED reading config: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

diag('current_database / current_user / current_schema / version', fn () => DB::select(
    'select current_database() as db, current_user as usr, current_schema() as schema, version() as ver'
));
diag('show search_path', fn () => DB::select('show search_path'));
diag("to_regclass('public.users')", fn () => DB::select("select to_regclass('public.users') as users_table"));
diag('tablas existentes en schema public', fn () => DB::select(
    "select tablename from pg_tables where schemaname = 'public' order by tablename"
));
diag('contenido de la tabla migrations (si existe)', fn () => DB::select('select * from migrations order by id'));

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
# -vvv TEMPORAL (ver bloque de diagnóstico arriba) para capturar el mayor
# detalle posible del error real en los logs de Render mientras se
# investiga el 25P02. Revertir a `--force` a secas una vez resuelto.
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
php artisan migrate --force -vvv

# config:cache/route:cache leen env() UNA VEZ acá -ya con las variables
# reales de Render disponibles en el proceso-, no en build time (ahí
# todavía no existen). Verificado antes de escribir esto: no hay ningún
# env() fuera de archivos config/ en toda la app, así que cachear config
# no rompe nada en runtime.
php artisan config:cache
php artisan route:cache
php artisan view:cache || true

exec "$@"
