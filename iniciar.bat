@echo off
chcp 65001 >nul
cd /d "%~dp0"

cls
echo ======================================
echo   ALMOX.SYS - Sistema de Almoxarifado
echo ======================================
echo.

:: Verifica se o PHP está instalado
where php >nul 2>nul
if %ERRORLEVEL% neq 0 (
    echo ERRO: PHP nao encontrado.
    echo.
    echo Para instalar o PHP no Windows:
    echo   1. Acesse: https://windows.php.net/download
    echo   2. Baixe a versao "Thread Safe x64" (.zip)
    echo   3. Extraia para C:\php
    echo   4. Adicione C:\php ao PATH do Windows
    echo      (Painel de Controle -> Sistema -> Variaveis de Ambiente)
    echo   5. Habilite a extensao pdo_sqlite no php.ini
    echo      (veja as instrucoes no README.md)
    echo.
    echo Apos instalar, clique duplo neste arquivo novamente.
    echo.
    pause
    exit /b 1
)

:: Verifica se a porta 8000 já está em uso
netstat -ano | findstr ":8000 " >nul 2>nul
if %ERRORLEVEL% equ 0 (
    echo Porta 8000 ja esta em uso.
    echo Abrindo o navegador no servidor existente...
    echo.
    start "" http://localhost:8000
    pause
    exit /b 0
)

echo   Endereco : http://localhost:8000
for /f "tokens=*" %%i in ('php -r "echo PHP_VERSION;"') do set PHP_VER=%%i
echo   PHP      : %PHP_VER%
echo.
echo   Para encerrar: feche esta janela
echo.
echo --------------------------------------
echo.

:: Abre o navegador após 2 segundos (em segundo plano)
start /min "" cmd /c "timeout /t 2 /nobreak >nul && start http://localhost:8000"

:: Inicia o servidor PHP
php -S localhost:8000
pause
