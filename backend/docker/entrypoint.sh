#!/bin/sh
set -e

# Render inyecta $PORT en runtime (no está disponible en build) — Apache
# viene configurado para escuchar en 80 por default, así que se reescribe
# acá, cada vez que arranca el contenedor.
PORT="${PORT:-80}"
sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Render Free no tiene Pre-Deploy Command ni shell/SSH ni one-off jobs -no
# hay forma de correr `migrate --force`/`db:seed --force` por fuera del
# arranque del contenedor-, así que ambos corren ACÁ, antes de servir
# tráfico.
#
# Es seguro correr esto en cada arranque (deploy Y restart/spin-up desde
# sleep del free tier): las migraciones ya aplicadas quedan registradas en
# la tabla `migrations` (no-op si no hay nada pendiente), y los 7 seeders
# de DatabaseSeeder (Item/EconomyItem/Recipe/RoomFurniture/ArenaEquipment/
# Pet/PetNarrativeEvent) son idempotentes -todos usan updateOrCreate (o un
# save() sobre una fila ya buscada por `key`) en vez de create() a secas,
# así que re-sembrar solo re-escribe los mismos valores, nunca duplica
# filas-. `set -e` (arriba) ya hace que un fallo en cualquiera de los dos
# aborte el entrypoint con su mismo exit code -nunca se levanta Apache con
# una migración o un seed fallado a medias-.
#
# DB_URL_MIGRATE = connection string DIRECTO de Neon (Connection Details
# → desactivar "Connection pooling" en el dashboard de Neon), configurado
# en Render como env var separada (ver render.yaml y
# docs/RENDER_DEPLOYMENT_ANALYSIS.md sección 14 para el contexto completo
# del SQLSTATE[25P02] que llevó a este override). Si todavía no está
# seteada, cae de vuelta al DB_URL pooled de siempre.
if [ -n "$DB_URL_MIGRATE" ]; then
    DB_URL="$DB_URL_MIGRATE" php artisan migrate --force
    DB_URL="$DB_URL_MIGRATE" php artisan db:seed --force
else
    echo "[WARN] DB_URL_MIGRATE no esta configurada -usando DB_URL (pooled) para migrar/sembrar, puede fallar con 25P02- ver docs/RENDER_DEPLOYMENT_ANALYSIS.md" >&2
    php artisan migrate --force
    php artisan db:seed --force
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
