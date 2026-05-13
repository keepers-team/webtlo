# Обновление Web-TLO с версии 3.x

> [!NOTE]
> Следует учесть изменение структуры проекта.
> Изменилось расположение кода относительно корня проекта,
> см `src`, `public`, `bin`, `cron`, `composer.json`
>
> Добавлен файл `/bin/webtlo cron:{action}` для запуска задач по расписанию, вместо `/cron/{action}.php`
>
>`ctrl+ F5` - Обязательно!

#### Docker
Если вы используете докер - для вас ничего не изменилось.  
Готовый образ работает, как и ранее.
Если вы не запускаете `cron` извне контейнера.  
Если запускаете - см примеры для Linux

#### Standalone / webtlo-win
При обновлении рекомендуется удалить содержимое каталога `webtlo-win\nginx\wtlo`, КРОМЕ `data` - ЭТО ВАШ КОНФИГ !  
Вставить с заменой новую сборку и запустить `Start.bat` как обычно.  
Если использовался планировщик, то задания нужно пересоздать, по причине смены исполняемого файла.

#### Самостоятельная настройка веб-сервера
Изменить путь к файлу `/root/index.php` => `/root/public/index.php`  
см
[docker-nginx](https://github.com/keepers-team/webtlo/blob/master/docker/rootfs/etc/nginx/nginx.conf),
[win-nginx](https://github.com/keepers-team/webtlo/blob/master/win/overlay/nginx/conf/nginx.conf)

Удалить старые зависимости `composer` в `webtlo/src` и установить их заново в `webtlo/`:
```bash
cd webtlo
rm -rf src/vendor
composer install --no-dev

# Опционально добавить --ignore-platform-reqs
```

Изменить путь к исполняемому файлу для автоматических задач:

Пример для Linux/Unix/Docker
```bash
# Было
php /var/www/webtlo/cron/update.php

# Стало
php /var/www/webtlo/bin/webtlo cron:update

# Можно без `php` если сделать файл исполняемым (chmod +x bin/webtlo)
```
см [docker-crontab](https://github.com/keepers-team/webtlo/blob/master/docker/rootfs/etc/crontabs/root)


Пример для Windows
```bash
# Одна задача
.\php\php.exe .\nginx\wtlo\bin\webtlo cron:reports

# Или несколько
cd php
php.exe ..\nginx\wtlo\bin\webtlo cron:update
php.exe ..\nginx\wtlo\bin\webtlo cron:keepers
# etc
```
см [manual-update.bat](https://github.com/keepers-team/webtlo/blob/master/win/overlay/manual-update.bat)
