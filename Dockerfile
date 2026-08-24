###################################
# Base
###################################
FROM alpine:3.19 AS base

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
    php82 php82-fpm php82-curl php82-openssl php82-sqlite3 php82-pdo_sqlite \
    php82-xml php82-iconv php82-mbstring php82-dom \
    # php tar decompress
    php82-phar \
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
    CRON_CONTROL="25 * * * *" \
    CRON_UPDATE="0 5 * * *" \
    CRON_KEEPERS="15 5 * * *" \
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

# Install dependencies (prod only)
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-progress \
    --no-interaction

# Make classmap
COPY src ./src
RUN composer dump-autoload \
    --optimize \
    --classmap-authoritative

# =========================
# Development image
# =========================
FROM base AS dev

COPY /docker/debug /etc/php82/conf.d

RUN apk add --update --no-cache git php82-phar php82-pecl-xdebug php82-tokenizer
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

# Copy vendor to workdir
COPY --from=builder /app/vendor ./vendor

# Copy application to workdir
COPY database ./database
COPY version.json ./version.json
COPY public ./public
COPY src ./src
