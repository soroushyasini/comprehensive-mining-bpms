FROM php:8.2-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /workspace
CMD ["php", "-d", "upload_max_filesize=50M", "-d", "post_max_size=55M", "-S", "0.0.0.0:8080", "-t", "tools/fixtures/minutes/public", "tools/fixtures/minutes/router.php"]
