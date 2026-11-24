@echo off
rem BuildTrack launcher for Windows (needs PHP 8.1+ with pdo_sqlite on PATH, e.g. C:\xampp\php).
cd /d "%~dp0"
php bin\console init || goto :error
php bin\console migrate || goto :error
if "%1"=="--seed" php bin\console seed
echo.
echo   BuildTrack: open http://localhost:8080   (Ctrl+C to stop)
echo.
php -d upload_max_filesize=11M -d post_max_size=12M -S 127.0.0.1:8080 -t public bin\router.php
goto :eof
:error
echo Startup failed. Is PHP installed and on PATH? Try: php -v
pause
