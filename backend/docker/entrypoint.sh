#!/bin/sh
set -e

# Render inyecta $PORT en runtime (no está disponible en build) — Apache
# viene configurado para escuchar en 80 por default, así que se reescribe
# acá, cada vez que arranca el contenedor.
PORT="${PORT:-80}"
sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

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
# Caveat conocido y aceptado (no hay forma de evitarlo sin Pre-Deploy/
# shell, que Free no ofrece): si Render llegara a solapar brevemente el
# contenedor viejo y el nuevo durante un deploy que agrega migraciones
# reales, ambos podrían intentar correr esa migración nueva al mismo
# tiempo -Postgres rechazaría el segundo CREATE/ALTER duplicado y ese
# contenedor fallaría al arrancar, por diseño (requisito: nunca ocultar un
# fallo de migración)-. Bajo riesgo en Free (una sola instancia, sin
# scaling horizontal), y de todas formas no hay otra opción disponible en
# este plan.
php artisan migrate --force

# config:cache/route:cache leen env() UNA VEZ acá -ya con las variables
# reales de Render disponibles en el proceso-, no en build time (ahí
# todavía no existen). Verificado antes de escribir esto: no hay ningún
# env() fuera de archivos config/ en toda la app, así que cachear config
# no rompe nada en runtime.
php artisan config:cache
php artisan route:cache
php artisan view:cache || true

exec "$@"
