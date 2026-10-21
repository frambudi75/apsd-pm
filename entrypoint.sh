#!/bin/sh
set -e

# Ensure database, uploads, and backups directory structures exist
mkdir -p /var/www/html/database /var/www/html/uploads/reports /var/www/html/uploads/daily /var/www/html/uploads/operators /var/www/html/uploads/branding /var/www/html/uploads/documents /var/www/html/uploads/signatures /var/www/html/uploads/temp /var/www/html/backups

# Fix permissions for database, uploads, and backups on mounted volumes
chown -R www-data:www-data /var/www/html/database /var/www/html/uploads /var/www/html/backups 2>/dev/null || true
chmod -R 777 /var/www/html/database /var/www/html/uploads /var/www/html/backups 2>/dev/null || true

# If database file exists, make sure it is read-write for all
if [ -f /var/www/html/database/apsd.sqlite ]; then
    chmod 666 /var/www/html/database/apsd.sqlite 2>/dev/null || true
fi

# Ensure Apache AllowOverride All and rewrite/headers modules are active
sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf 2>/dev/null || true
a2enmod rewrite headers 2>/dev/null || true

# Export container environment variables for background cron tasks
printenv | grep -E '^(DB_|REDIS_|APP_|PATH=)' > /etc/environment 2>/dev/null || true

# Start background cron service if installed
if command -v cron >/dev/null 2>&1; then
    service cron start 2>/dev/null || /etc/init.d/cron start 2>/dev/null || true
fi

# Run database auto-repair & self-healing check on startup
php -r "require_once '/var/www/html/config/database.php'; \$pdo = get_db_connection(); \$res = auto_repair_database(\$pdo, true); echo '[DB Auto-Repair] ' . (\$res['message'] ?? 'OK') . PHP_EOL;" 2>/dev/null || true

# Synchronize PWA Manifest with database branding settings on startup
php -r "require_once '/var/www/html/config/database.php'; require_once '/var/www/html/includes/pwa.php'; sync_pwa_manifest_and_icons();" 2>/dev/null || true

# Execute CMD (default: apache2-foreground)
exec "$@"
