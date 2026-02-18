@echo off

echo Removing old tasks...
SCHTASKS /DELETE /TN "WebTLO\Control" /F
SCHTASKS /DELETE /TN "WebTLO\Update"  /F
SCHTASKS /DELETE /TN "WebTLO\Keepers" /F
SCHTASKS /DELETE /TN "WebTLO\Reports" /F

echo Adding tasks...
SCHTASKS /CREATE /SC HOURLY /TN "WebTLO\Control" /TR "'%cd%\php\RunHiddenConsole.exe' '%cd%\php\php.exe' '%cd%\nginx\wtlo\bin\webtlo' 'cron:control'" /ST 00:25
SCHTASKS /CREATE /SC HOURLY /TN "WebTLO\Update"  /TR "'%cd%\php\RunHiddenConsole.exe' '%cd%\php\php.exe' '%cd%\nginx\wtlo\bin\webtlo' 'cron:update'"  /ST 00:15
SCHTASKS /CREATE /SC DAILY  /TN "WebTLO\Keepers" /TR "'%cd%\php\RunHiddenConsole.exe' '%cd%\php\php.exe' '%cd%\nginx\wtlo\bin\webtlo' 'cron:keepers'" /ST 05:00
SCHTASKS /CREATE /SC DAILY  /TN "WebTLO\Reports" /TR "'%cd%\php\RunHiddenConsole.exe' '%cd%\php\php.exe' '%cd%\nginx\wtlo\bin\webtlo' 'cron:reports'" /ST 06:00

pause
