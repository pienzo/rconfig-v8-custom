# rConfig V8 Core - Docker Image
FROM node:22.23.5-bookworm-slim AS frontend
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --include=dev

COPY . .
RUN npm run build && test -s public/build/manifest.json

FROM php:8.4.28-apache-bookworm

# Set user ID for www-data to 1000
RUN usermod -u 1000 www-data

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libldap2-dev \
    libgmp-dev \
    libicu-dev \
    zip \
    vim \
    redis-server \
    unzip \
    supervisor \
    cron \
    git \
    netcat-openbsd \
    libsnmp-dev \
    snmp \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd zip pdo_mysql pcntl snmp ldap gmp intl \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

# Configure Apache to use Laravel's public directory
RUN echo "DocumentRoot /var/www/html/rconfig/public" > /etc/apache2/sites-available/000-default.conf \
    && echo "<Directory /var/www/html/rconfig/public>" >> /etc/apache2/sites-available/000-default.conf \
    && echo "    AllowOverride All" >> /etc/apache2/sites-available/000-default.conf \
    && echo "    Require all granted" >> /etc/apache2/sites-available/000-default.conf \
    && echo "</Directory>" >> /etc/apache2/sites-available/000-default.conf

# Install Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html/rconfig

# Dependency layer: only invalidated when composer files change
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Application source and fresh Vite output
COPY . .
RUN for template in default.yml ios-ssh-enable.yml ios-ssh-noenable.yml ios-telnet-enable.yml ios-telnet-noenable.yml; do \
        path="storage/app/rconfig/templates/$template"; \
        if [ ! -f "$path" ] || [ ! -s "$path" ]; then \
            echo "Required Core template is missing or empty: $path" >&2; \
            exit 1; \
        fi; \
    done
COPY --from=frontend /app/public/build/ ./public/build/

RUN mkdir -p bootstrap/cache \
    && COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev

# Copy service configuration files
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/redis.conf /etc/redis/rconfig-redis.conf

# PHP settings: keep function arguments (passwords) out of exception traces (RCO-2055)
COPY --chown=root:root --chmod=0644 docker/php-security.ini /usr/local/etc/php/conf.d/zz-rconfig-security.ini

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Create log and runtime directories
RUN mkdir -p /var/log /var/run/supervisor /var/run/redis /var/lib/redis \
    && chown -R www-data:www-data /var/log \
    && chown -R redis:redis /var/run/redis /var/lib/redis

# Set up permissions for storage and cache
RUN mkdir -p storage/app/public \
        storage/app/rconfig/backups \
        storage/app/rconfig/data \
        storage/app/rconfig/exports \
        storage/app/rconfig/reports \
        storage/files/uploads \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data /var/www/html/rconfig/storage /var/www/html/rconfig/bootstrap/cache \
    && chmod -R 775 /var/www/html/rconfig/storage/framework /var/www/html/rconfig/storage/logs /var/www/html/rconfig/bootstrap/cache \
    && chmod 775 /var/www/html/rconfig/storage
# Note: the chmod above deliberately targets framework/logs/cache rather than
# recursing over all of storage/. storage/app/rconfig/data holds downloaded
# device configs, which carry device secrets and are kept non world readable by
# FileOperations. A recursive chmod here would undo that on every build.

# The config data directory ships at whatever mode the checkout carries, which
# is world traversable. FileOperations creates the directories beneath it at
# 0750, so stored configs were never exposed, but the root of that tree should
# not be an exception to the rule. Set here so both the image and the skeleton
# below carry it, which covers the named volume path as well as the bind mount.
RUN chmod 750 /var/www/html/rconfig/storage/app/rconfig/data

# Snapshot the pristine storage tree. A bind mounted storage/ masks everything
# the image ships, so the entrypoint restores missing paths from this copy. It
# lives outside the mount point, which is what keeps it visible. Taken after the
# ownership and mode work above so the skeleton carries the same modes.
RUN mkdir -p /usr/local/share/rconfig \
    && cp -a /var/www/html/rconfig/storage /usr/local/share/rconfig/storage-skeleton

# Marks the runtime as containerised. rconfig:clear-all keys off this to skip
# the bare-metal supervisor/systemctl restarts (which need sudo) and to manage
# storage permissions the container way. Kept low in the file so it does not
# invalidate the apt and composer layers above it.
ENV IS_DOCKER=true

ARG SOURCE_REVISION=unknown
LABEL org.opencontainers.image.revision="${SOURCE_REVISION}"

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]