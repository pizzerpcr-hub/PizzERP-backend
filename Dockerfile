FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip supervisor libpq-dev libzip-dev libonig-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql pgsql mbstring bcmath intl zip opcache \
    && a2enmod rewrite proxy proxy_http \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .
COPY docker/reverb-proxy.conf /etc/apache2/conf-available/reverb-proxy.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache storage/framework/sessions \
       storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' \
       /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' \
       > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel reverb-proxy

EXPOSE 10000

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]