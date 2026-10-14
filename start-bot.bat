@echo off
rem Runs the Laravel scheduler forever; it calls freelancer:scan every 2 minutes.
rem Registered in Task Scheduler as "FreelancerBot" (runs as SYSTEM at boot).
cd /d "%~dp0"
if exist "C:\Users\Administrator\tools\php\php.exe" set "PATH=C:\Users\Administrator\tools\php;%PATH%"
php artisan schedule:work
