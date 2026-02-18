![GitHub Release](https://img.shields.io/github/v/release/keepers-team/webtlo)
![GitHub Downloads (all assets, latest release)](https://img.shields.io/github/downloads/keepers-team/webtlo/latest/total?label=downloads)

# web-TLO

Веб-приложение для управления торрентами.

![image](https://github.com/keepers-team/webtlo/assets/54838254/80ba630e-2f1c-48bc-b736-ad0544925cae)

### Основные функциональные возможности:
- получение данных о раздачах из хранимых подразделов форума
- получение сведений о хранимых раздачах в торрент-клиентах
- формирование и отправка отчётов о хранимых раздачах
- управление раздачами в поддерживаемых торрент-клиентах
  - добавление
  - удаление
  - остановка/запуск, в т.ч. по расписанию
  - добавление меток/категорий
---

## При обновлении с версии webTLO 3.x
> [!NOTE]
> Следует учесть изменение структуры проекта.  
> Изменилось расположение кода относительно корня проекта,
> см `src`, `public`, `bin`, `cron`, `composer.json`  
> Добавлен файл `/bin/webtlo cron:{action}` для запуска задач по расписанию, вместо `/cron/{action}.php`

#### Docker
Если вы используете докер - для вас ничего не изменилось.  
Готовый образ работает, как и ранее.
Если вы не запускаете `cron` извне контейнера.

#### Standalone / webtlo-win
При обновлении рекомендуется удалить содержимое каталога `webtlo-win\nginx\wtlo`, КРОМЕ `data` - ЭТО ВАШ КОНФИГ !  
Вставить с заменой новую сборку и запустить `Start.bat` как обычно.  
Если использовался планировщик, то задания нужно пересоздать, по причине смены исполняемого файла.

#### Самостоятельная настройка веб-сервера
Изменить путь к файлу `/root/index.php` => `/root/public/index.php`  
см
[docker-nginx](https://github.com/keepers-team/webtlo/blob/4.x/docker/rootfs/etc/nginx/nginx.conf)
[win-nginx](https://github.com/keepers-team/webtlo/blob/4.x/win/overlay/nginx/conf/nginx.conf)

Изменить путь к исполняемому файлу для автоматических задач:

```bash
# Было
php /var/www/webtlo/cron/update.php

# Стало
php /var/www/webtlo/bin/webtlo cron:update

# Можно без `php` если сделать файл исполняемым (chmod +x bin/webtlo)
```
см [docker-crontab](https://github.com/keepers-team/webtlo/blob/4.x/docker/rootfs/etc/crontabs/root)

[//]: # (@TODO Исправить ссылки на master)

---

[//]: # (@TODO Поднять до php8.2)
### Системные требования
Любой веб-сервер с поддержкой PHP 8.1+ (Nginx/Apache2+) и SQLite 3.38+.

### Установка
[Последний релиз](https://github.com/keepers-team/webtlo/releases/latest)

#### Docker
Готовый docker образ:
- `docker pull ghcr.io/keepers-team/webtlo:4.x`
- `docker pull berkut174/webtlo:4.x`

Примеры docker compose можно посмотреть в [docker-compose.yml](https://github.com/keepers-team/webtlo/blob/master/docker-compose.yml).

Сборка из исходников, например с помощью [docker-compose.dev.yml](https://github.com/keepers-team/webtlo/blob/master/docker-compose.dev.yml).

#### Windows
- **Standalone** сборка (рекомендуется), скачать [webtlo-win.zip](https://github.com/keepers-team/webtlo/releases/latest/download/webtlo-win.zip), распаковать в желаемое место, запустить `Start.bat`.
Подробности [тут](https://github.com/keepers-team/webtlo/blob/master/win/README.md).
- Подготовленный [webtlo.zip](https://github.com/keepers-team/webtlo/releases/latest/download/webtlo.zip) для самостоятельной установки в любой подходящий веб-сервер.


#### Из репозитория
- клонировать репозиторий `git clone https://github.com/keepers-team/webtlo.git`
- установить [composer](https://getcomposer.org)
- установить зависимости `composer install --no-dev`
- настроить желаемый веб-сервер


### Важно
В случае самостоятельной настройки рекомендуется в php.ini добавить игнорирование ошибок:  
`error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT`  

Увеличить лимиты памяти и используемых переменных:
- `memory_limit = 2G`
- `max_input_vars=100000`

Пример настроек `php.ini`:
[docker-php-ini](https://github.com/keepers-team/webtlo/blob/master/docker/rootfs/etc/php81/php.ini),
[standalone-php-ini](https://github.com/keepers-team/webtlo/blob/master/win/overlay/php/php.ini)
