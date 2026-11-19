#!/bin/sh
set -e
cd /var/www/html

# Le cache du référentiel doit rester inscriptible (volume monté)
mkdir -p storage && chown -R www-data:www-data storage

if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
    php bin/migrate.php
fi

# Création / mise à jour optionnelle du compte admin depuis les variables d'environnement
if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASSWORD" ]; then
    php bin/create-admin.php "$ADMIN_EMAIL" "$ADMIN_PASSWORD" || echo "Avertissement : compte admin non créé" >&2
fi

exec "$@"
