<?php
require_once '../config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

$admin_id = $_SESSION['id'];
$admin_username = $_SESSION['username'];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action === 'delete_all') {
            // Hapus semua data dari tabel log
            $mysqli->query("TRUNCATE TABLE activity_logs");

            // Catat aksi penghapusan ini sebagai log baru
            log_activity($mysqli, $admin_id, $admin_username, 'LOG_CLEAR', 'Semua log aktivitas telah dihapus.');
            
            $_SESSION['success_message'] = "Semua log aktivitas berhasil dihapus.";
        } else {
            throw new Exception("Aksi tidak valid.");
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    
    header("location: ../admin_activity_log.php");
    exit();
}
?>
