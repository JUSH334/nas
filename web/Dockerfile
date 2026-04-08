FROM php:8.2-apache

# Install PHP extensions needed for MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install zip/unzip for file management features
RUN apt-get update && apt-get install -y zip unzip && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
