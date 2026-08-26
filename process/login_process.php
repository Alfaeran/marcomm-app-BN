<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
require_once '../config/database.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("location: ../login.php");
    exit();
}

$username_input = trim($_POST["username"] ?? '');
$password_input = trim($_POST["password"] ?? '');

if (empty($username_input) || empty($password_input)) {
    $_SESSION['error_message'] = "Username dan password tidak boleh kosong.";
    header("location: ../login.php");
    exit();
}


try {
    // Ambil juga kolom has_seen_guide
    $sql = "SELECT id, username, password, role, brand, branch_id, force_password_change, has_seen_guide FROM users WHERE username = ?";
    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) throw new Exception("Gagal menyiapkan statement SQL: " . $mysqli->error);
    
    $stmt->bind_param("s", $username_input);
    if (!$stmt->execute()) throw new Exception("Gagal mengeksekusi statement: " . $stmt->error);
    
    $stmt->store_result();
    if ($stmt->num_rows == 1) {
        $stmt->bind_result($db_id, $db_username, $db_hashed_password, $db_role, $db_brand, $db_branch_id, $db_force_change, $db_has_seen_guide);
        if ($stmt->fetch()) {
            if (password_verify($password_input, $db_hashed_password)) {
                // Login Berhasil
                $_SESSION["loggedin"] = true;
                $_SESSION["id"] = $db_id;
                $_SESSION["username"] = $db_username;
                $_SESSION["role"] = $db_role;
                $_SESSION["brand"] = $db_brand;
                $_SESSION["branch_id"] = $db_branch_id;
                $_SESSION["force_password_change"] = $db_force_change;
                $_SESSION["has_seen_guide"] = $db_has_seen_guide; // Simpan status panduan ke sesi

                // FIXED: Use prepared statement to prevent SQL injection
                $stmt_update_login = $mysqli->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                if ($stmt_update_login) {
                    $stmt_update_login->bind_param("i", $db_id);
                    $stmt_update_login->execute();
                    $stmt_update_login->close();
                }
                log_activity($mysqli, $db_id, $db_username, 'LOGIN_SUCCESS', 'User berhasil login.');

                // Cek flag ganti password
                if ($db_force_change == 1) {
                    header("location: ../force_change_password.php");
                } else {
                    header("location: ../index.php");
                }
                exit();
            }
        }
    }
    
    log_activity($mysqli, null, $username_input, 'LOGIN_FAIL', 'Upaya login gagal.');
    throw new Exception("Username atau password yang Anda masukkan salah.");

} catch (Exception $e) {
    $_SESSION['error_message'] = $e->getMessage();
    header("location: ../login.php");
    exit();
}
?>
