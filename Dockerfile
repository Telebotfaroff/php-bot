FROM php:8.3-apache-bookworm
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN apt-get update \
 && apt-get install -y --no-install-recommends libcurl4-openssl-dev supervisor \
 && docker-php-ext-install curl pdo_pgsql \
 && a2enmod rewrite headers \
 && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
 && sed -i "s#DocumentRoot /var/www/html#DocumentRoot \$APACHE_DOCUMENT_ROOT#" /etc/apache2/sites-available/000-default.conf \
 && printf '\n<Directory /var/www/html/public>\n Options -Indexes +FollowSymLinks\n AllowOverride All\n Require all granted\n</Directory>\n<Files \"install.php\">\n Require all denied\n</Files>\n' >> /etc/apache2/apache2.conf \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html && mkdir -p /var/log/php-bot && chown -R www-data:www-data /var/log/php-bot
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
EXPOSE 8080
CMD ["/bin/sh", "/var/www/html/docker/entrypoint.sh"]
