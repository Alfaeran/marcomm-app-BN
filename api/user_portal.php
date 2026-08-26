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
$branch_id = $_SESSION['branch_id'];

if ($action === 'get_dashboard_stats') {
    $stats = [
        'event_count' => 0,
        'matpro_count' => 0
    ];
    
    $res = $mysqli->query("SELECT COUNT(*) as c FROM event_submissions WHERE user_id = $user_id AND MONTH(created_at) = MONTH(CURRENT_DATE())");
    if ($res) $stats['event_count'] = $res->fetch_assoc()['c'];
    
    $res = $mysqli->query("SELECT COUNT(*) as c FROM matpro_injects WHERE user_id = $user_id AND MONTH(created_at) = MONTH(CURRENT_DATE())");
    if ($res) $stats['matpro_count'] = $res->fetch_assoc()['c'];
    
    echo json_encode($stats);
    exit;
}

if ($action === 'get_events') {
    $query = "SELECT e.id, e.event_name, e.created_at, e.site_snapshot_name as branch, s.site_id, s.site_name, c.nama_kategori
              FROM event_submissions e
              LEFT JOIN sites s ON e.site_id = s.id
              LEFT JOIN event_categories c ON e.kategori_event_id = c.id
              WHERE e.user_id = $user_id
              ORDER BY e.created_at DESC";
    $res = $mysqli->query($query);
    $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['data' => $data]);
    exit;
}

if ($action === 'get_matpro_activities') {
    $query = "SELECT m.id, m.created_at, p.nama_project, t.nama_tipe, m.qty, m.status, o.outlet_name, s.site_name
              FROM matpro_injects m
              LEFT JOIN matpro_projects p ON m.project_id = p.id
              LEFT JOIN matpro_types t ON m.tipe_id = t.id
              LEFT JOIN outlets o ON m.outlet_id = o.id
              LEFT JOIN sites s ON m.site_id = s.id
              WHERE m.user_id = $user_id
              ORDER BY m.created_at DESC";
    $res = $mysqli->query($query);
    $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['data' => $data]);
    exit;
}

send_json_error('Aksi tidak valid.');
