#!/bin/sh
set -e

# Ensure database and uploads directory structures exist
mkdir -p /var/www/html/database /var/www/html/uploads/reports /var/www/html/uploads/temp

# Fix permissions for SQLite database and uploads on mounted volumes
chown -R www-data:www-data /var/www/html/database /var/www/html/uploads 2>/dev/null || true
chmod -R 777 /var/www/html/database /var/www/html/uploads 2>/dev/null || true

# If database file exists, make sure it is read-write for all
if [ -f /var/www/html/database/apsd.sqlite ]; then
    chmod 666 /var/www/html/database/apsd.sqlite 2>/dev/null || true
fi

# Ensure Apache AllowOverride All and rewrite/headers modules are active
sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf 2>/dev/null || true
a2enmod rewrite headers 2>/dev/null || true

# Execute CMD (default: apache2-foreground)
exec "$@"
