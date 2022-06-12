FROM php:7.4-apache

COPY . /var/www/html/
VOLUME /var/www/html/data

# Контейнер ниже предоставляет скрипт установки расширений
FROM mlocati/php-extension-installer/latest
RUN /usr/bin/install-php-extensions xmlrpc

# Используется Debian, устанавливаем cron и убираем лишние файлы
RUN apt-get update && apt-get install -y cron && which cron && \
    rm -rf /etc/cron.*/*

# Для запуска cron создаём шелл-скрипт entrypoint, который скинет окружение в /etc/environment и входим в контейнер, передавая параметры этому скрипту.
# Также позволяет запускать дополнительные команды при необходимости таковых на запуске за счёт изменения CMD при запуске контейнера.
# Порядок выполнения: все команды и аргументы, переданные в CMD как bash;
# Запущенный ENTRYPOINT заменяется на "docker-php-entrypoint apache2-foreground" ради сохранения управления контейнером с помощью docker stop и т.п.
ENTRYPOINT ["/entrypoint.sh"]

# Для запуска cron необходимо иметь /crontab файл, добавим его снаружи контейнера, стандартно - в ./crontab.
# Также стартуем php + apache, как сделали бы это без добавленных контейнеров и/или опций 
CMD ["coproc", "cron"]
