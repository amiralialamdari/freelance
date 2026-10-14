@echo off
rem Runs the Laravel scheduler forever; it calls freelancer:scan every 2 minutes.
cd /d "%~dp0"
set "PATH=%USERPROFILE%\tools\php;%PATH%"
php artisan schedule:work
