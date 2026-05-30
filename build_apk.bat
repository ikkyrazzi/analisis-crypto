@echo off
echo ========================================================
echo   Membangun APK Mobile Web View (KyySIS)
echo ========================================================
echo.

REM Set JAVA_HOME otomatis ke Android Studio bawaan
set JAVA_HOME=C:\Program Files\Android\Android Studio\jbr
if not exist "%JAVA_HOME%\bin\java.exe" (
    echo [ERROR] Java tidak ditemukan di Android Studio!
    echo Pastikan Android Studio terinstal.
    pause
    exit /b
)

echo [1/3] Menjalankan Sync Capacitor...
call npx cap sync

echo.
echo [2/3] Meng-compile APK Android...
cd android
call gradlew assembleDebug
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Gagal meng-compile APK.
    cd ..
    pause
    exit /b
)
cd ..

echo.
echo [3/3] Menyalin APK ke folder utama...
copy android\app\build\outputs\apk\debug\app-debug.apk KyySIS.apk > nul

echo.
echo ========================================================
echo   SUKSES! Aplikasi Anda sudah siap diinstall.
echo   File APK ada di: KyySIS.apk
echo ========================================================
pause
