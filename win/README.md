# Подготовленный Web-TLO для Windows

### Особенности и ограничения
- не поддерживается rtorrent (php8 без xmlrpc)
- Используются порты 39080 (nginx) и 39081 (php-fpm)

### Использование web-интерфейса
- Запустить `Start.bat`
- интерфейс доступен на <a href="http://localhost:39080/">http://localhost:39080/</a>
- Для остановки web-сервера выполнить Stop.bat

### Использование скриптов автоматизации
Запустить `schedule-install.bat` для добавления скриптов автоматизаций в планировщик windows.  
`schedule-delete.bat` - для удаления задач.  
Скрипты можно выполнять без запуска web-сервера.  
[Подробнее о скриптах](https://webtlo.keepers.tech/configuration/automation-scripts/).
