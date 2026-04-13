# =============================================================================
# Dockerfile compartido para todos los microservicios Laravel
# Imagen base ligera con PHP 8.3 + extensiones necesarias para PostgreSQL
# =============================================================================
FROM php:8.3-cli-alpine

# Instalar dependencias del sistema y extensiones PHP
RUN apk add --no-cache \
    $PHPIZE_DEPS \
    linux-headers \
    postgresql-dev \
    libpq \
    git \
    unzip \
    curl \
    && pecl install redis \
    && docker-php-ext-install pdo pdo_pgsql pcntl \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS linux-headers

# Instalar Composer desde imagen oficial (sin contaminación)
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 1. Copiamos los archivos de configuración de dependencias
COPY composer.json composer.lock ./

# 2. Instalamos las dependencias PERO sin ejecutar scripts (porque artisan no existe aún)
RUN composer install --no-interaction --no-scripts --optimize-autoloader

# 3. AHORA copiamos el resto del código (incluyendo el archivo 'artisan')
COPY . .

# 4. Ejecutamos manualmente el descubrimiento de paquetes que falló antes
RUN php artisan package:discover --ansi

# 5. Permisos
RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8000