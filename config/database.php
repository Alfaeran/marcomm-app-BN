<?php
// config/database.php

/**
 * Memulai sesi PHP di paling atas.
 * Ini adalah langkah paling penting untuk memastikan sesi konsisten di semua halaman.
 */
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Mengaktifkan output buffering untuk mencegah error "headers already sent".
ob_start();
date_default_timezone_set('Asia/Makassar'); // WITA - Bali & Nusa Tenggara
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'D:\Marcomm-app-BN\logs\my_php_errors.log'); // di luar source tree (deploy = junction)

// --- Konfigurasi Database ---
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'marcomm_event_bn');

// Buat koneksi
$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Cek koneksi
if ($mysqli->connect_errno) {
    error_log("Koneksi database gagal: " . $mysqli->connect_error);
    die("ERROR: Tidak dapat terhubung ke database. Silakan coba lagi nanti.");
}

// Set karakter set
$mysqli->set_charset("utf8mb4");

// --- Fungsi Helper Global ---

/**
 * Mengambil pengaturan aplikasi dari database.
 * Menggunakan cache statis untuk efisiensi.
 */
if (!function_exists('get_setting')) {
    function get_setting($db_connection, $key) {
        static $settings = null;
        
        if ($settings === null) {
            $settings = [];
            // PERBAIKAN: Menggunakan nama tabel 'settings' dan kolom 'setting_key'
            // Tabel 'app_settings' tidak ditemukan di file SQL Anda.
            $result = $db_connection->query("SELECT setting_key, setting_value FROM settings");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
                $result->free();
            }
        }
        return $settings[$key] ?? null;
    }
}

/**
 * Fungsi untuk memeriksa sesi admin.
 * Ini akan mencegah redirect ke halaman login jika sesi sudah aktif.
 */
if (!function_exists('check_admin_session')) {
    function check_admin_session() {
        if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
            header("location: login.php");
            exit;
        }
    }
}

/**
 * Fungsi untuk memeriksa sesi user biasa.
 */
if (!function_exists('check_user_session')) {
    function check_user_session() {
        if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
            header("location: login.php");
            exit;
        }
    }
}

/**
 * Fungsi untuk mencatat aktivitas ke dalam log.
 */
if (!function_exists('log_activity')) {
    // PERBAIKAN: Menyesuaikan nama parameter agar cocok dengan nama kolom di database.
    function log_activity($mysqli_conn, $user_id, $username, $action, $description, $ip_address = null, $user_agent = null) {
        // PERBAIKAN: Menggunakan nama kolom 'action' dan 'description' sesuai database Anda.
        $sql = "INSERT INTO activity_logs (user_id, username, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli_conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("isssss", $user_id, $username, $action, $description, $ip_address, $user_agent);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log("Gagal menyiapkan statement log aktivitas: " . $mysqli_conn->error);
        }
    }
}

/**
 * Helper function for dynamic bind_param (compatible with PHP < 5.6 and PHP 8.1+)
 */
if (!function_exists('ref_values')) {
    function ref_values(&$arr){
        $refs = [];
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
}

/**
 * Fungsi untuk mengecek apakah bulky import diperbolehkan saat ini.
 */
if (!function_exists('is_bulk_import_allowed')) {
    function is_bulk_import_allowed($mysqli) {
        $status = get_setting($mysqli, 'bulk_import_status');
        if ($status !== '1') return [ 'allowed' => false, 'message' => 'Fitur Bulk Import sedang dinonaktifkan oleh Admin.' ];

        $start_time = get_setting($mysqli, 'bulk_import_start_time') ?: '00:00';
        $end_time   = get_setting($mysqli, 'bulk_import_end_time') ?: '23:59';
        $start_day  = (int)(get_setting($mysqli, 'bulk_import_start_day') ?: 1);
        $end_day    = (int)(get_setting($mysqli, 'bulk_import_end_day') ?: 31);

        $current_time = date('H:i');
        $current_day_num = (int)date('j');

        if ($current_day_num < $start_day || $current_day_num > $end_day) {
            return [ 'allowed' => false, 'message' => 'Bulk Import hanya diperbolehkan antara tanggal ' . $start_day . ' s/d ' . $end_day . ' tiap bulannya.' ];
        }

        if ($current_time < $start_time || $current_time > $end_time) {
            return [ 'allowed' => false, 'message' => 'Bulk Import hanya diperbolehkan antara jam ' . $start_time . ' s/d ' . $end_time ];
        }

        return [ 'allowed' => true ];
    }
}
