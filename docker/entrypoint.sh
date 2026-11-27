#!/usr/bin/env bash
set -e

PORT="${PORT:-8080}"

# Bind Apache to dynamic Render/Koyeb PORT
sed -ri "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Ensure SQLite file exists if using sqlite fallback
if [ "${DB_CONNECTION:-pgsql}" = "sqlite" ] && [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
  chown www-data:www-data database/database.sqlite
fi

# Ensure storage symlink exists
php artisan storage:link --force || true

# Run migrations automatically when enabled (set RUN_MIGRATIONS=true in Render env)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force || true
fi

# Optional one-time seeding (set RUN_SEEDERS=true on first deploy, then turn off)
if [ "${RUN_SEEDERS:-false}" = "true" ]; then
  php artisan db:seed --force || true
fi

# Warm caches for fast responses
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

exec apache2-foreground
