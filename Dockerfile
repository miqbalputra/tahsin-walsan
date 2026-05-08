FROM php:8.2-apache

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libicu-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_mysql \
        mysqli \
        zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache.conf /etc/apache2/conf-available/presensi-tahsin.conf
RUN a2enconf presensi-tahsin

COPY . /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/presensi-entrypoint

RUN chmod +x /usr/local/bin/presensi-entrypoint \
    && mkdir -p /var/www/html/.sessions \
    && chown -R www-data:www-data /var/www/html/.sessions \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/login.php >/dev/null || exit 1

ENTRYPOINT ["presensi-entrypoint"]
CMD ["apache2-foreground"]
