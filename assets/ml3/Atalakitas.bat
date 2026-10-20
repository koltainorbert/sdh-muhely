@echo off
setlocal
rem ---------------------------------------------------------------------------
rem  MunkaLap 3 mentes (.wdg) atalakitasa csomagga (Munkalapszoftver Clone).
rem  Hasznalat: huzd a .wdg fajlt erre a fajlra. Masodik parameterkent megadhato
rem  a MunkaLap 3 programmappaja (alapesetben C:\MunkaLap\MunkaLap-3).
rem  Az eredeti mentest nem modositja.
rem ---------------------------------------------------------------------------
set "ML=%~2"
if "%ML%"=="" set "ML=C:\MunkaLap\MunkaLap-3"
if "%~1"=="" goto nincs
if not exist "%~1" goto nincs
if not exist "%ML%\lib\derby.jar" goto nincsml
set "JAVA=%ML%\jre\win-64\bin\java.exe"
if not exist "%JAVA%" set "JAVA=%ML%\jre\win-32\bin\java.exe"
if not exist "%JAVA%" set "JAVA=java"
set "CP=%~dp0munkalap-atalakito.jar;%ML%\lib\derby.jar"
if exist "%ML%\lib\derbyshared.jar" set "CP=%CP%;%ML%\lib\derbyshared.jar"
echo.
echo Atalakitas folyamatban - ez nehany percig tarthat...
echo.
"%JAVA%" -Xmx768m -cp "%CP%" MunkaLapAtalakito "%~1" "%~dpn1-csomag.zip"
if errorlevel 1 goto hiba
echo.
echo KESZ. A csomag:
echo "%~dpn1-csomag.zip"
echo.
echo Ezt toltsd fel: Beallitasok - Rendszer - Munkalapszoftver Clone.
echo.
pause
exit /b 0

:nincs
echo.
echo Huzd a MunkaLap 3 mentest (.wdg fajl) erre a fajlra: Atalakitas.bat
echo.
pause
exit /b 1

:nincsml
echo.
echo Nem talalom a MunkaLap 3 programot itt: %ML%
echo Add meg a mappajat masodik parameterkent, pl.:
echo   Atalakitas.bat "mentes.wdg" "D:\MunkaLap\MunkaLap-3"
echo.
pause
exit /b 1

:hiba
echo.
echo Az atalakitas nem sikerult. A fenti hibauzenetet kuldd el a rendszergazdanak.
echo.
pause
exit /b 1
