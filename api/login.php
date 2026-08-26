<?php
require_once 'cors.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
$username_input = trim($data["username"] ?? $_POST["username"] ?? '');
$password_input = trim($data["password"] ?? $_POST["password"] ?? '');

if (empty($username_input) || empty($password_input)) {
    echo json_encode(["status" => "error", "message" => "Username dan password tidak boleh kosong."]);
    exit();
}

try {
    $sql = "SELECT id, username, password, role, brand, branch_id, force_password_change, has_seen_guide FROM users WHERE username = ?";
    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) throw new Exception("Gagal menyiapkan statement SQL");
    
    $stmt->bind_param("s", $username_input);
    if (!$stmt->execute()) throw new Exception("Gagal mengeksekusi statement");
    
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
                
                $stmt_update_login = $mysqli->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                if ($stmt_update_login) {
                    $stmt_update_login->bind_param("i", $db_id);
                    $stmt_update_login->execute();
                    $stmt_update_login->close();
                }
                
                if (function_exists('log_activity')) {
                    log_activity($mysqli, $db_id, $db_username, 'LOGIN_SUCCESS', 'User berhasil login (React API).');
                }
                
                echo json_encode([
                    "status" => "success", 
                    "message" => "Login berhasil",
                    "user" => [
                        "id" => $db_id,
                        "username" => $db_username,
                        "role" => $db_role,
                        "force_password_change" => $db_force_change
                    ]
                ]);
                exit();
            }
        }
    }
    
    if (function_exists('log_activity')) {
        log_activity($mysqli, null, $username_input, 'LOGIN_FAIL', 'Upaya login gagal (React API).');
    }
    echo json_encode(["status" => "error", "message" => "Username atau password yang Anda masukkan salah."]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
