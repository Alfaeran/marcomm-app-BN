<?php
require_once '../config/database.php';

// Jika user tidak login, hentikan proses
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    die("Akses ditolak.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_id = $_SESSION['id'];

    try {
        if (empty($new_password) || empty($confirm_password)) {
            throw new Exception("Password tidak boleh kosong.");
        }
        if (strlen($new_password) < 6) {
            throw new Exception("Password minimal harus 6 karakter.");
        }
        if ($new_password !== $confirm_password) {
            throw new Exception("Konfirmasi password tidak cocok.");
        }

        // Hash password baru
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        // Update password dan set flag ke 0 (false)
        $sql = "UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("si", $hashed_password, $user_id);
        $stmt->execute();

        // Update juga flag di sesi agar tidak redirect lagi
        $_SESSION["force_password_change"] = 0;

        // Redirect ke dashboard yang sesuai
        header("location: ../index.php");
        exit();

    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header("location: ../force_change_password.php");
        exit();
    }
}
?>
