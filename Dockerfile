FROM php:8.1-apache

# Install required PHP extensions for MySQL database connection
RUN docker-php-ext-install mysqli pdo pdo_mysql && docker-php-ext-enable mysqli pdo_mysql

# Enable Apache mod_rewrite if needed
RUN a2enmod rewrite

# Copy your PHP project files to Apache root
COPY . /var/www/html/

EXPOSE 80
