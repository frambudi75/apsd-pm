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
    && rm -rf /var/lib/apt/lists/*

# Configure & install PHP extensions (GD with FreeType & JPEG, PDO SQLite, PDO MySQL)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql pdo_sqlite

# Install Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set appropriate permissions for SQLite database and uploads directories
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/database /var/www/html/uploads

# Expose internal Apache port
EXPOSE 80

CMD ["apache2-foreground"]
