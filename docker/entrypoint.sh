#!/bin/sh
set -eu

AUTO_MIGRATE_VALUE="$(printf '%s' "${AUTO_MIGRATE:-true}" | tr '[:upper:]' '[:lower:]')"
if [ "$AUTO_MIGRATE_VALUE" = "true" ] || [ "$AUTO_MIGRATE_VALUE" = "1" ] || [ "$AUTO_MIGRATE_VALUE" = "yes" ]; then
    echo "Running pending database migrations..."
    php /var/www/html/bin/migrate.php
    echo "Database migrations are up to date."
else
    echo "Automatic database migrations disabled (AUTO_MIGRATE=$AUTO_MIGRATE_VALUE)."
fi

exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
