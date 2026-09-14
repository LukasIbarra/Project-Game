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
# contenedor-, así que corre ACÁ, antes de servir tráfico.
#
# Es seguro correrlo en cada arranque (deploy Y restart/spin-up desde
# sleep del free tier): las migraciones ya aplicadas quedan registradas
# en la tabla `migrations`, así que `migrate --force` sin nada pendiente
# es un no-op rápido, nunca las vuelve a correr. `set -e` (arriba) ya hace
# que un fallo acá aborte el entrypoint con su mismo exit code -nunca se
# levanta Apache con una migración fallida a medias-.
#
# Causa raíz encontrada del SQLSTATE[25P02] investigado en rondas previas
# (ver docs/RENDER_DEPLOYMENT_ANALYSIS.md): DB_URL apunta al endpoint
# *pooled* de Neon (host con sufijo "-pooler", PgBouncer en modo
# transaction). Documentado por el propio Neon como propenso a errores
# para migraciones -Migrator envuelve cada migración en
# Connection->transaction() (CREATE TABLE + ALTER TABLE ADD CONSTRAINT
# como statements separados dentro de un solo BEGIN/COMMIT), y ese patrón
# multi-statement dentro de una transacción explícita es exactamente lo
# que Neon dice que falla sobre el pooler-. Confirmado empíricamente en
# rondas anteriores: el mismo SQL corrido SIN transacción explícita
# funcionaba perfecto sobre el mismo DB_URL; envuelto en
# DB::transaction() (réplica fiel de lo que hace Migrator) reproducía el
# 25P02 tal cual. Fix recomendado por Neon: usar el connection string
# *directo* (sin pooler) solo para migrar, y dejar el pooled para el
# tráfico normal de la app -por eso el override puntual de abajo, nunca
# se toca el DB_URL que usa Apache/el resto del proceso-.
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
