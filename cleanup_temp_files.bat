@echo off
echo ==============================================
echo PEMBERSIHAN FILE SEMENTARA / TESTING (MARCOMM APPS)
echo ==============================================
echo.
echo Menghapus file-file pengujian...

:: Menghapus pola file testing yang umum
if exist "check_*.php" del /q "check_*.php"
if exist "test_*.php" del /q "test_*.php"
if exist "search_*.php" del /q "search_*.php"
if exist "inspect_*.php" del /q "inspect_*.php"

:: Menghapus file di folder scratch
if exist "scratch\*.php" del /q "scratch\*.php"

echo Semua file testing (.php) telah dibersihkan!
echo.
echo PENTING: Script ini HANYA menghapus file dengan awalan check_, test_, search_, inspect_, dan isi dari folder scratch.
echo Aplikasi PHP sangat dinamis, sehingga menghapus file utama secara otomatis dengan script "pencari file tidak terpakai" sangat tidak disarankan karena bisa merusak sistem (file yang terpakai bisa salah dideteksi sebagai tidak terpakai).
echo.
pause
