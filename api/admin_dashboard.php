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

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    send_json_error('Akses ditolak.', 403);
}

$action = $_GET['action'] ?? 'get_dashboard_stats';

if ($action === 'get_dashboard_stats') {
    $stats = [];
    
    // Total Event
    $res = $mysqli->query("SELECT COUNT(*) as count FROM event_submissions");
    $stats['total_events'] = $res ? $res->fetch_assoc()['count'] : 0;
    
    // Total Matpro
    $res = $mysqli->query("SELECT COUNT(*) as count FROM matpro_activities");
    $stats['total_matpro'] = $res ? $res->fetch_assoc()['count'] : 0;
    
    // Active Branches
    $res = $mysqli->query("SELECT COUNT(*) as count FROM branches");
    $stats['total_branches'] = $res ? $res->fetch_assoc()['count'] : 0;
    
    // Total Users
    $res = $mysqli->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
    $stats['total_users'] = $res ? $res->fetch_assoc()['count'] : 0;
    
    // Recent Activities (Events)
    $sql = "SELECT e.event_name as activity_name, e.waktu_input as date, u.username, 'Event' as type 
            FROM event_submissions e JOIN users u ON e.user_id = u.id ORDER BY e.waktu_input DESC LIMIT 5";
    $recent = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
    
    $stats['recent_activities'] = $recent;

    echo json_encode(['data' => $stats]);
    exit;
}

send_json_error('Aksi tidak valid.');
