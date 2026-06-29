# ─── base stage ───────────────────────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.3-bookworm AS base

WORKDIR /app

# Install PHP extensions required by Symfony + Doctrine + PostgreSQL
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    opcache \
    zip \
    apcu \
    mbstring

# Suppress PHP version header
RUN echo "expose_php = Off" > /usr/local/etc/php/conf.d/security.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ─── dev stage ────────────────────────────────────────────────────────────────
FROM base AS dev

RUN install-php-extensions xdebug

ENV APP_ENV=dev
ENV XDEBUG_MODE=develop,coverage
ENV XDEBUG_CONFIG="client_host=host.docker.internal"

COPY composer.json composer.lock* ./
RUN composer install --no-scripts --no-interaction

COPY . .
RUN composer run-script post-install-cmd --no-interaction 2>/dev/null || true

CMD ["frankenphp", "run", "--config", "/app/Caddyfile"]

# ─── prod stage ───────────────────────────────────────────────────────────────
FROM base AS prod

ENV APP_ENV=prod

COPY composer.json composer.lock* ./
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-scripts \
    --no-interaction

COPY . .

RUN composer run-script post-install-cmd --no-interaction 2>/dev/null || true \
    && php bin/console cache:warmup \
    && php bin/console asset-map:compile 2>/dev/null || true

CMD ["frankenphp", "run", "--config", "/app/Caddyfile"]
