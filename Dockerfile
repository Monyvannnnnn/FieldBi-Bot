FROM php:8.2-cli

# Install PostgreSQL dev libraries and PHP extensions needed for database and bot
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql mysqli \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy repository files into the container
COPY . /var/www/html/

# Run the real-time polling daemon by default
CMD ["php", "support_bot_poller.php"]
