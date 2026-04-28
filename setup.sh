#!/bin/bash

set -e

echo "======================================"
echo " Laravel Project Setup Started"
echo "======================================"

# -------------------------------------------------
# 1. Install Dependencies
# -------------------------------------------------
echo "Installing composer dependencies..."
composer install --no-interaction --prefer-dist

# -------------------------------------------------
# 2. Install Required Packages (if missing)
# -------------------------------------------------
echo "Checking Laravel Passport..."
composer show laravel/passport >/dev/null 2>&1 || composer require laravel/passport

echo "Checking Spatie Permission..."
composer show spatie/laravel-permission >/dev/null 2>&1 || composer require spatie/laravel-permission

echo "Checking Spatie Activitylog..."
composer show spatie/laravel-activitylog >/dev/null 2>&1 || composer require spatie/laravel-activitylog

# -------------------------------------------------
# 3. Environment File
# -------------------------------------------------
if [ ! -f .env ]; then
    echo "Creating .env file..."
    cp .env.example .env
fi

# -------------------------------------------------
# 4. Generate App Key
# -------------------------------------------------
echo "Generating application key..."
php artisan key:generate --force

# -------------------------------------------------
# 5. Publish Vendor Files (Only Once)
# -------------------------------------------------
echo "Publishing package assets..."

if [ ! -f config/passport.php ]; then
    php artisan vendor:publish --provider="Laravel\Passport\PassportServiceProvider" --force
fi

if [ ! -f config/permission.php ]; then
    php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --force
fi

if [ ! -f config/activitylog.php ]; then
    php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --force
fi

# -------------------------------------------------
# 6. Safe Pre-Migration Clear
# -------------------------------------------------
echo "Clearing config/view/routes..."
php artisan config:clear
php artisan route:clear
php artisan view:clear

# -------------------------------------------------
# 7. Run Fresh Migration
# -------------------------------------------------
echo "Running migrations..."
php artisan migrate:fresh --force

# -------------------------------------------------
# 8. Seed Database
# -------------------------------------------------
echo "Running seeders..."
php artisan db:seed --force

# -------------------------------------------------
# 9. Generate Passport Keys
# -------------------------------------------------
if [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
    echo "Generating Passport keys..."
    php artisan passport:keys --force
fi

# -------------------------------------------------
# 10. Create Personal Access Client (only if none)
# -------------------------------------------------
CLIENT_EXISTS=$(php artisan tinker --execute="echo \Laravel\Passport\Client::count();" 2>/dev/null | tail -n 1)

if [ -z "$CLIENT_EXISTS" ] || [ "$CLIENT_EXISTS" = "0" ]; then
    echo "Creating Passport Personal Access Client..."
    php artisan passport:client --personal --name="Admin Access Client" --provider="admins" --no-interaction
    php artisan passport:client --personal --name="User Access Client" --provider="users" --no-interaction
fi

# -------------------------------------------------
# 11. Final Optimize Clear
# -------------------------------------------------
echo "Clearing caches..."
php artisan optimize:clear

echo "======================================"
echo " Laravel Project Setup Completed"
echo "======================================"