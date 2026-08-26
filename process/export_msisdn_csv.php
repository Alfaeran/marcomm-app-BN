<?php
require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    die("Akses ditolak.");
}

// Get filters from GET request
$search_keyword = $_GET['keyword'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$brand = $_GET['brand'] ?? '';
$branch_id = $_GET['branch_id'] ?? '';
$mc_id = $_GET['mc_id'] ?? '';
$area = $_GET['area'] ?? '';

// --- Logika Filter ---
$where_clauses = [];
$param_types = "";
$param_values = [];

// Filter berdasarkan keyword (MSISDN)
if (!empty($search_keyword)) {
    $where_clauses[] = "md.msisdn LIKE ?";
    $param_types .= "s";
    $param_values[] = "%" . $search_keyword . "%";
}

// Filter berdasarkan tanggal
if (!empty($start_date)) {
    $where_clauses[] = "e.waktu_input >= ?";
    $param_types .= "s";
    $param_values[] = $start_date . " 00:00:00";
}
if (!empty($end_date)) {
    $where_clauses[] = "e.waktu_input <= ?";
    $param_types .= "s";
    $param_values[] = $end_date . " 23:59:59";
}

if (!empty($brand)) {
    $where_clauses[] = "u.brand = ?";
    $param_types .= "s";
    $param_values[] = $brand;
}
if (!empty($branch_id)) {
    $where_clauses[] = "u.branch_id = ?";
    $param_types .= "i";
    $param_values[] = (int)$branch_id;
}
if (!empty($mc_id)) {
    $where_clauses[] = "s.micro_cluster_id = ?";
    $param_types .= "i";
    $param_values[] = (int)$mc_id;
}
if (!empty($area)) {
    $where_clauses[] = "s.area = ?";
    $param_types .= "s";
    $param_values[] = $area;
}

// Gabungkan semua klausa WHERE
$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
}

// Query
$sql = "SELECT 
            md.msisdn,
            e.event_name,
            e.waktu_input,
            u.username AS user_input,
            u.brand,
            b.nama_branch,
            mc.nama_micro_cluster,
            s.site_id as site_code,
            s.site_name,
            s.kecamatan,
            s.kabupaten,
            s.area,
            s.region
        FROM msisdn_data md
        JOIN event_submissions e ON md.submission_id = e.unique_id
        JOIN users u ON e.user_id = u.id
        LEFT JOIN sites s ON e.site_id = s.id
        LEFT JOIN branches b ON u.branch_id = b.id
        LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id
        $where_sql
        ORDER BY e.waktu_input DESC";

$stmt = $mysqli->prepare($sql);
if (!empty($param_values)) {
    $stmt->bind_param($param_types, ...$param_values);
}
$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    die("Query error: " . $mysqli->error);
}

// --- Output CSV ---
$filename = 'laporan_msisdn_' . date('Ymd_His') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 support
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header Kolom
$headers = [
    'MSISDN', 'Nama Event', 'User Input', 'Brand', 'Branch', 
    'Micro Cluster', 'Site Name', 'Site ID', 'Kecamatan', 
    'Kabupaten', 'Area', 'Region', 'Waktu Input Event'
];
fputcsv($output, $headers);

// Mengisi data
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['msisdn'],
        $row['event_name'],
        $row['user_input'],
        $row['brand'],
        $row['nama_branch'],
        $row['nama_micro_cluster'],
        $row['site_name'],
        $row['site_code'],
        $row['kecamatan'],
        $row['kabupaten'],
        $row['area'],
        $row['region'],
        $row['waktu_input']
    ]);
}

fclose($output);
$mysqli->close();
exit;
