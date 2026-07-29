# Bor De Guna ERP production image.
#
# Two-stage: Node builds the Inertia/React front end, PHP-FPM runs the
# application. The final image contains no Node, no build tooling and no dev
# dependencies.
#
# ⚠ Built ON THE VPS (`docker compose build`), because there is no Docker on the
# developer machine and no registry in play. That is fine on a 2-core box for an
# image this size, but the build is the slowest step of a deploy.

# ---------- stage 1: front-end assets ----------
FROM node:22-alpine AS assets

WORKDIR /build

# Copy manifests first so a code-only change does not re-run the install.
COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js tsconfig.json components.json ./

# Ziggy's generated routes file is committed, but Vite still needs the PHP-side
# aliases resolved at build time; everything it reads is copied above.
RUN npm run build


# ---------- stage 2: application ----------
FROM php:8.3-fpm-alpine AS app

# pdo_mysql is the one that matters: production is MySQL 8 while the test suite
# runs SQLite, and this image must not silently fall back to sqlite because the
# driver was missing.
RUN apk add --no-cache \
        bash \
        mysql-client \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        intl \
        zip \
        gd \
        opcache \
    && rm -rf /var/cache/apk/*

# dompdf renders the payslip PDFs and needs gd; bcmath keeps money arithmetic
# honest; intl is required by the framework's formatting.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies before source, so a code change does not re-resolve them.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .
COPY --from=assets /build/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# ⚠ A pristine copy of public/, kept OUTSIDE the path the shared volume mounts
# over. The web container is plain nginx with none of these files, so public/ is
# a volume — and a named volume is seeded from the image only while it is empty.
# Without this, the second deploy would serve the first deploy's assets forever.
# The entrypoint copies this into place on every start.
RUN cp -a public /opt/public-dist

# ⚠ NO `artisan config:cache` HERE. The cache would bake in build-time
# environment values, and this same image runs as app, worker, scheduler and
# is used for one-off commands. Caching happens at container start instead,
# once the real .env is mounted. A stale config cache has already cost this
# project a debugging session (the ~27-failure auth symptom).
#
# ⚠ NO `php artisan optimize` for the same reason.

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]
