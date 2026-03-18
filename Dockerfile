# --- GIAI ĐOẠN 1: BUILD FRONTEND (Node.js) ---
FROM node:20-alpine AS frontend-builder
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
RUN npm run build

# --- GIAI ĐOẠN 2: CHẠY BACKEND (PHP-FPM) ---
FROM php:8.3-fpm

# Cài đặt system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip libpq-dev libzip-dev

# Cài đặt PHP extensions
RUN docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd zip

# Cài đặt Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy toàn bộ source code
COPY . .

# Copy kết quả đã build từ Giai đoạn 1 sang (Thư mục public/build)
COPY --from=frontend-builder /app/public/build ./public/build

# Cài đặt Laravel dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Phân quyền
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]