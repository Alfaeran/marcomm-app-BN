<?php
require_once 'cors.php';
require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function send_json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'user') {
    send_json_error('Akses ditolak.', 403);
}

$action = $_GET['action'] ?? '';
$user_id = $_SESSION['id'];
$user_branch_id = $_SESSION['branch_id'];

// Get User MCs
$stmt = $mysqli->prepare("SELECT micro_cluster_id FROM user_micro_clusters WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$mc_res = $stmt->get_result();
$user_mc_ids = [];
while($row = $mc_res->fetch_assoc()) $user_mc_ids[] = (int)$row['micro_cluster_id'];
$stmt->close();
$has_specific_mc = count($user_mc_ids) > 0;
$mc_in = implode(',', $user_mc_ids);

if ($action === 'get_sites') {
    $where_clauses = [];
    if ($has_specific_mc) {
        $where_clauses[] = "s.micro_cluster_id IN ($mc_in)";
    } else {
        $where_clauses[] = "s.branch_id = $user_branch_id";
    }

    $where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

    $sql = "SELECT s.id, s.site_id, s.site_name, s.kecamatan, s.kabupaten, s.area, s.region,
                   b.nama_branch, b.brand, mc.nama_micro_cluster
            FROM sites s 
            LEFT JOIN branches b ON s.branch_id = b.id
            LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id
            $where_sql ORDER BY s.site_name ASC";
            
    $res = $mysqli->query($sql);
    $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['data' => $data]);
    exit;
}

if ($action === 'get_outlets') {
    $where_clauses = [];
    if ($has_specific_mc) {
        $where_clauses[] = "o.micro_cluster_id IN ($mc_in)";
    } else {
        $where_clauses[] = "o.branch_id = $user_branch_id";
    }

    $where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

    $sql = "SELECT o.id, o.outlet_id, o.outlet_name, o.kabupaten, o.kecamatan, 
                   b.nama_branch, mc.nama_micro_cluster
            FROM outlets o 
            LEFT JOIN branches b ON o.branch_id = b.id
            LEFT JOIN micro_clusters mc ON o.micro_cluster_id = mc.id
            $where_sql ORDER BY o.outlet_name ASC";
            
    $res = $mysqli->query($sql);
    $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['data' => $data]);
    exit;
}

send_json_error('Aksi tidak valid.');
