<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

// --- Logika Filter ---
$where_clauses = [];
$param_types = "";
$param_values = [];

$search_keyword = $_POST['keyword_export'] ?? '';
$start_date = $_POST['start_date_export'] ?? '';
$end_date = $_POST['end_date_export'] ?? '';

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

$brand = $_POST['brand_export'] ?? '';
$branch_id = $_POST['branch_id_export'] ?? '';
$mc_id = $_POST['mc_id_export'] ?? '';
$area = $_POST['area_export'] ?? '';

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

// Query untuk mencari MSISDN dan data event terkait
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

// --- Membuat File Excel ---
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Laporan MSISDN');

// Header Kolom
$headers = [
    'MSISDN', 'Nama Event', 'User Input', 'Brand', 'Branch', 'Micro Cluster', 'Site Name', 'Site ID', 'Kecamatan', 'Kabupaten', 'Area', 'Region', 'Waktu Input Event'
];
$sheet->fromArray($headers, NULL, 'A1');

// Mengisi data
$row_num = 2;
while ($row = $result->fetch_assoc()) {
    $data_row = [
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
    ];
    $sheet->fromArray($data_row, NULL, 'A' . $row_num);
    $row_num++;
}

// --- Mengirim File ke Browser untuk Diunduh ---
$filename = 'laporan_msisdn_' . date('Ymd') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
?>
