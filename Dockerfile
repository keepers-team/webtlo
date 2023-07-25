FROM alpine:3.16

# environment
ENV TERM "xterm-color"
ENV S6_OVERLAY_VERSION v3.1.5.0
ENV S6_KEEP_ENV 1
ENV S6_BEHAVIOUR_IF_STAGE2_FAILS 2
ENV S6_CMD_WAIT_FOR_SERVICES_MAXTIME 0
ENV S6_SERVICES_GRACETIME 1000
ENV S6_KILL_GRACETIME 1000

# packages & configure
RUN apk add --update --no-cache \
    # base tools
    bash ca-certificates curl openssl nano sqlite \
    # web server
    nginx \
    # php interpreter
    php81 php81-fpm php81-curl php81-sqlite3 php81-pdo_sqlite php81-xml php81-mbstring php81-dom \
    && rm -rf /var/cache/apk/*

# install s6 overlays
RUN \
    export S6_ARCH=$(uname -m) && \
    curl -L -s "https://github.com/just-containers/s6-overlay/releases/download/${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz" | tar Jxpf - -C / && \
    curl -L -s "https://github.com/just-containers/s6-overlay/releases/download/${S6_OVERLAY_VERSION}/s6-overlay-${S6_ARCH}.tar.xz" | tar Jxpf - -C / && \
    # Move /init somewhere else to prevent issues with podman/RHEL
    mv /init /s6-init

# copy root filesystem
COPY docker/rootfs /

# set application-specific environment
ENV WEBTLO_DIR "/data/storage"
ENV WEBTLO_CRON "true"
ENV WEBTLO_UID "nobody"
ENV WEBTLO_GID "nobody"
EXPOSE 80
VOLUME /data
WORKDIR /var/www/webtlo

# copy application to workdir
COPY cron cron
COPY css css
COPY webfonts webfonts
COPY scripts scripts
COPY php php
COPY favicon.ico .
COPY version.json .
COPY index.php .

HEALTHCHECK CMD curl -f http://localhost || exit 1
SHELL ["/bin/bash", "-c"]
ENTRYPOINT ["/s6-init"]
