<?php
/**
 * Export Outlets to CSV
 * Respects territory and role-based access control.
 */

require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth check
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die('Unauthorized access');
}

$user_role      = $_SESSION['role'] ?? '';
$user_id        = $_SESSION['id'];
$user_branch_id = $_SESSION['branch_id'] ?? null;

if (!in_array($user_role, ['admin', 'user'])) {
    die('Unauthorized access');
}

// --- Build WHERE clause ---
$where_clauses = [];
$param_types   = "";
$param_values  = [];
$user_mc_ids   = [];

// ---- Role: user — restrict by territory ----
if ($user_role === 'user') {
    $stmt_mc = $mysqli->prepare("SELECT micro_cluster_id FROM user_micro_clusters WHERE user_id = ?");
    $stmt_mc->bind_param("i", $user_id);
    $stmt_mc->execute();
    $mc_res = $stmt_mc->get_result();
    while ($row = $mc_res->fetch_assoc()) {
        $user_mc_ids[] = (int)$row['micro_cluster_id'];
    }
    $stmt_mc->close();

    if (count($user_mc_ids) > 0) {
        $mc_in = implode(',', $user_mc_ids);
        $where_clauses[] = "s.micro_cluster_id IN ($mc_in)";
    } else {
        $where_clauses[] = "s.branch_id = " . (int)$user_branch_id;
    }
}

// ---- Shared filters ----
$search_keyword = $_GET['keyword'] ?? '';
$brand_filter   = $_GET['brand'] ?? '';
$branch_filter  = $_GET['branch_id'] ?? '';
$mc_filter      = $_GET['mc_id'] ?? '';

if (!empty($search_keyword)) {
    $where_clauses[] = "(o.id_outlet LIKE ? OR o.nama_outlet LIKE ? OR s.site_name LIKE ?)";
    $param_types .= "sss";
    $keyword_like = "%" . $search_keyword . "%";
    array_push($param_values, $keyword_like, $keyword_like, $keyword_like);
}

if (!empty($brand_filter) && $user_role === 'admin') {
    $where_clauses[] = "o.brand = ?";
    $param_types .= "s";
    $param_values[] = $brand_filter;
}

if (!empty($branch_filter) && $user_role === 'admin') {
    $where_clauses[] = "s.branch_id = ?";
    $param_types .= "i";
    $param_values[] = (int)$branch_filter;
}

if (!empty($mc_filter)) {
    $allowed = true;
    if ($user_role === 'user' && count($user_mc_ids) > 0) {
        $allowed = in_array((int)$mc_filter, $user_mc_ids);
    }
    if ($allowed) {
        $where_clauses[] = "s.micro_cluster_id = ?";
        $param_types .= "i";
        $param_values[] = (int)$mc_filter;
    }
}

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

// --- Query ---
$sql = "SELECT o.id_outlet, o.nama_outlet, o.Id_Outlet_Nama_Outlet, o.brand,
               s.site_id as site_code, s.site_name, s.kecamatan, s.kabupaten,
               b.nama_branch,
               mc.nama_micro_cluster
        FROM outlets o
        LEFT JOIN sites s ON o.site_id = s.id
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id"
        . $where_sql . " ORDER BY o.nama_outlet ASC";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die('Query error: ' . $mysqli->error);
}
if (!empty($param_values)) {
    $stmt->bind_param($param_types, ...$param_values);
}
$stmt->execute();
$result = $stmt->get_result();

// --- CSV Output ---
$filename = 'export_outlets_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Headers
fputcsv($output, [
    'ID Outlet',
    'Nama Outlet',
    'ID + Nama Outlet',
    'Brand',
    'Site Code',
    'Nama Site',
    'Kecamatan',
    'Kabupaten',
    'Branch',
    'Micro Cluster',
]);

// Rows
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['id_outlet'],
        $row['nama_outlet'],
        $row['Id_Outlet_Nama_Outlet'],
        $row['brand'],
        $row['site_code'],
        $row['site_name'],
        $row['kecamatan'],
        $row['kabupaten'],
        $row['nama_branch'],
        $row['nama_micro_cluster'],
    ]);
}

fclose($output);
$stmt->close();
$mysqli->close();
exit;
