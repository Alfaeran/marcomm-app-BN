<?php
/**
 * Export Users to CSV
 */

require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    die('Unauthorized access');
}

// --- Logika Filter & Urutan (Sama seperti admin_manage_users.php) ---
$where_clauses = [];
$param_types = "";
$param_values = [];

$search_keyword = $_GET['keyword'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'id_desc';
$brand_filter = $_GET['brand'] ?? '';

// Filter pencarian
if (!empty($search_keyword)) {
    $where_clauses[] = "(u.nama LIKE ? OR u.username LIKE ?)";
    $param_types .= "ss";
    $keyword_like = "%" . $search_keyword . "%";
    $param_values[] = $keyword_like;
    $param_values[] = $keyword_like;
}

// Filter Brand
if (!empty($brand_filter)) {
    $where_clauses[] = "u.brand = ?";
    $param_types .= "s";
    $param_values[] = $brand_filter;
}

// Logika Urutan
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

// Query untuk mengambil data user
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
if (!$stmt) {
    die("Error preparing query: " . $mysqli->error);
}
if (!empty($param_values)) {
    $stmt->bind_param($param_types, ...$param_values);
}
$stmt->execute();
$users_result = $stmt->get_result();

// --- CSV Output ---
$filename = 'export_users_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Headers
fputcsv($output, [
    'ID',
    'Nama Lengkap',
    'Username',
    'Role',
    'Brand',
    'Branch',
    'Micro Clusters'
]);

// Rows
while ($row = $users_result->fetch_assoc()) {
    fputcsv($output, [
        $row['id'],
        $row['nama'],
        $row['username'],
        $row['role'],
        $row['brand'] ?: '-',
        $row['nama_branch'] ?: '-',
        $row['micro_clusters_list'] ?: '-'
    ]);
}

fclose($output);
$stmt->close();
$mysqli->close();
exit;
