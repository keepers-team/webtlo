# Подготовленный Web-TLO для Windows

### Особенности и ограничения
- Пользовательские данные расположены по пути `webtlo-win\nginx\wtlo\data`
- Используются порты 39080 (nginx) и 39081 (php-fpm)
- Использован ssl сертификат [ca-cert](https://curl.se/docs/caextract.html) расположенный по пути `webtlo-win\php\ssl`
- Для корректной работы ssl сертификата требуется как минимум один раз запустить web-интерфейс

### Использование web-интерфейса
- Запустить `Start.bat`
- интерфейс доступен на <a href="http://localhost:39080/">http://localhost:39080/</a>
- Для остановки web-сервера выполнить `Stop.bat`

### Использование скриптов автоматизации
Запустить `schedule-install.bat` для добавления скриптов автоматизаций в планировщик windows.  
`schedule-delete.bat` - для удаления задач.  
Скрипты можно выполнять без запуска web-сервера.  
[Подробнее о скриптах](https://webtlo.keepers.tech/configuration/automation-scripts/).
