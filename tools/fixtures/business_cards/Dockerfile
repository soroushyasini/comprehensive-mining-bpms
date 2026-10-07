FROM php:8.2-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libpng-dev libjpeg62-turbo-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring gd \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /workspace
CMD ["php", "-d", "memory_limit=512M", "-d", "upload_max_filesize=10M", "-d", "post_max_size=12M", "-S", "0.0.0.0:8080", "-t", "tools/fixtures/business_cards/public", "tools/fixtures/business_cards/router.php"]
