@echo off
echo Removing scheduled tasks...
SCHTASKS /DELETE /TN "WebTLO\Control" /F
SCHTASKS /DELETE /TN "WebTLO\Update"  /F
SCHTASKS /DELETE /TN "WebTLO\Keepers" /F
SCHTASKS /DELETE /TN "WebTLO\Reports" /F
pause
