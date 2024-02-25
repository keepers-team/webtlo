cd nginx
start nginx.exe
cd ..\php
SET PHP_HOME=%cd%
start RunHiddenConsole.exe php-cgi.exe -b 127.0.0.1:39081
start "" http://localhost:39080/
