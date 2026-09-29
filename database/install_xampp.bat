@echo off
title BarangayConnect - Install database in XAMPP
setlocal

set MYSQL=C:\xampp\mysql\bin\mysql.exe
set SQL=%~dp0barangayconnect.sql

if not exist "%MYSQL%" (
  echo ERROR: XAMPP MySQL not found at C:\xampp
  echo Install XAMPP from https://www.apachefriends.org/ and try again.
  pause
  exit /b 1
)

echo Starting MySQL (if not running)...
if exist "C:\xampp\mysql_start.bat" call "C:\xampp\mysql_start.bat" >nul 2>&1
timeout /t 3 /nobreak >nul

echo Importing barangayconnect database...
"%MYSQL%" -u root < "%SQL%"
"%MYSQL%" -u root < "%~dp0demo_users.sql" >nul 2>&1
if errorlevel 1 (
  echo.
  echo IMPORT FAILED. Open XAMPP Control Panel, start MySQL, then run this file again.
  pause
  exit /b 1
)

echo.
echo SUCCESS - Database ready in XAMPP:
echo   Host:     127.0.0.1
echo   User:     root
echo   Password: (empty)
echo   Database: barangayconnect
echo.
echo Demo admin:     admin / admin123
echo Demo residents: demoresident or 09171234567 / demo123
echo                   mariasantos or 09179876543 / demo123
echo phpMyAdmin:  http://localhost/phpmyadmin  (database: barangayconnect)
echo.
pause
