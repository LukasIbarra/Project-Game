# Render + Neon (PostgreSQL). Ver docs/RENDER_DEPLOYMENT_ANALYSIS.md.
#
# Base php:8.2-apache -un solo proceso en el contenedor (Apache + mod_php),
# sin PHP-FPM/Nginx/supervisord separados- porque este proyecto no tiene
# necesidad real de esa complejidad: sin colas en tiempo real, sin tráfico
# alto esperado (demo/beta), CLAUDE.md ya evita sobreingeniería
# explícitamente. Apache+mod_php es un servidor de producción legítimo, solo
# no el de mayor throughput -de sobra para este MVP-.
FROM php:8.2-apache

# Extensiones realmente usadas por el proyecto (verificado antes de escribir
# esto: sin GD/Imagick/ZipArchive/bcmath en el código de app, sin colas
# Redis -CLAUDE.md ya lo prohíbe-, session/cache/queue todos en 'database').
# pdo_mysql se mantiene además de pdo_pgsql -no estorba, y mantiene la
# imagen utilizable si en algún momento se necesita apuntar a MySQL de
# nuevo sin reconstruir-.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        unzip \
        git \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        bcmath \
        zip \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Composer primero (capa cacheable) — el resto del código cambia mucho más
# seguido que las dependencias.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --optimize-autoloader --no-interaction

COPY . .

RUN composer dump-autoload --optimize --no-dev

# Apache sirve desde public/, no desde la raíz del proyecto -acá vive el
# código de la app, no el docroot-.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e "s!/var/www/html!\${APACHE_DOCUMENT_ROOT}!g" \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

# storage/ y bootstrap/cache/ deben ser escribibles en runtime (logs, cache
# de config/rutas, sesiones si algo cae a ese driver) -Render no da disco
# persistente en el plan gratuito, y esta app no lo necesita: nada de lo
# que se escribe acá debe sobrevivir un restart (sin uploads de usuario).
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Render inyecta $PORT en runtime, no en build -por eso Apache escucha en
# 80 acá y el entrypoint reconfigura el puerto real al arrancar el
# contenedor, no antes-.
EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
