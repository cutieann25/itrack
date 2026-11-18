
FROM php:8.3-apache

# Install PHP extensions for MySQL and TiDB connections
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache URL rewriting
RUN a2enmod rewrite

# Set the working directory
WORKDIR /var/www/html

# Copy the project files into Apache's web directory
COPY . /var/www/html/

# Set the web server port
EXPOSE 80
