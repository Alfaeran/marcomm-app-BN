<?php
/**
 * Export Events to CSV
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
$site_id = $_GET['site_id'] ?? '';
$search = $_GET['search'] ?? '';
$keyword = $_GET['keyword'] ?? '';
$date_filter = $_GET['date_filter'] ?? '';

// Build query
$sql = "SELECT 
    e.unique_id,
    e.event_name,
    e.event_category,
    e.waktu_input as event_date,
    s.site_name,
    s.site_id as site_id_code,
    e.branch_name,
    e.micro_cluster_name,
    e.latitude,
    e.longitude,
    e.sp_0k,
    e.sp_3gb,
    e.sp_5gb,
    e.sp_7gb,
    e.sp_100gb,
    e.fwa,
    e.fwa_5g,
    e.sp_existing,
    e.hit_haji_umroh,
    e.jumlah_audience,
    e.reload,
    e.mobo_paket,
    e.cost,
    e.benefit_total,
    e.alasan_feedback,
    e.provider_digunakan,
    e.provider_sinyal_terbaik,
    e.mengenal_im3,
    e.sudah_beli_im3,
    e.lokasi_beli,
    e.tertarik_beli_im3,
    u.username as submitted_by
FROM event_submissions e
LEFT JOIN users u ON e.user_id = u.id
LEFT JOIN sites s ON e.site_id = s.id
WHERE 1=1";

// Apply filters
if ($user_role !== 'admin') {
    $sql .= " AND e.user_id = " . intval($user_id);
}

if ($start_date) {
    $sql .= " AND DATE(e.waktu_input) >= '" . $mysqli->real_escape_string($start_date) . "'";
}

if ($end_date) {
    $sql .= " AND DATE(e.waktu_input) <= '" . $mysqli->real_escape_string($end_date) . "'";
}

if ($filter_user_id && $user_role === 'admin') {
    $sql .= " AND e.user_id = " . intval($filter_user_id);
}

if ($site_id) {
    $sql .= " AND e.site_id = " . intval($site_id);
}

if ($search) {
    $search_term = $mysqli->real_escape_string($search);
    $sql .= " AND (e.event_name LIKE '%$search_term%' 
              OR s.site_name LIKE '%$search_term%' 
              OR e.branch_name LIKE '%$search_term%')";
}

if ($keyword) {
    $keyword_term = $mysqli->real_escape_string($keyword);
    $sql .= " AND (e.event_name LIKE '%$keyword_term%' 
              OR s.site_name LIKE '%$keyword_term%' 
              OR s.site_id LIKE '%$keyword_term%')";
}

if ($date_filter) {
    if ($date_filter === 'today') {
        $sql .= " AND DATE(e.waktu_input) = CURDATE()";
    } elseif ($date_filter === 'week') {
        $sql .= " AND DATE(e.waktu_input) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($date_filter === 'month') {
        $sql .= " AND MONTH(e.waktu_input) = MONTH(CURDATE()) AND YEAR(e.waktu_input) = YEAR(CURDATE())";
    }
}

// Additional filters to match Excel export
$brand = $_GET['brand'] ?? '';
$branch_id = $_GET['branch_id'] ?? '';
$mc_id = $_GET['mc_id'] ?? '';
$area = $_GET['area'] ?? '';

if ($brand) {
    $sql .= " AND u.brand = '" . $mysqli->real_escape_string($brand) . "'";
}
if ($branch_id) {
    $sql .= " AND s.branch_id = " . intval($branch_id);
}
if ($mc_id) {
    $sql .= " AND s.micro_cluster_id = " . intval($mc_id);
}
if ($area) {
    $sql .= " AND s.area LIKE '%" . $mysqli->real_escape_string($area) . "%'";
}

$sql .= " ORDER BY e.waktu_input DESC";

$result = $mysqli->query($sql);

if (!$result) {
    die('Query error: ' . $mysqli->error);
}

// Set headers for CSV download
$filename = 'events_export_' . date('Y-m-d_His') . '.csv';
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
    'ID',
    'Nama Event',
    'Kategori',
    'Tanggal Event',
    'Site Name',
    'Site ID',
    'Branch',
    'Micro Cluster',
    'Latitude',
    'Longitude',
    'SP 0K',
    'SP 3GB',
    'SP 5GB',
    'SP 7GB',
    'SP 100GB',
    'FWA',
    'FWA 5G',
    'SP Existing',
    'HIT Haji/Umroh',
    'Jumlah Audience',
    'Reload',
    'Mobo/Paket',
    'Cost',
    'Total Benefit',
    'Alasan/Feedback',
    'Provider Digunakan',
    'Provider Sinyal Terbaik',
    'Mengenal IM3',
    'Sudah Beli IM3',
    'Lokasi Beli',
    'Tertarik Beli IM3',
    'Submitted By'
];

fputcsv($output, $headers);

// Write data
while ($row = $result->fetch_assoc()) {
    $data = [
        $row['unique_id'],
        $row['event_name'],
        $row['event_category'],
        $row['event_date'],
        $row['site_name'],
        $row['site_id_code'],
        $row['branch_name'],
        $row['micro_cluster_name'],
        $row['latitude'],
        $row['longitude'],
        $row['sp_0k'],
        $row['sp_3gb'],
        $row['sp_5gb'],
        $row['sp_7gb'],
        $row['sp_100gb'],
        $row['fwa'],
        $row['fwa_5g'],
        $row['sp_existing'],
        $row['hit_haji_umroh'],
        $row['jumlah_audience'],
        $row['reload'],
        $row['mobo_paket'],
        $row['cost'],
        $row['benefit_total'],
        $row['alasan_feedback'],
        $row['provider_digunakan'],
        $row['provider_sinyal_terbaik'],
        $row['mengenal_im3'],
        $row['sudah_beli_im3'],
        $row['lokasi_beli'],
        $row['tertarik_beli_im3'],
        $row['submitted_by']
    ];
    
    fputcsv($output, $data);
}

fclose($output);
$mysqli->close();
exit;
