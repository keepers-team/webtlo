utils\iniEdit.exe "php/php.ini" [SSL] STANDALONE_DIR=%cd%

cd nginx
start nginx.exe

cd ..\php
start RunHiddenConsole.exe php-cgi.exe -b 127.0.0.1:39081
start "" http://localhost:39080/
