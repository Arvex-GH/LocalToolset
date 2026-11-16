@echo off
rem Double-click this file to give the bridge a brand-new token.
rem It writes the token into fetch_bridge\.env and shows it; the tunnel URL
rem stays the same, but the running bridge has to be restarted to use it.
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

%PY% "%~dp0launch.py" --new-token
set "CODE=%ERRORLEVEL%"

echo.
if not "%CODE%"=="0" echo Could not write a new token ^(code %CODE%^).
echo Press any key to close this window - copy the token first.
pause >nul
