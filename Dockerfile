FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql opcache \
 && a2enmod headers \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf "ServerTokens Prod\nServerName localhost\n" > /etc/apache2/conf-enabled/zz-security.conf

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

ENV TZ=Africa/Abidjan
EXPOSE 80
VOLUME ["/var/www/html/storage"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD curl -fsS -o /dev/null http://localhost/admin/login.php || exit 1

ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
