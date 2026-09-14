#!/bin/sh
set -e

# Render inyecta $PORT en runtime (no está disponible en build) — Apache
# viene configurado para escuchar en 80 por default, así que se reescribe
# acá, cada vez que arranca el contenedor.
PORT="${PORT:-80}"
sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# config:cache/route:cache leen env() UNA VEZ acá -ya con las variables
# reales de Render disponibles en el proceso-, no en build time (ahí
# todavía no existen). Verificado antes de escribir esto: no hay ningún
# env() fuera de archivos config/ en toda la app, así que cachear config
# no rompe nada en runtime.
#
# IMPORTANTE: esto NO incluye migraciones. Las migraciones corren por el
# Pre-Deploy Command de Render (ver render.yaml), nunca acá -si migrasen en
# cada arranque de contenedor, correrían también en cada restart/scale del
# servicio, no solo en cada deploy real-.
php artisan config:cache
php artisan route:cache
php artisan view:cache || true

exec "$@"
