FROM php:8.2-fpm-alpine

# Installation des dépendances système
RUN apk add --no-cache \
    git \
    unzip \
    curl \
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

# Installer les dépendances PHP sans exécuter les scripts
RUN composer install --optimize-autoloader --no-dev --no-scripts || composer install --optimize-autoloader --no-scripts

# Copy custom PHP-FPM configuration
COPY docker/www.conf /usr/local/etc/php-fpm.d/www.conf

# Copy PHP upload configuration
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Permissions
RUN chown -R www-data:www-data /var/www/html

# Exposer le port PHP-FPM
EXPOSE 9000

# Commande de démarrage PHP-FPM
CMD ["php-fpm"]
