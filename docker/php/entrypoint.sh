#!/usr/bin/env bash
# Single entrypoint shared by the app, worker and scheduler containers.
# The role is chosen with CONTAINER_ROLE so all three stay byte-identical.
set -euo pipefail

ROLE="${CONTAINER_ROLE:-app}"
cd /var/www/html

if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] installing PHP dependencies..."
    composer install --prefer-dist --no-interaction --no-progress
fi

if [ ! -f .env ]; then
    echo "[entrypoint] no backend/.env found, deriving one from .env.example"
    cp .env.example .env
fi

if ! grep -qE '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

# Only the app container owns schema migration; workers must never race it.
if [ "$ROLE" = "app" ]; then
    echo "[entrypoint] waiting for the database..."
    until php -r 'exit(@pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"))) ? 0 : 1);' 2>/dev/null; do
        sleep 2
    done
    php artisan migrate --force
    php artisan storage:link || true
fi

case "$ROLE" in
    app)
        exec php-fpm
        ;;
    worker)
        # --max-time recycles the worker hourly so long-lived leaks cannot build up.
        exec php artisan queue:work redis \
            --queue=high,default,notifications,documents,reports,low \
            --tries=3 --backoff=10,60,300 --max-time=3600 --sleep=1
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    *)
        echo "[entrypoint] unknown CONTAINER_ROLE '$ROLE'" >&2
        exit 1
        ;;
esac
