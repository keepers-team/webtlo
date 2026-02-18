###################################
# Base
###################################
FROM alpine:3.17 AS base

# environment
ENV TZ="Europe/Moscow"
ENV TERM="xterm-color"
ENV S6_OVERLAY_VERSION="v3.1.4.2"
ENV S6_KEEP_ENV=1
ENV S6_BEHAVIOUR_IF_STAGE2_FAILS=2
ENV S6_CMD_WAIT_FOR_SERVICES_MAXTIME=0
ENV S6_SERVICES_GRACETIME=1000
ENV S6_KILL_GRACETIME=1000

# packages & configure
RUN apk add --update --no-cache \
    # base tools
    bash nano tzdata ca-certificates curl openssl sqlite \
    # web server
    nginx \
    # php interpreter
    php81 php81-fpm php81-curl php81-openssl php81-sqlite3 php81-pdo_sqlite \
    php81-xml php81-iconv php81-mbstring php81-dom \
    # php tar decompress
    php81-phar \
    && rm -rf /var/cache/apk/*


# install s6 overlays
ARG S6_OVERLAY_DOWNLOAD="https://github.com/just-containers/s6-overlay/releases/download/${S6_OVERLAY_VERSION}"
RUN \
    export S6_ARCH=$(uname -m) && \
    curl -L -s "${S6_OVERLAY_DOWNLOAD}/s6-overlay-noarch.tar.xz"     | tar Jxpf - -C / && \
    curl -L -s "${S6_OVERLAY_DOWNLOAD}/s6-overlay-${S6_ARCH}.tar.xz" | tar Jxpf - -C / && \
    # Move /init somewhere else to prevent issues with podman/RHEL
    mv /init /s6-init

# copy root filesystem
COPY docker/rootfs /

# set application-specific environment
ENV WEBTLO_UID=1000
ENV WEBTLO_GID=1000

# set cron environment
ENV WEBTLO_DIR="/data/storage"
ENV WEBTLO_CRON="true" \
    CRON_UPDATE="15 * * * *" \
    CRON_CONTROL="25 * * * *" \
    CRON_KEEPERS="0 5 * * *" \
    CRON_REPORTS="0 6 * * *"

EXPOSE 80
VOLUME /data
WORKDIR /var/www/webtlo

SHELL ["/bin/bash", "-c"]
ENTRYPOINT ["/s6-init"]

# =========================
# Builder stage
# =========================
FROM base AS builder

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

WORKDIR /app
COPY composer.json composer.lock ./
COPY src ./src

# Install dependencies (prod only)
RUN composer install \
    --no-dev \
    --no-progress \
    --no-interaction \
    --optimize-autoloader \
    --classmap-authoritative

# =========================
# Development image
# =========================
FROM base AS dev

COPY /docker/debug /etc/php81/conf.d

RUN apk add --update --no-cache git php81-phar php81-pecl-xdebug php81-tokenizer
RUN git config --global --add safe.directory "*"
# Copy composer for dev
COPY --from=builder /usr/bin/composer /usr/bin/composer

# =========================
# Production image
# =========================
FROM base AS prod

WORKDIR /var/www/webtlo

# Copy bin and fix rights
COPY bin ./bin
RUN chmod +x bin/webtlo

# Copy application to workdir
COPY database ./database
COPY public ./public
COPY src ./src
COPY version.json ./version.json

# Copy vendor to workdir
COPY --from=builder /app/vendor ./vendor
