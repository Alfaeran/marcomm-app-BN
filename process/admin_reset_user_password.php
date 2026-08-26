<?php
// process/admin_reset_user_password.php
require_once '../config/database.php';

// Pastikan session dimulai
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    die("Akses ditolak.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['user_id'])) {
    $user_id = (int)$_POST['user_id'];
    $default_password = 'marcomm123';
    $password_hash = password_hash($default_password, PASSWORD_DEFAULT);

    // Update password di database
    $sql = "UPDATE users SET password = ? WHERE id = ?";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("si", $password_hash, $user_id);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Password berhasil di-reset ke default (marcomm123).";
        } else {
            $_SESSION['error_message'] = "Gagal me-reset password: " . $mysqli->error;
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Gagal menyiapkan query: " . $mysqli->error;
    }
}

header("location: ../admin_manage_users.php");
exit;
?>
