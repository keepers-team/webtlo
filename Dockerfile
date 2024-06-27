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
    php81-xml php81-mbstring php81-dom \
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

# install composer
FROM composer AS builder
COPY src/composer.* ./
COPY src/back back
RUN composer install --no-dev --no-progress && composer dump-autoload -o

# image for development
FROM base AS dev
RUN apk add --update --no-cache php81-phar php81-pecl-xdebug php81-tokenizer
COPY /docker/debug /etc/php81/conf.d
# copy composer parts
COPY --from=composer /usr/bin/composer /usr/bin/composer

# image for production
FROM base AS prod
# copy application to workdir
COPY src .
# copy composer parts
COPY --from=builder /app/vendor vendor
