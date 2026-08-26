<?php
/**
 * Export Sites to CSV
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

$user_role     = $_SESSION['role'] ?? '';
$user_id       = $_SESSION['id'];
$user_branch_id = $_SESSION['branch_id'] ?? null;

if (!in_array($user_role, ['admin', 'user'])) {
    die('Unauthorized access');
}

// --- Build WHERE clause ---
$where_clauses = [];
$param_types   = "";
$param_values  = [];

// ---- Role: user — restrict by territory ----
if ($user_role === 'user') {
    // Cek micro cluster spesifik
    $stmt_mc = $mysqli->prepare("SELECT micro_cluster_id FROM user_micro_clusters WHERE user_id = ?");
    $stmt_mc->bind_param("i", $user_id);
    $stmt_mc->execute();
    $mc_res = $stmt_mc->get_result();
    $user_mc_ids = [];
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

// ---- Shared filters (from GET params) ----
$search_keyword = $_GET['keyword'] ?? '';
$brand_filter   = $_GET['brand'] ?? '';
$branch_filter  = $_GET['branch_id'] ?? '';
$mc_filter      = $_GET['mc_id'] ?? '';

if (!empty($search_keyword)) {
    $where_clauses[] = "(s.site_id LIKE ? OR s.site_name LIKE ? OR s.kecamatan LIKE ? OR s.kabupaten LIKE ?)";
    $param_types .= "ssss";
    $keyword_like = "%" . $search_keyword . "%";
    array_push($param_values, $keyword_like, $keyword_like, $keyword_like, $keyword_like);
}

if (!empty($brand_filter) && $user_role === 'admin') {
    $where_clauses[] = "b.brand = ?";
    $param_types .= "s";
    $param_values[] = $brand_filter;
}

if (!empty($branch_filter) && $user_role === 'admin') {
    $where_clauses[] = "s.branch_id = ?";
    $param_types .= "i";
    $param_values[] = (int)$branch_filter;
}

if (!empty($mc_filter)) {
    // For user role, validate that mc_filter is within their allowed MCs
    $allowed = true;
    if ($user_role === 'user' && count($user_mc_ids ?? []) > 0) {
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
$sql = "SELECT s.site_id, s.site_name, s.area, s.region,
               s.kecamatan, s.kabupaten,
               b.brand, b.nama_branch,
               mc.nama_micro_cluster
        FROM sites s
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id"
        . $where_sql . " ORDER BY s.site_name ASC";

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
$filename = 'export_sites_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Headers
fputcsv($output, [
    'Site ID',
    'Nama Site',
    'Kecamatan',
    'Kabupaten',
    'Area',
    'Region',
    'Brand',
    'Branch',
    'Micro Cluster',
]);

// Rows
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['site_id'],
        $row['site_name'],
        $row['kecamatan'],
        $row['kabupaten'],
        $row['area'],
        $row['region'],
        $row['brand'],
        $row['nama_branch'],
        $row['nama_micro_cluster'],
    ]);
}

fclose($output);
$stmt->close();
$mysqli->close();
exit;
