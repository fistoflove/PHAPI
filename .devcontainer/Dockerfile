FROM phpswoole/swoole:php8.3

# Swoole, pdo_mysql, and redis are pre-installed in this image.
# Add git/unzip (Composer), MariaDB + Redis (integration tests), and pcov (coverage).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip \
        mariadb-server redis-server redis-tools \
    && docker-php-ext-enable redis \
    && pecl install pcov && docker-php-ext-enable pcov \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && install -d -m 0755 -o mysql -g mysql /run/mysqld

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
