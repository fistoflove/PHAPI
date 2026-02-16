FROM phpswoole/swoole:php8.3

# Swoole, pdo_mysql, and redis extensions are pre-installed in this image.
# Add git/unzip for Composer and pcov for code coverage.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && pecl install pcov && docker-php-ext-enable pcov \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist

COPY . .

RUN composer dump-autoload --optimize
