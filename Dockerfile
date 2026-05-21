FROM php:8.2-fpm

ENV DEBIAN_FRONTEND=noninteractive

# System deps + PHP extensions + nginx
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libsqlite3-dev \
        nginx \
        unzip \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Increase limits for large media uploads
RUN { \
        echo 'upload_max_filesize = 4G'; \
        echo 'post_max_size = 4G'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 300'; \
        echo 'max_input_time = 300'; \
    } > /usr/local/etc/php/conf.d/media-server.ini

# Pass host env vars through to FPM workers (clear_env=yes by default strips them,
# which means background scan.php processes launched via exec() lose MEDIA_PATH etc.)
RUN echo 'clear_env = no' >> /usr/local/etc/php-fpm.d/www.conf

# Nginx site config
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Startup script (php-fpm + nginx)
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps first (separate layer — cached unless composer.json changes)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application source
COPY . .

# Ensure writable directories exist with correct ownership
RUN mkdir -p storage/db storage/cache public/covers \
    && chown -R www-data:www-data storage public/covers \
    && chmod -R 775 storage public/covers

EXPOSE 80

CMD ["/start.sh"]
