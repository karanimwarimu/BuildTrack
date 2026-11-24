#!/bin/sh
# Container start-up: prepare the SQLite database, optionally load demo data, then run Apache.
set -e
cd /var/www/html
mkdir -p data
chown -R www-data:www-data data

as_www() { runuser -u www-data -- "$@"; }

as_www php bin/console migrate
if [ "${DEMO_MODE:-false}" = "true" ]; then
    # Free instances start with an empty disk on every boot, so (re)load the demo data.
    as_www php bin/console seed --force || echo "Demo data already present."
fi

exec apache2-foreground
