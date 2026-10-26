# Base épinglée par digest : sans ça, Docker Hub déplace le tag 8.5-apache
# à chaque rebuild upstream → la couche extensions perd son cache → chaque
# deploy recompile intl/gd/... pendant ~5 min. Bumper le digest à la main
# pour passer à une base plus récente (PHP 8.5.12, Debian 13 « trixie »).
# 8.5.12 minimum : CVE-2026-109618/109619 (CERT-EU 2026-018), RCE non authentifiée.
FROM php:8.5.12-apache@sha256:e25ab14ef2cfec3bab9dd66c6cf871691146f428fbd72241b1a2c38fcacf92f5

# Extensions PHP requises
RUN apt-get update && apt-get install -y \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libicu-dev \
        unzip \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        zip \
        gd \
        mbstring \
        fileinfo \
        exif \
        intl \
        pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
# OPcache n'est plus à installer : depuis PHP 8.5 il est compilé dans PHP et
# actif d'office (`docker-php-ext-install opcache` échoue, faute de module).
# pcntl : arrêt PROPRE du worker de jobs. Sans lui, un `docker compose up`
# tue le process en plein milieu d'une génération — le job resterait « en
# cours » jusqu'au passage du reaper (2 min) au lieu de se terminer.

# Apache : activer mod_rewrite et mod_headers
RUN a2enmod rewrite headers

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# DocumentRoot → public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -i 's|/var/www/html|${APACHE_DOCUMENT_ROOT}|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|/var/www/html|${APACHE_DOCUMENT_ROOT}|g' /etc/apache2/apache2.conf \
    && sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Composer install d'abord (cache layer tant que composer.lock ne change pas)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-autoloader

# Puis le code (cache bust ici à chaque changement de code, mais sans réinstaller composer)
COPY . .
RUN composer dump-autoload --optimize --no-dev

# Permissions sur storage et generated
RUN mkdir -p \
        storage/uploads \
        storage/logs \
        storage/cache \
        storage/ratelimit \
        storage/profile_documents \
        storage/knowledge_bases \
        storage/branding \
        public/assets/img/generated \
        public/assets/img/branding \
        public/assets/img/avatars \
        public/assets/img/profils \
        public/assets/video/generated \
    && chown -R www-data:www-data storage public/assets/img public/assets/video \
    && chmod -R 775 storage public/assets/img public/assets/video

# PHP config production
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && echo "upload_max_filesize=50M" >> /usr/local/etc/php/php.ini \
    && echo "post_max_size=50M" >> /usr/local/etc/php/php.ini \
    && echo "memory_limit=256M" >> /usr/local/etc/php/php.ini \
    && echo "max_execution_time=120" >> /usr/local/etc/php/php.ini \
    && echo "max_file_uploads=20" >> /usr/local/etc/php/php.ini \
    && echo "session.upload_progress.enabled=0" >> /usr/local/etc/php/php.ini

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

# Santé du conteneur. `start-period` généreux : l'entrypoint attend MySQL puis
# applique les migrations avant qu'Apache n'écoute — sans ce délai, Docker
# déclarerait le conteneur malade pendant son démarrage normal.
# `curl` est fourni par l'image de base (vérifié : /usr/bin/curl).
HEALTHCHECK --interval=30s --timeout=5s --start-period=120s --retries=3 \
    CMD curl -fsS http://127.0.0.1/healthz > /dev/null || exit 1

ENTRYPOINT ["/entrypoint.sh"]
