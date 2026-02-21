FROM php:8.1-cli-alpine AS base

RUN apk add --no-cache git unzip curl oniguruma-dev nodejs npm \
    && docker-php-ext-install mbstring

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
# Regenerate lock file inside the container to ensure PHP 8.1 compatibility
RUN composer update --no-dev --no-interaction --no-progress --optimize-autoloader

# Install Babel parser for full JSX/TSX/TS support
COPY bin/package.json bin/package.json
RUN cd bin && npm install --omit=dev --no-fund --no-audit

COPY src/ src/
COPY bin/ bin/
RUN chmod +x bin/analyze

ENTRYPOINT ["php", "/app/bin/analyze"]

# Test stage — installs dev deps + copies tests/
FROM base AS test

RUN composer update --no-interaction --no-progress

COPY phpunit.xml .
COPY tests/ tests/

ENTRYPOINT ["./vendor/bin/phpunit", "--configuration", "phpunit.xml"]
