# syntax=docker/dockerfile:1.7

# Khronoz production image.
#
# One image, four roles, selected by the container's command: `web` (default),
# `horizon`, `schedule`, `migrate`. Anything else execs through. See
# docker/entrypoint.sh.
#
# The build stage is Debian and the runtime is Alpine, deliberately. Vite's
# native dependencies -- rollup, @tailwindcss/oxide, lightningcss -- resolve to
# their glibc builds, and the toolchain is 300 MB nobody needs at runtime.
# vendor/ is pure PHP and the built assets are plain JS/CSS, so both cross the
# libc boundary without issue.

ARG PHP_VERSION=8.5
ARG NODE_VERSION=22
ARG NPM_VERSION=11.19.1

# Pinned: `pecl install` without a version takes whatever is current at build
# time.
ARG REDIS_VERSION=6.3.0
ARG SWOOLE_VERSION=6.2.2

# ---------------------------------------------------------------------------
# builder -- composer deps, Vite client build, Inertia SSR bundle
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-bookworm AS builder

ARG NODE_VERSION
ARG NPM_VERSION

# `vite build` shells out to `php artisan wayfinder:generate`, so this stage
# needs a bootable Laravel application, not just Node.
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# pcntl is not optional here even though this stage runs no worker:
# laravel/horizon requires it, so composer refuses to resolve the lock file
# without it.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip \
        libicu-dev libpq-dev libzip-dev; \
    docker-php-ext-install -j"$(nproc)" intl pcntl pdo_pgsql zip; \
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_VERSION}.x" | bash -; \
    apt-get install -y --no-install-recommends nodejs; \
    npm install -g "npm@${NPM_VERSION}"; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependency layers first, keyed on the lockfiles alone, so a source-only change
# does not re-resolve either dependency tree.
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-progress

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .

# package:discover boots the application, so it runs only once the source is in
# place and the autoloader is optimized.
RUN set -eux; \
    composer dump-autoload --optimize --no-dev --classmap-authoritative; \
    php artisan package:discover --ansi

# `build` is already `vite build && vite build --ssr`. `build:ssr` alone would
# emit the SSR bundle and no client assets.
RUN npm run build

# Blade and event caches are environment-independent, so they are safe to bake.
# config:cache and route:cache are NOT -- they happen at container start, where
# the real environment exists.
RUN set -eux; \
    php artisan view:cache; \
    php artisan event:cache

# Strip everything the runtime will not read.
#
#   - node_modules: the SSR bundle is self-contained, see vite.config.js.
#   - resources/js: compiled into public/build already. Blade's @vite resolves
#     entry names through public/build/manifest.json and never opens the source.
#   - sourcemaps: never read in production.
#   - AWS service models: ~51 MB of API definitions for services we never call.
RUN set -eux; \
    php docker/prune.php; \
    rm -rf node_modules resources/js package-lock.json \
        .env storage/logs/* bootstrap/cache/config.php; \
    find bootstrap/ssr public/build -name '*.map' -delete

# ---------------------------------------------------------------------------
# runtime -- Alpine, no toolchain, no node_modules
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-alpine AS runtime

ARG REDIS_VERSION
ARG SWOOLE_VERSION

# `auto` is resolved from the cgroup CPU quota by docker/entrypoint.sh. Swoole
# and Node both report host cores instead. OCTANE_MAX_REQUESTS recycles a worker
# after N requests, bounding leaks.
#
# The SSR address is fixed here rather than inherited from .env: 43714 is the
# host-facing port for development, and inside the container the renderer is
# only ever reached over loopback.
ENV PORT=8000 \
    OCTANE_SERVER=swoole \
    OCTANE_WORKERS=auto \
    OCTANE_TASK_WORKERS=auto \
    OCTANE_MAX_REQUESTS=10000 \
    SSR_WORKERS=1 \
    INERTIA_SSR_PORT=13714 \
    INERTIA_SSR_URL=http://127.0.0.1:13714 \
    APP_ENV=production \
    APP_DEBUG=false

# nodejs runs the Inertia SSR bundle. vite.config.js sets ssr.noExternal for the
# SSR build, so that bundle inlines its npm dependencies and leaves only Node
# builtins as bare imports -- which is what lets npm and node_modules stay out of
# this stage.
#
# gd carries endroid/qr-code; mbstring, posix and opcache are compiled into the
# base image and must not be passed to docker-php-ext-install.
RUN set -eux; \
    apk add --no-cache \
        nodejs runit tini \
        c-ares freetype icu-libs libjpeg-turbo libpng libpq libzip brotli-libs; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        brotli-dev c-ares-dev curl-dev freetype-dev icu-dev libjpeg-turbo-dev \
        libpng-dev libzip-dev linux-headers openssl-dev \
        pcre2-dev postgresql-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath gd intl pcntl pdo_pgsql pgsql sockets zip; \
    pecl install "redis-${REDIS_VERSION}"; \
    pecl install --configureoptions 'enable-openssl="yes" enable-sockets="yes" enable-http2="yes"' "swoole-${SWOOLE_VERSION}"; \
    docker-php-ext-enable redis swoole; \
    strip --strip-debug /usr/local/lib/php/extensions/*/*.so; \
    pecl clear-cache; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear \
        /usr/local/lib/php/test /usr/local/lib/php/doc \
        /usr/local/bin/php-cgi /usr/local/bin/phpdbg \
        /usr/local/include/php

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-khronoz.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/cluster.mjs /usr/local/bin/cluster.mjs
COPY docker/web.sh /usr/local/bin/web
COPY docker/finish.sh /usr/local/bin/finish
COPY docker/octane.sh /etc/service/octane/run
COPY docker/ssr.sh /etc/service/ssr/run

# runsv writes its state into supervise/ inside each service directory, so the
# tree has to belong to the user the container runs as. One shared finish script,
# symlinked in: it identifies the service from its working directory.
#
# Named `sail`: ./sail execs as $APP_USER, which defaults to that.
RUN set -eux; \
    chmod +x /usr/local/bin/entrypoint /usr/local/bin/web \
        /usr/local/bin/finish /etc/service/*/run; \
    ln -s /usr/local/bin/finish /etc/service/octane/finish; \
    ln -s /usr/local/bin/finish /etc/service/ssr/finish; \
    addgroup -g 1000 -S sail; \
    adduser -u 1000 -S -G sail -h /app sail; \
    chown -R sail:sail /etc/service

WORKDIR /app

COPY --from=builder --chown=sail:sail /app /app

# Writable at runtime; everything else stays owned by root and read-only to the
# application user.
RUN set -eux; \
    mkdir -p storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache; \
    chown -R sail:sail storage bootstrap/cache; \
    chmod -R u+rwX storage bootstrap/cache

USER sail

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:".getenv("PORT")."/up") ? 0 : 1);'

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint"]
CMD ["web"]
