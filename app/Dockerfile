# ─── base stage ───────────────────────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.3-bookworm AS base

WORKDIR /app

# Install PHP extensions required by Symfony + Doctrine + PostgreSQL
# bcmath: used by minishlink/web-push's VAPID JWT signing (web-token/jwt-library)
# for its big-integer math -- without it, that library falls back to a pure-PHP
# calculator and emits a notice that Symfony's error handler turns into a fatal
# error (docs/push-notifications-plan.md).
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    opcache \
    zip \
    apcu \
    mbstring \
    bcmath

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
    && php bin/console asset-map:compile \
    && php bin/console cache:warmup

CMD ["frankenphp", "run", "--config", "/app/Caddyfile"]
