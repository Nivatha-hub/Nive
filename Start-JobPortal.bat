@echo off
setlocal
cd /d "%~dp0"
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" (
  echo XAMPP PHP was not found at %PHP%.
  pause
  exit /b 1
)
start "JobPortal PHP Server" /D "%~dp0" cmd /k ""%PHP%" -S 127.0.0.1:8000"
timeout /t 2 /nobreak >nul
start "" "http://127.0.0.1:8000/"
endlocal
