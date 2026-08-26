<?php
/**
 * Export Matpro Activities to CSV
 * Supports filtering by date range, user, status, etc.
 */

require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['id'])) {
    die('Unauthorized access');
}

$user_id = $_SESSION['id'];
$user_role = $_SESSION['role'] ?? 'user';

// Get filters from query params
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$filter_user_id = $_GET['user_id'] ?? '';
$keyword = $_GET['keyword'] ?? '';
$date_filter = $_GET['date_filter'] ?? '';

// Build query
$where_clauses = ["1=1"];

if ($user_role !== 'admin') {
    $where_clauses[] = "ma.user_id = " . intval($user_id);
}

if ($start_date) {
    $where_clauses[] = "DATE(ma.activity_datetime) >= '" . $mysqli->real_escape_string($start_date) . "'";
}

if ($end_date) {
    $where_clauses[] = "DATE(ma.activity_datetime) <= '" . $mysqli->real_escape_string($end_date) . "'";
}

if ($filter_user_id && $user_role === 'admin') {
    $where_clauses[] = "ma.user_id = " . intval($filter_user_id);
}

if ($keyword) {
    $keyword_term = $mysqli->real_escape_string($keyword);
    $where_clauses[] = "(ma.unique_id LIKE '%$keyword_term%' 
              OR ma.project_name LIKE '%$keyword_term%' 
              OR s.site_name LIKE '%$keyword_term%' 
              OR o.Id_Outlet_Nama_Outlet LIKE '%$keyword_term%'
              OR ma.outlet_snapshot_name LIKE '%$keyword_term%')";
}

if ($date_filter) {
    if ($date_filter === 'today') {
        $where_clauses[] = "DATE(ma.activity_datetime) = CURDATE()";
    } elseif ($date_filter === 'week') {
        $where_clauses[] = "DATE(ma.activity_datetime) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($date_filter === 'month') {
        $where_clauses[] = "MONTH(ma.activity_datetime) = MONTH(CURDATE()) AND YEAR(ma.activity_datetime) = YEAR(CURDATE())";
    }
}

$where_sql = " WHERE " . implode(" AND ", $where_clauses);

$sql = "SELECT 
            ma.id, ma.unique_id, ma.activity_datetime, ma.qty_used,
            ma.project_name, ma.type_name,
            s.site_name,
            COALESCE(o.Id_Outlet_Nama_Outlet, ma.outlet_snapshot_name, 'Outlet Telah Dihapus') as outlet_display_name,
            u.username as submitted_by,
            IFNULL(ms.stock_quantity, 0) as sisa_stock
        FROM matpro_activities ma
        LEFT JOIN sites s ON ma.site_id = s.id
        LEFT JOIN outlets o ON ma.outlet_id = o.id
        LEFT JOIN users u ON ma.user_id = u.id
        LEFT JOIN matpro_stocks ms ON ma.user_id = ms.user_id 
            AND ma.project_name = ms.project_name 
            AND ma.type_name = ms.type_name 
            AND ma.branch_id = ms.branch_id 
            AND (ma.micro_cluster_id = ms.micro_cluster_id OR (ma.micro_cluster_id IS NULL AND ms.micro_cluster_id IS NULL))
        $where_sql
        ORDER BY ma.activity_datetime DESC";

$result = $mysqli->query($sql);

if (!$result) {
    die('Query error: ' . $mysqli->error);
}

// Set headers for CSV download
$filename = 'matpro_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 support
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write headers
$headers = [
    'ID Aktivitas',
    'Tanggal Activity',
    'Project',
    'Jenis',
    'Site Name',
    'Outlet',
    'Qty',
    'Sisa Stock',
    'Submitted By'
];

fputcsv($output, $headers);

// Write data
while ($row = $result->fetch_assoc()) {
    $data = [
        $row['unique_id'],
        $row['activity_datetime'],
        $row['project_name'],
        $row['type_name'],
        $row['site_name'],
        $row['outlet_display_name'],
        $row['qty_used'],
        $row['sisa_stock'],
        $row['submitted_by']
    ];
    
    fputcsv($output, $data);
}

fclose($output);
$mysqli->close();
exit;
