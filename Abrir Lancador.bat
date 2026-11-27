@echo off
rem Abre o Lancador de Chamados (PHP Desktop). Coloque este arquivo na pasta do PHP Desktop.
cd /d "%~dp0"
if not exist "%~dp0phpdesktop-chrome.exe" (
  echo Nao encontrei o phpdesktop-chrome.exe nesta pasta.
  echo Veja "Instalar a partir deste repositorio" no README.md.
  pause
  exit /b 1
)
start "" "%~dp0phpdesktop-chrome.exe"
