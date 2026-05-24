@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0\.."

echo Starting Toothalie WebSocket Infrastructure...
echo.

start "Channel Server" cmd /k php bin/channel-server.php
timeout /t 2 /nobreak
start "WebSocket Server" cmd /k php bin/websocket-server.php
timeout /t 2 /nobreak
start "Bridge" cmd /k php bin/bridge.php

echo All workers starting in separate windows.
echo Check each window for errors.
