#!/usr/bin/env bash
# Build script for Render deployment

set -e

echo "🚀 Starting build process..."

echo "📦 Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "🔑 Generating JWT keypair..."
php bin/console lexik:jwt:generate-keypair --skip-if-exists

echo "🗄️  Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "🧹 Clearing cache..."
php bin/console cache:clear --env=prod --no-warmup

echo "🔥 Warming up cache..."
php bin/console cache:warmup --env=prod

echo "✅ Build complete!"
