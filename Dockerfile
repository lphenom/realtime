# syntax=docker/dockerfile:1.4
# =============================================================================
# lphenom/realtime — Development Dockerfile
# PHP 8.1-alpine with pdo_mysql + ext-redis + Composer 2.9.5
# SSH agent is forwarded at build time for private VCS packages.
# =============================================================================
FROM php:8.1.31-alpine
# Install system dependencies and PHP extensions
RUN apk add --no-cache \
        git \
        openssh-client \
        ${PHPIZE_DEPS} \
        mysql-client \
    && docker-php-ext-install pdo pdo_mysql \
    && pecl install redis-6.0.2 \
    && docker-php-ext-enable redis \
    && apk del ${PHPIZE_DEPS}
# Install Composer (pinned version)
COPY --from=composer:2.9.5 /usr/bin/composer /usr/bin/composer
WORKDIR /app
# Copy project files
COPY composer.json ./
# Install dependencies using SSH agent forwarded from host
# Run: DOCKER_BUILDKIT=1 docker-compose up --build
RUN --mount=type=ssh \
    mkdir -p -m 0600 /root/.ssh \
    && ssh-keyscan github.com >> /root/.ssh/known_hosts \
    && composer install --no-scripts --no-interaction --prefer-dist --optimize-autoloader
CMD ["php", "-a"]
