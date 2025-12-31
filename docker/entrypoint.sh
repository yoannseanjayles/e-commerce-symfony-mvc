#!/bin/bash

# Exit on error
set -e

echo "Warming up cache..."
php bin/console cache:warmup --env=prod

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=prod 2>&1 || {
    echo "Migrations failed; trying schema update as fallback...";
    php bin/console doctrine:schema:update --force --no-interaction --env=prod 2>&1 || echo "Schema update completed or not needed";
}

echo "Setting up admin user..."
php bin/reset-admin-password.php 2>&1 || echo "Admin setup completed"

echo "Checking if data exists..."
PRODUCT_COUNT=$(php bin/console doctrine:query:sql "SELECT COUNT(*) FROM products" --env=prod 2>&1 | grep -oP '\d+' | tail -1 || echo "0")
echo "Current product count: $PRODUCT_COUNT"

if [ "$PRODUCT_COUNT" = "0" ] || [ -z "$PRODUCT_COUNT" ]; then
    echo "Loading fixtures (initial data)..."
    php bin/console doctrine:fixtures:load --no-interaction --env=prod 2>&1 || echo "Fixtures loading failed or skipped"
else
    echo "Data already exists, skipping fixtures"
fi

echo "Installing assets..."
php bin/console assets:install --symlink --relative public --env=prod || true

echo "Fix permissions..."
chown -R www-data:www-data /var/www/html/var
chmod -R 775 /var/www/html/var

echo "Creating upload directory..."
mkdir -p /var/www/html/public/assets/uploads
chown -R www-data:www-data /var/www/html/public/assets/uploads
chmod -R 775 /var/www/html/public/assets/uploads

echo "Starting Apache..."
exec apache2-foreground
