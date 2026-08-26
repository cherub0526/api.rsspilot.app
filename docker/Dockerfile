FROM hyperf/hyperf:8.3-alpine-v3.19-swoole-v6

# Set the working directory
WORKDIR /var/www

# Copy the composer files first to leverage Docker cache
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy the rest of the application code
COPY . .

# Expose the port that Hyperf listens on
EXPOSE 9501

# Start the Hyperf server
CMD ["/usr/bin/php", "/var/www/artisan", "start"]
