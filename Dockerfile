FROM php:8.2-fpm-alpine

# Installation des dépendances système + Nginx
RUN apk add --no-cache \
    git \
    unzip \
    curl \
    nginx \
    supervisor \
    libzip-dev \
    postgresql-dev \
    icu-dev \
    oniguruma-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev

# Installation des extensions PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    zip \
    intl \
    opcache \
    gd \
    mbstring

# Installation de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Définir le répertoire de travail
WORKDIR /var/www/html

# Copier les fichiers du projet
COPY . .

# Créer le fichier .env à partir de .env.example
RUN cp .env.example .env

# Installer les dépendances PHP
RUN composer install --optimize-autoloader --no-dev --no-scripts || composer install --optimize-autoloader --no-scripts

# Créer les répertoires nécessaires
RUN mkdir -p /var/www/html/var/cache /var/www/html/var/log \
    && mkdir -p /run/nginx \
    && mkdir -p /var/log/supervisor

# Configuration Nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/http.d/default.conf

# Configuration PHP-FPM
COPY docker/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Configuration Supervisor
COPY docker/supervisord.conf /etc/supervisord.conf

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/var

# Exposer le port HTTP
EXPOSE 10000

# Démarrer Supervisor (qui gère Nginx + PHP-FPM)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
