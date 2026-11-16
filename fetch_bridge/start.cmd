@echo off
rem Double-click this file to start the fetch bridge and its Cloudflare tunnel.
rem Ctrl+C in this window stops both. Extra arguments are passed to server.py,
rem for example:  start.cmd --auto
setlocal
cd /d "%~dp0"

set "PY=python"
where py >nul 2>nul && set "PY=py"

%PY% -c "import sys; sys.exit(0 if sys.version_info >= (3, 9) else 1)" >nul 2>nul
if errorlevel 1 (
  echo.
  echo Python 3.9 or newer is required and was not found on PATH.
  echo Install it from https://www.python.org/downloads/ and tick "Add python.exe to PATH".
  echo.
  pause
  exit /b 1
)

%PY% "%~dp0launch.py" %*
set "CODE=%ERRORLEVEL%"

echo.
if not "%CODE%"=="0" echo The bridge exited with code %CODE%.
echo Bridge stopped. Press any key to close this window.
pause >nul
