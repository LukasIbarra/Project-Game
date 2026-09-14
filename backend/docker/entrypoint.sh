#!/bin/sh
set -e

# Render inyecta $PORT en runtime (no está disponible en build) — Apache
# viene configurado para escuchar en 80 por default, así que se reescribe
# acá, cada vez que arranca el contenedor.
PORT="${PORT:-80}"
sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# ============================================================================
# BLOQUE TEMPORAL DE DIAGNÓSTICO — RONDA 4. El fix de DB_URL_MIGRATE
# (ronda anterior) no resolvió el SQLSTATE[25P02] en
# create_users_table -mismo error, mismo statement final-, así que antes
# de asumir nada de nuevo esto verifica, en orden: (a) si DB_URL_MIGRATE
# realmente llega y qué host usa el migrate real, (b) si `users` (u otras
# tablas de esta misma migración) ya existen como objetos reales en la
# base -CREATE TABLE fallando por "already exists" explicaría el 25P02
# posterior sin que sea ni el pooler ni la migración en sí-. 100%
# solo-lectura (ningún CREATE/ALTER/DROP), nunca toca `users`/`migrations`
# de forma destructiva, cada bloque en try/catch, y el `|| echo` final
# asegura que un fallo acá nunca bloquee el `migrate --force` real de
# abajo. Revertir quitando todo entre BEGIN/END DIAGNOSTIC una vez
# resuelto.
# ============================================================================
# BEGIN DIAGNOSTIC
MIGRATE_URL="${DB_URL_MIGRATE:-$DB_URL}"
if [ -n "$DB_URL_MIGRATE" ]; then
    MIGRATE_CONFIGURED="yes"
else
    MIGRATE_CONFIGURED="no"
fi
MIGRATE_HOST=$(echo "$MIGRATE_URL" | sed -E 's#^[a-zA-Z]+://[^@]*@([^:/]+).*#\1#')
RUNTIME_HOST=$(echo "$DB_URL" | sed -E 's#^[a-zA-Z]+://[^@]*@([^:/]+).*#\1#')
case "$MIGRATE_HOST" in
    *-pooler*) MIGRATE_POOLED="yes" ;;
    *) MIGRATE_POOLED="no" ;;
esac
echo "[MIGRATE] DB_URL_MIGRATE configured: ${MIGRATE_CONFIGURED}"
echo "[MIGRATE] DB host used for migrate: ${MIGRATE_HOST}"
echo "[MIGRATE] pooled endpoint (contains -pooler): ${MIGRATE_POOLED}"
echo "[MIGRATE] DB host used for runtime/app (DB_URL): ${RUNTIME_HOST}"

cat > /tmp/diagnose_r4.php <<'PHP'
echo "=== DIAGNOSTIC RONDA 4: estado real de la base (solo lectura, con la misma conexion que usara el migrate real) ===\n";
try {
    foreach (DB::select('select version() as v') as $row) {
        echo '  version() = ' . $row->v . "\n";
    }
    foreach (DB::select('select current_database() as db, current_user as usr, current_schema() as schema') as $row) {
        echo '  db=' . $row->db . ' user=' . $row->usr . ' schema=' . $row->schema . "\n";
    }
} catch (\Throwable $e) {
    echo "  error de conexion/consulta basica: " . get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo "\n--- to_regclass: existen ya estas tablas? ---\n";
foreach (['users', 'password_reset_tokens', 'sessions', 'migrations'] as $t) {
    try {
        foreach (DB::select("select to_regclass('public.$t') as r") as $row) {
            echo "  public.$t => " . ($row->r ?? 'NO EXISTE') . "\n";
        }
    } catch (\Throwable $e) {
        echo "  public.$t => error: " . $e->getMessage() . "\n";
    }
}

echo "\n--- si 'users' existe: columnas ---\n";
try {
    $cols = DB::select("select column_name, data_type, is_nullable from information_schema.columns where table_schema='public' and table_name='users' order by ordinal_position");
    foreach ($cols as $c) {
        echo "  {$c->column_name} | {$c->data_type} | nullable={$c->is_nullable}\n";
    }
    if (empty($cols)) echo "  (ninguna -- la tabla no existe)\n";
} catch (\Throwable $e) {
    echo "  error leyendo columnas: " . $e->getMessage() . "\n";
}

echo "\n--- si 'users' existe: constraints ---\n";
try {
    $cons = DB::select("select conname, contype, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'public.users'::regclass");
    foreach ($cons as $c) {
        echo "  {$c->conname} | tipo={$c->contype} | {$c->def}\n";
    }
    if (empty($cons)) echo "  (ninguno)\n";
} catch (\Throwable $e) {
    echo "  error leyendo constraints (probable: la tabla no existe): " . $e->getMessage() . "\n";
}

echo "\n--- contenido de la tabla 'migrations' (si existe) ---\n";
try {
    $rows = DB::select('select migration, batch from migrations order by id');
    foreach ($rows as $r) {
        echo "  {$r->migration} | batch={$r->batch}\n";
    }
    if (empty($rows)) echo "  (vacia)\n";
} catch (\Throwable $e) {
    echo "  error leyendo migrations (probable: la tabla no existe): " . $e->getMessage() . "\n";
}
echo "=== DIAGNOSTIC RONDA 4: fin ===\n";
PHP
DB_URL="$MIGRATE_URL" php artisan tinker < /tmp/diagnose_r4.php || echo "[DIAGNOSTIC] el bloque de introspeccion fallo al ejecutarse (ver arriba)"
rm -f /tmp/diagnose_r4.php
# END DIAGNOSTIC
# ============================================================================

# Render Free no tiene Pre-Deploy Command ni shell/SSH ni one-off jobs -no
# hay forma de correr `migrate --force` por fuera del arranque del
# contenedor-, así que corre ACÁ, antes de servir tráfico.
#
# Es seguro correrlo en cada arranque (deploy Y restart/spin-up desde
# sleep del free tier): las migraciones ya aplicadas quedan registradas
# en la tabla `migrations`, así que `migrate --force` sin nada pendiente
# es un no-op rápido, nunca las vuelve a correr. `set -e` (arriba) ya hace
# que un fallo acá aborte el entrypoint con su mismo exit code -nunca se
# levanta Apache con una migración fallida a medias-.
#
# HIPÓTESIS EN VERIFICACIÓN (NO confirmada todavía -ver bloque de
# diagnóstico RONDA 4 arriba, y docs/RENDER_DEPLOYMENT_ANALYSIS.md
# sección 14): se sospechó que el SQLSTATE[25P02] en create_users_table
# era por usar el endpoint *pooled* de Neon (PgBouncer en modo
# transaction, que Neon documenta como propenso a errores en migraciones
# multi-statement) para migrar, y por eso se agregó el override de abajo
# a DB_URL_MIGRATE (connection string directo). Aplicar ese fix NO
# resolvió el error -mismo 25P02, mismo statement final- así que esto ya
# NO se puede tomar como la causa raíz confirmada. El bloque de
# diagnóstico de arriba corre con esta misma URL resuelta antes de este
# punto, específicamente para confirmar si el problema es el pooler, si
# DB_URL_MIGRATE en realidad no está llegando, o si hay un objeto real
# (ej. una tabla `users` ya existente) bloqueando el CREATE TABLE.
#
# DB_URL_MIGRATE = connection string DIRECTO de Neon (Connection Details
# → desactivar "Connection pooling" en el dashboard de Neon), configurado
# en Render como env var separada (ver render.yaml). Si todavía no está
# seteada, cae de vuelta al DB_URL pooled de siempre -mismo riesgo de
# 25P02 ya conocido, pero no rompe el arranque en caliente por la falta
# de la variable nueva-.
if [ -n "$DB_URL_MIGRATE" ]; then
    DB_URL="$DB_URL_MIGRATE" php artisan migrate --force
else
    echo "[WARN] DB_URL_MIGRATE no esta configurada -usando DB_URL (pooled) para migrar, puede fallar con 25P02- ver docs/RENDER_DEPLOYMENT_ANALYSIS.md" >&2
    php artisan migrate --force
fi
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

# config:cache/route:cache leen env() UNA VEZ acá -ya con las variables
# reales de Render disponibles en el proceso-, no en build time (ahí
# todavía no existen). Verificado antes de escribir esto: no hay ningún
# env() fuera de archivos config/ en toda la app, así que cachear config
# no rompe nada en runtime.
php artisan config:cache
php artisan route:cache
php artisan view:cache || true

exec "$@"
