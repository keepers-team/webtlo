FROM php:7.4-apache
WORKDIR /var/www/html

COPY . .
RUN mkdir -m777 data && chown www-data: data
VOLUME data
