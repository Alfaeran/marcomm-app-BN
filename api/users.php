<?php
require_once 'cors.php';
require_once '../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');

function send_json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] ?? '') !== 'admin') {
    send_json_error('Akses ditolak. Silakan login kembali.', 403);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get_users');
$current_admin_id = $_SESSION['id'];

if ($action === 'get_users') {
    $where_clauses = [];
    $param_types = "";
    $param_values = [];

    $search_keyword = $_GET['keyword'] ?? '';
    $sort_by = $_GET['sort_by'] ?? 'id_desc';
    $brand_filter = $_GET['brand'] ?? '';

    if (!empty($search_keyword)) {
        $where_clauses[] = "(u.nama LIKE ? OR u.username LIKE ?)";
        $param_types .= "ss";
        $keyword_like = "%" . $search_keyword . "%";
        $param_values[] = $keyword_like;
        $param_values[] = $keyword_like;
    }

    if (!empty($brand_filter)) {
        $where_clauses[] = "u.brand = ?";
        $param_types .= "s";
        $param_values[] = $brand_filter;
    }

    $allowed_sorts = [
        'id_desc' => 'u.id DESC',
        'nama_asc' => 'u.nama ASC',
        'nama_desc' => 'u.nama DESC',
        'username_asc' => 'u.username ASC',
        'username_desc' => 'u.username DESC'
    ];
    $order_by_sql = $allowed_sorts[$sort_by] ?? 'u.id DESC';

    $where_sql = "";
    if (!empty($where_clauses)) {
        $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    }

    $sql = "SELECT 
                u.id, u.nama, u.username, u.role, u.brand, b.nama_branch,
                (SELECT GROUP_CONCAT(mc.nama_micro_cluster SEPARATOR ', ') 
                 FROM user_micro_clusters umc 
                 JOIN micro_clusters mc ON umc.micro_cluster_id = mc.id 
                 WHERE umc.user_id = u.id) AS micro_clusters_list
            FROM users u
            LEFT JOIN branches b ON u.branch_id = b.id
            $where_sql
            ORDER BY $order_by_sql";

    $stmt = $mysqli->prepare($sql);
    $users = [];
    if ($stmt) {
        if (!empty($param_values)) {
            $stmt->bind_param($param_types, ...$param_values);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    
    echo json_encode(['data' => $users]);
    exit;
}

if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_ids = $data['user_ids'] ?? [];
    
    if (empty($user_ids) || !is_array($user_ids)) {
        send_json_error("Pilih setidaknya satu user untuk dihapus.");
    }
    
    $deleted = 0;
    $errors = [];
    foreach ($user_ids as $uid) {
        if ($uid == $current_admin_id) {
            $errors[] = "Tidak dapat menghapus diri sendiri ($uid).";
            continue;
        }
        
        $mysqli->begin_transaction();
        try {
            // Delete user micro clusters
            $stmt1 = $mysqli->prepare("DELETE FROM user_micro_clusters WHERE user_id = ?");
            $stmt1->bind_param('i', $uid);
            $stmt1->execute();
            $stmt1->close();
            
            // Delete user
            $stmt2 = $mysqli->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
            $stmt2->bind_param('i', $uid);
            $stmt2->execute();
            
            if ($stmt2->affected_rows > 0) {
                $deleted++;
                $mysqli->commit();
            } else {
                $mysqli->rollback();
                $errors[] = "Gagal menghapus user $uid (mungkin admin atau tidak ada).";
            }
            $stmt2->close();
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $errors[] = $e->getMessage();
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Berhasil menghapus $deleted user.",
        'errors' => $errors
    ]);
    exit;
}

if ($action === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = (int)($data['user_id'] ?? 0);
    
    if ($user_id <= 0) send_json_error("ID user tidak valid.");
    if ($user_id == $current_admin_id) send_json_error("Tidak dapat reset password diri sendiri dari sini.");
    
    $default_password = password_hash('marcomm123', PASSWORD_DEFAULT);
    
    $stmt = $mysqli->prepare("UPDATE users SET password = ?, force_change_password = 1 WHERE id = ?");
    $stmt->bind_param('si', $default_password, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => "Password berhasil direset ke 'marcomm123'."]);
    } else {
        send_json_error("Gagal mereset password.");
    }
    $stmt->close();
    exit;
}

send_json_error('Aksi tidak dikenali', 400);
