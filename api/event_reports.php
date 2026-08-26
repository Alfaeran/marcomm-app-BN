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

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get_reports');
$admin_id = (int)$_SESSION['id'];

if ($action === 'get_pending_requests') {
    $pending_requests_sql = "SELECT r.*, u.username, e.event_name, r.requested_columns, r.rejection_reason 
                             FROM event_requests r 
                             JOIN users u ON r.user_id = u.id 
                             JOIN event_submissions e ON r.event_id = e.unique_id 
                             WHERE r.status = 'pending' ORDER BY r.requested_at ASC";
    $result = $mysqli->query($pending_requests_sql);
    $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['data' => $data]);
    exit;
}

if ($action === 'get_reports') {
    $records_per_page = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($page - 1) * $records_per_page;

    $where_clauses = [];
    $param_types = "";
    $param_values = [];

    $search_keyword = $_GET['keyword'] ?? '';
    if (!empty($search_keyword)) {
        $where_clauses[] = "(e.event_name LIKE ? OR u.username LIKE ? OR s.site_name LIKE ?)";
        $param_types .= "sss";
        $keyword_like = "%" . $search_keyword . "%";
        array_push($param_values, $keyword_like, $keyword_like, $keyword_like);
    }
    
    $where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

    $count_sql = "SELECT COUNT(e.unique_id) as total FROM event_submissions e JOIN users u ON e.user_id = u.id LEFT JOIN sites s ON e.site_id = s.id $where_sql";
    
    $stmt_count = $mysqli->prepare($count_sql);
    $total_records = 0;
    if ($stmt_count) {
        if (!empty($param_values)) {
            $stmt_count->bind_param($param_types, ...$param_values);
        }
        $stmt_count->execute();
        $total_records = $stmt_count->get_result()->fetch_assoc()['total'];
        $stmt_count->close();
    }
    
    $total_pages = ceil($total_records / $records_per_page);

    $sql = "SELECT e.unique_id, e.event_name, e.waktu_input, e.benefit_total, u.username AS user_input, s.site_name, s.site_id as site_code, s.area, b.nama_branch, mc.nama_micro_cluster
            FROM event_submissions e
            JOIN users u ON e.user_id = u.id
            LEFT JOIN sites s ON e.site_id = s.id
            LEFT JOIN branches b ON s.branch_id = b.id
            LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id
            $where_sql ORDER BY e.waktu_input DESC LIMIT ? OFFSET ?";
            
    $param_types_page = $param_types . "ii";
    $param_values_page = array_merge($param_values, [$records_per_page, $offset]);

    $stmt = $mysqli->prepare($sql);
    $events = [];
    if ($stmt) {
        $stmt->bind_param($param_types_page, ...$param_values_page);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    echo json_encode([
        'data' => $events,
        'pagination' => [
            'total_records' => $total_records,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'limit' => $records_per_page
        ]
    ]);
    exit;
}

send_json_error('Aksi tidak dikenali', 400);
