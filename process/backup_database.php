<?php
// D:\laragon\www\marcomm_bn\process\backup_database.php

// Mengatur batas waktu eksekusi skrip menjadi 5 menit (300 detik)
set_time_limit(300);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Memulai sesi PHP jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Memuat file konfigurasi database
// Asumsi: file ini berada di D:\laragon\www\marcomm_bn\process\
// sehingga path ke config memerlukan '../'
require_once '../config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    $_SESSION['error_message'] = "Akses ditolak. Anda tidak memiliki izin untuk melakukan backup database.";
    header("location: ../login.php"); // Redirect ke login jika tidak ada akses
    exit;
}

// --- Konfigurasi MySQL (dari config/database.php) ---
$dbHost = DB_SERVER;
$dbUser = DB_USERNAME;
$dbPass = DB_PASSWORD;
$dbName = DB_NAME;

// --- Path ke mysqldump ---
// PENTING: Sesuaikan path ini dengan lokasi mysqldump di instalasi XAMPP Anda.
// Contoh untuk XAMPP di Windows:
$mysqldumpPath = 'D:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe';
// Contoh untuk XAMPP di Linux/macOS (mungkin tidak perlu .exe):
// $mysqldumpPath = '/Applications/XAMPP/xamppfiles/bin/mysqldump';
// $mysqldumpPath = '/opt/lampp/bin/mysqldump';

// Nama file backup
$backupFileName = $dbName . '_backup_' . date('Ymd_His') . '.sql';
$backupFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $backupFileName; // Simpan sementara di direktori temp sistem

try {
    // Bangun perintah mysqldump
    $command = "$mysqldumpPath --opt -h$dbHost -u$dbUser";
    if ($dbPass) {
        $command .= " -p$dbPass"; // Hanya tambahkan -p jika ada password
    }
    $command .= " $dbName > $backupFilePath";

    // Eksekusi perintah mysqldump
    // shell_exec() akan mengembalikan output perintah, atau NULL jika gagal
    $output = shell_exec($command);

    if ($output === null && !file_exists($backupFilePath)) {
        // Ini bisa berarti perintah tidak ditemukan, atau ada masalah eksekusi
        throw new Exception("Gagal mengeksekusi mysqldump. Pastikan path ke mysqldump sudah benar dan PHP memiliki izin untuk menjalankan perintah shell.");
    }
    
    if (!file_exists($backupFilePath) || filesize($backupFilePath) == 0) {
        throw new Exception("File backup tidak terbuat atau kosong. Periksa log server untuk detail lebih lanjut.");
    }

    // Set header untuk download file
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $backupFileName . '"');
    header('Content-Length: ' . filesize($backupFilePath));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    // Baca file dan kirim ke browser
    readfile($backupFilePath);

    // Hapus file backup sementara setelah diunduh
    unlink($backupFilePath);

    // log_activity($mysqli, $_SESSION['id'], $_SESSION['username'], 'DB_BACKUP', "Database '$dbName' berhasil di-backup.");
    exit; // Penting untuk menghentikan eksekusi setelah file dikirim

} catch (Exception $e) {
    // Tangani error dan simpan pesan ke sesi
    $_SESSION['error_message'] = "Error saat backup database: " . $e->getMessage();
    error_log("[ERROR] backup_database.php - " . $e->getMessage());

    // Coba hapus file backup yang mungkin rusak
    if (file_exists($backupFilePath)) {
        unlink($backupFilePath);
    }
    
    // Redirect kembali ke dashboard admin
    header("location: ../dashboard_admin.php");
    exit;
}
?>
