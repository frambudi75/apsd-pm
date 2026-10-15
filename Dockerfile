FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    libsqlite3-dev \
    sqlite3 \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    chromium \
    fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*

# Configure & install PHP extensions (GD with FreeType & JPEG, PDO SQLite, PDO MySQL, EXIF)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql pdo_sqlite exif

# Install Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# Enable Apache mod_rewrite & headers, and configure AllowOverride All for .htaccess
RUN a2enmod rewrite headers \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Configure PHP settings for high upload limits and large forms (PM reports have up to 48 photos)
RUN { \
    echo 'upload_max_filesize = 64M'; \
    echo 'post_max_size = 128M'; \
    echo 'max_file_uploads = 100'; \
    echo 'memory_limit = 512M'; \
    echo 'max_execution_time = 300'; \
    echo 'max_input_time = 300'; \
    echo 'max_input_vars = 5000'; \
} > /usr/local/etc/php/conf.d/apsd-limits.ini

# Set working directory
WORKDIR /var/www/html

# Copy entrypoint script and ensure Unix line endings
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

# Copy application files
COPY . /var/www/html/

# Set initial permissions for database and uploads directories
RUN mkdir -p /var/www/html/database /var/www/html/uploads/reports \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 777 /var/www/html/database /var/www/html/uploads

# Expose internal Apache port
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
