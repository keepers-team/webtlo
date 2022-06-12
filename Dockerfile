FROM php:7.4-apache
FROM mlocati/php-extension-installer/latest
RUN /usr/bin/install-php-extensions xmlrpc
RUN apt-get update && apt-get install -y cron && which cron && \
    rm -rf /etc/cron.*/*

COPY . /var/www/html/
VOLUME /var/www/html/data
