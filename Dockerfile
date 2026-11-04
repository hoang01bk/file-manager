# --- GIAI ĐOẠN 1: BUILD FRONTEND (Node.js) ---
FROM node:20-alpine AS frontend-builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# --- GIAI ĐOẠN 2: CHẠY BACKEND (PHP-FPM) ---
FROM php:8.3.36-fpm AS php-runtime

# Cài đặt system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip libpq-dev libzip-dev qpdf

# Cài đặt PHP extensions
RUN docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd zip

# Cài đặt Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Install locked dependencies without relying on a host vendor directory.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-dev --no-scripts --no-autoloader

# Copy application code; .dockerignore excludes host dependencies, caches and data.
COPY . .

# Copy kết quả đã build từ Giai đoạn 1 sang (Thư mục public/build)
COPY --from=frontend-builder /app/public/build ./public/build

# Cài đặt Laravel dependencies
RUN mkdir -p storage/app/public storage/app/private storage/logs \
    storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && composer dump-autoload --no-interaction --classmap-authoritative --no-dev

# Phân quyền
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

COPY docker/php/local.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/production/entrypoint.sh /usr/local/bin/file-manager-entrypoint
RUN chmod +x /usr/local/bin/file-manager-entrypoint
ENTRYPOINT ["file-manager-entrypoint"]
EXPOSE 9000
CMD ["php-fpm"]

# nginx and PHP use the public directory from the same build.
FROM nginx:alpine AS web
ENV PHP_FPM_HOST=app
COPY --from=php-runtime /var/www/public /var/www/public
COPY docker/nginx/conf.d/default.conf /etc/nginx/templates/default.conf.template
RUN sed -i 's/fastcgi_pass app:9000;/fastcgi_pass ${PHP_FPM_HOST}:9000;/' /etc/nginx/templates/default.conf.template

# Keep the default Docker build target compatible with existing PHP services.
FROM php-runtime AS app
