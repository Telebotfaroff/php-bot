FROM php:8.3-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
 && apt-get install -y --no-install-recommends libcurl4-openssl-dev libpq-dev supervisor \
 && docker-php-ext-install curl pdo_pgsql \
 && a2enmod rewrite headers \
 && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
 && rm -rf /var/lib/apt/lists/*

RUN cat > /etc/apache2/sites-available/000-default.conf <<'APACHE'
<VirtualHost *:8080>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/public
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
APACHE

RUN cat >> /etc/apache2/apache2.conf <<'APACHE'

<Directory /var/www/html/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
<Files "install.php">
    Require all denied
</Files>
APACHE

WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
 && mkdir -p /var/log/php-bot \
 && chown -R www-data:www-data /var/log/php-bot

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 8080
CMD ["/bin/sh", "/var/www/html/docker/entrypoint.sh"]
