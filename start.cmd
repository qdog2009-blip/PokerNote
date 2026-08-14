@echo off
setlocal EnableExtensions

set "PROJECT_ROOT=%~dp0"
set "PHP_EXE=%POKERNOTE_PHP%"

if not defined PHP_EXE (
    for /f "delims=" %%I in ('where php.exe 2^>nul') do if not defined PHP_EXE set "PHP_EXE=%%I"
)

if not defined PHP_EXE (
    for /d %%D in ("%LOCALAPPDATA%\Temp\pokernote-php-7.4.*") do if exist "%%~fD\php.exe" set "PHP_EXE=%%~fD\php.exe"
)

if not defined PHP_EXE (
    echo [PokerNote] PHP 7.4 was not found.
    echo Install PHP 7.4 or set POKERNOTE_PHP to the full path of php.exe.
    exit /b 1
)

if not exist "%PHP_EXE%" (
    echo [PokerNote] php.exe does not exist: %PHP_EXE%
    exit /b 1
)

"%PHP_EXE%" -r "exit(PHP_MAJOR_VERSION === 7 && PHP_MINOR_VERSION === 4 ? 0 : 1);"
if errorlevel 1 (
    echo [PokerNote] The selected executable is not PHP 7.4: %PHP_EXE%
    echo Set POKERNOTE_PHP to the full path of a PHP 7.4 php.exe.
    exit /b 1
)
set "PHP_VERSION=7.4"

for %%I in ("%PHP_EXE%") do set "PHP_DIRECTORY=%%~dpI"
set "POKERNOTE_LOAD_SQLITE=0"
"%PHP_EXE%" -r "exit(in_array('sqlite', PDO::getAvailableDrivers(), true) ? 0 : 1);"
if errorlevel 1 (
    if not exist "%PHP_DIRECTORY%ext\php_pdo_sqlite.dll" (
        echo [PokerNote] The pdo_sqlite extension is not enabled and php_pdo_sqlite.dll was not found.
        echo Enable pdo_sqlite in php.ini, then start the service again.
        exit /b 1
    )
    set "POKERNOTE_LOAD_SQLITE=1"
)

if "%POKERNOTE_LOAD_SQLITE%"=="1" (
    "%PHP_EXE%" -d "extension_dir=%PHP_DIRECTORY%ext" -d extension=php_pdo_sqlite.dll -r "exit(in_array('sqlite', PDO::getAvailableDrivers(), true) ? 0 : 1);"
) else (
    "%PHP_EXE%" -r "exit(in_array('sqlite', PDO::getAvailableDrivers(), true) ? 0 : 1);"
)
if errorlevel 1 (
    echo [PokerNote] Failed to load the pdo_sqlite extension.
    exit /b 1
)

if "%POKERNOTE_CHECK_ONLY%"=="1" (
    echo [PokerNote] PHP %PHP_VERSION% with pdo_sqlite is ready.
    exit /b 0
)

if not defined POKERNOTE_BIND_ADDRESS set "POKERNOTE_BIND_ADDRESS=0.0.0.0"
if not defined POKERNOTE_PORT set "POKERNOTE_PORT=3000"

echo [PokerNote] PHP %PHP_VERSION% with pdo_sqlite is ready.
echo [PokerNote] Open http://localhost:%POKERNOTE_PORT%
if "%POKERNOTE_LOAD_SQLITE%"=="1" (
    "%PHP_EXE%" -d "extension_dir=%PHP_DIRECTORY%ext" -d extension=php_pdo_sqlite.dll -S %POKERNOTE_BIND_ADDRESS%:%POKERNOTE_PORT% -t "%PROJECT_ROOT%public" "%PROJECT_ROOT%router.php"
) else (
    "%PHP_EXE%" -S %POKERNOTE_BIND_ADDRESS%:%POKERNOTE_PORT% -t "%PROJECT_ROOT%public" "%PROJECT_ROOT%router.php"
)
exit /b %ERRORLEVEL%
