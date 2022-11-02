FROM php:7.4-apache

WORKDIR /var/www/html

COPY . .
RUN mkdir -m777 data && chown www-data: data && echo 'TimeOut 3600' >> /etc/apache2/apache2.conf
VOLUME /var/www/html/data