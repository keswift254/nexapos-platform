FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql curl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN echo "display_errors=Off" > /usr/local/etc/php/conf.d/production-errors.ini

COPY . /var/www/html/

# Document root is public/, not the repo root - config/, app/, sql/ stay
# unreachable over HTTP the same way .htaccess-less Apache would never
# have served them locally either, just enforced at the vhost level here.
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
