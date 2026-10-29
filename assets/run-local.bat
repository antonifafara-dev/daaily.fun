@echo off
setlocal
where php >nul 2>&1
if errorlevel 1 (
  echo Nie znaleziono PHP. Zainstaluj PHP z rozszerzeniem PDO_SQLite i dodaj php.exe do PATH.
  pause
  exit /b 1
)
php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"
if errorlevel 1 (
  echo PHP nie ma wlaczonego rozszerzenia PDO_SQLite. Wlacz je w php.ini.
  pause
  exit /b 1
)
php -r "exit(PHP_VERSION_ID >= 80100 ? 0 : 1);"
if errorlevel 1 (
  echo Wymagany jest PHP 8.1 lub nowszy.
  pause
  exit /b 1
)
echo Otwieram daily fun pod adresem http://127.0.0.1:8000
start "" "http://127.0.0.1:8000"
echo Aby zatrzymac serwer, wcisnij Ctrl+C.
php -S 127.0.0.1:8000 -t "%~dp0"
pause
