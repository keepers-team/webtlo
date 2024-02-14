![GitHub Release](https://img.shields.io/github/v/release/keepers-team/webtlo)
![GitHub Downloads (all assets, latest release)](https://img.shields.io/github/downloads/keepers-team/webtlo/latest/total?label=downloads)

# web-TLO

Веб-приложение для управления торрентами.

![image](https://github.com/keepers-team/webtlo/assets/54838254/80ba630e-2f1c-48bc-b736-ad0544925cae)

### Основные функциональные возможности:
- получение данных о раздачах из хранимых подразделов форума
- получение сведений о хранимых раздачах в торрент-клиентах
- формирование отчётов о хранимых раздачах
- управление раздачами в поддерживаемых торрент-клиентах
  - добавление
  - удаление
  - остановка/запуск в т.ч. по расписанию
  - добавление меток/категорий

### Установка
Перейдите на вкладку с последним [релизом](https://github.com/keepers-team/webtlo/releases/latest) и скачайте подходящий архив:
- `webtlo-win.zip` - [standalone](https://github.com/keepers-team/webtlo/tree/readme/win) архив для windows без необходимости устаналивать веб-сервер
- `webtlo.zip` - для самостоятельной установки в любой подходящий веб-сервер

Или используйте docker образ, например:  
`docker pull berkut174/webtlo:latest`  
Примеры настройки docker-compose можно посмотреть в [docker-compose.yml](https://github.com/keepers-team/webtlo/blob/master/docker-compose.yml)

Или скачайте репозиторий:  
- клонировать репозиторий `git clone https://github.com/keepers-team/webtlo.git`
- установить [composer](https://getcomposer.org)
- установить зависимости `cd src && composer install --no-dev`
- настроить желаемый веб-сервер 

### Системные требования
Любой веб-сервер с поддержкой PHP (Nginx/Apache2+ и PHP 8.1).

В случае самостоятельной настройки рекомендуется в php.ini добавить игнорирование ошибок:  
`error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT`
