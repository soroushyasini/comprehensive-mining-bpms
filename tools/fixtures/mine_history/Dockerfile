FROM php:8.2-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /workspace
CMD ["php", "-d", "upload_max_filesize=21M", "-d", "post_max_size=23M", "-S", "0.0.0.0:8080", "-t", "tools/fixtures/mine_history", "tools/fixtures/mine_history/router.php"]
