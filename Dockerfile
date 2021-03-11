FROM php:7.4-apache

COPY . /var/www/html/
VOLUME /var/www/html/data