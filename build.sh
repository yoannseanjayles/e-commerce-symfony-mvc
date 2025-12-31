#!/usr/bin/env bash
# exit on error
set -o errexit

# Install dependencies
composer install --no-dev --optimize-autoloader

# Clear and warm up cache
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=prod

# Install assets (if any)
php bin/console assets:install --symlink --relative public --env=prod
