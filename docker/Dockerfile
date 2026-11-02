# syntax=docker/dockerfile:1
#
# The image conversion API as a PHP-FPM container (port 9000, FastCGI), with
# the conversion backends of the library: libvips, cwebp and gif2webp,
# avifenc, Imagick and GD, plus prlimit and bubblewrap for the isolation.
# Put nginx in front of it (deploy/nginx, docker/compose.yaml).
#
# Built for a hardened runtime: it runs as uid 10001, needs no capability
# and no privilege escalation, and writes only to TEMP_DIR (/tmp), LOCK_DIR
# (/run/image-conversion-api) and CONVERTER_CACHE_DIR
# (/var/cache/image-conversion-api), so the root filesystem can be
# read-only. docker/compose.yaml runs it that way.
#
# Build from the root of the repository:
#
#   docker build -f docker/Dockerfile -t image-conversion-api .
#
# The dependencies are installed with Composer in a build stage. To reuse a
# vendor/ installed beforehand for PHP 8.3 without dev dependencies
# ("composer install --no-dev"), pass it as a named build context:
#
#   docker build -f docker/Dockerfile --build-arg VENDOR_SOURCE=context \
#       --build-context app-vendor=./vendor -t image-conversion-api .

ARG UBUNTU_IMAGE=ubuntu:24.04
# Composer of the build stage only. Ubuntu's own (2.7) refuses a GitHub
# token with a "-", which GitHub's current installation tokens
# ("ghs_<app id>_<JWT>", base64url) may hold, and the composer_auth secret
# carries one in CI; Composer 2.10 no longer checks the token's format.
ARG COMPOSER_IMAGE=composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48
# "composer" (install in a build stage) or "context" (the "app-vendor" build context).
ARG VENDOR_SOURCE=composer

FROM ${UBUNTU_IMAGE} AS runtime-base
ENV DEBIAN_FRONTEND=noninteractive
# Ubuntu 24.04 ships PHP 8.3. ext-posix (php8.3-common) lets the library
# keep its capability and isolation caches; ext-apcu keeps the rate limit
# and metrics counters in shared memory; libfcgi-bin answers HEALTHCHECK.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        php8.3-cli \
        php8.3-fpm \
        php8.3-opcache \
        php8.3-gd \
        php8.3-imagick \
        php8.3-apcu \
        libvips-tools \
        webp \
        libavif-bin \
        bubblewrap \
        util-linux \
        libfcgi-bin \
    && rm -rf /var/lib/apt/lists/*

FROM ${COMPOSER_IMAGE} AS composer-binary

# Composer (the official image's phar), and the extensions that
# composer.json's require-dev needs:
# "composer update --no-dev" still resolves the dev requirements (PHPUnit
# needs dom, mbstring, xml and xmlwriter), it only leaves them out of
# vendor/. Only this stage has them; the runtime image stays without.
# curl makes the downloads faster.
FROM runtime-base AS vendor-tools
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
        php8.3-curl \
        php8.3-mbstring \
        php8.3-xml \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer

FROM vendor-tools AS vendor-composer
WORKDIR /build
COPY composer.json ./
# The library comes from GitHub: an optional "composer_auth" secret (the
# JSON of COMPOSER_AUTH, such as a GitHub token) avoids the anonymous API
# rate limit, without leaving the token in a layer.
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH \
    composer update --no-dev --no-scripts --no-autoloader --no-interaction --no-progress
COPY src/ src/
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

FROM scratch AS vendor-context
COPY --from=app-vendor . /build/vendor/

FROM vendor-${VENDOR_SOURCE} AS vendor

FROM runtime-base

LABEL org.opencontainers.image.title="image-conversion-api" \
      org.opencontainers.image.description="HTTP microservice that converts untrusted images to WEBP and AVIF (PHP-FPM, FastCGI on port 9000)" \
      org.opencontainers.image.licenses="BSD-3-Clause"

ARG APP_UID=10001
RUN groupadd --system --gid "${APP_UID}" image-conversion-api \
    && useradd --system --uid "${APP_UID}" --gid "${APP_UID}" --home-dir /nonexistent --no-create-home \
        --shell /usr/sbin/nologin image-conversion-api \
    && rm -f /etc/php/8.3/fpm/pool.d/www.conf \
    && install -d -o "${APP_UID}" -g "${APP_UID}" -m 0700 /var/cache/image-conversion-api /run/image-conversion-api

COPY docker/php-fpm.conf /etc/php/8.3/fpm/php-fpm.conf
COPY docker/php.ini /etc/php/8.3/fpm/conf.d/99-image-conversion-api.ini
COPY docker/php.ini /etc/php/8.3/cli/conf.d/99-image-conversion-api.ini
COPY --from=vendor /build/vendor/ /srv/image-conversion-api/vendor/
COPY composer.json .env.example /srv/image-conversion-api/
COPY bin/ /srv/image-conversion-api/bin/
COPY public/ /srv/image-conversion-api/public/
COPY src/ /srv/image-conversion-api/src/

# The configuration comes from the environment (see .env.example); these
# are the paths of the hardened runtime. API_KEY or API_KEYS must be set.
ENV TEMP_DIR=/tmp \
    LOCK_DIR=/run/image-conversion-api \
    CONVERTER_CACHE_DIR=/var/cache/image-conversion-api \
    LOG_FILE=stderr

WORKDIR /srv/image-conversion-api
USER ${APP_UID}:${APP_UID}
EXPOSE 9000
STOPSIGNAL SIGQUIT

# /healthz through FastCGI, as nginx would ask for it.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD env -i REQUEST_METHOD=GET REQUEST_URI=/healthz SCRIPT_NAME=/index.php \
        SCRIPT_FILENAME=/srv/image-conversion-api/public/index.php \
        cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q '"status":"ok"'

CMD ["php-fpm8.3", "--nodaemonize", "--force-stderr", "--fpm-config", "/etc/php/8.3/fpm/php-fpm.conf"]
