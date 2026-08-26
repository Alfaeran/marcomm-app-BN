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

$filters = [
    'keyword' => $_POST['keyword_export'] ?? '', 'start_date' => $_POST['start_date_export'] ?? '',
    'end_date' => $_POST['end_date_export'] ?? '', 'brand' => $_POST['brand_export'] ?? '',
    'branch_id' => $_POST['branch_id_export'] ?? '', 'mc_id' => $_POST['mc_id_export'] ?? '',
    'area' => $_POST['area_export'] ?? ''
];
$export_action = $_POST['action'] ?? 'export_filtered';

// --- Default Date Range (MTD) if no dates provided ---
// Only apply MTD default if it's NOT an explicit 'export_all' action
if ($export_action !== 'export_all' && empty($filters['start_date']) && empty($filters['end_date'])) {
    $filters['start_date'] = date('Y-m-01');
    $filters['end_date'] = date('Y-m-t');
}

if (!empty($filters['keyword'])) { $where_clauses[] = "(e.event_name LIKE ? OR u.username LIKE ? OR s.site_name LIKE ?)"; $param_types .= "sss"; $keyword_like = "%" . $filters['keyword'] . "%"; $param_values[] = $keyword_like; $param_values[] = $keyword_like; $param_values[] = $keyword_like; }
if (!empty($filters['start_date'])) { $where_clauses[] = "e.waktu_input >= ?"; $param_types .= "s"; $param_values[] = $filters['start_date'] . " 00:00:00"; }
if (!empty($filters['end_date'])) { $where_clauses[] = "e.waktu_input <= ?"; $param_types .= "s"; $param_values[] = $filters['end_date'] . " 23:59:59"; }
if (!empty($filters['brand'])) { $where_clauses[] = "u.brand = ?"; $param_types .= "s"; $param_values[] = $filters['brand']; }
if (!empty($filters['branch_id'])) { $where_clauses[] = "s.branch_id = ?"; $param_types .= "i"; $param_values[] = $filters['branch_id']; }
if (!empty($filters['mc_id'])) { $where_clauses[] = "s.micro_cluster_id = ?"; $param_types .= "i"; $param_values[] = $filters['mc_id']; }
if (!empty($filters['area'])) { $where_clauses[] = "s.area LIKE ?"; $param_types .= "s"; $param_values[] = "%" . $filters['area'] . "%"; }

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

// Query untuk mengambil semua data lengkap untuk ekspor
$sql = "SELECT e.*, u.username, u.nama, s.site_id as site_code, s.site_name, s.kecamatan, s.kabupaten, s.area, s.region, cat.nama_kategori, b.nama_branch, mc.nama_micro_cluster
        FROM event_submissions e
        JOIN users u ON e.user_id = u.id
        LEFT JOIN sites s ON e.site_id = s.id
        LEFT JOIN event_categories cat ON e.kategori_event_id = cat.id
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id
        $where_sql
        ORDER BY e.waktu_input ASC";

$stmt = $mysqli->prepare($sql);
if (!empty($param_values)) {
    $stmt->bind_param($param_types, ...$param_values);
}
$stmt->execute();
$result = $stmt->get_result();

// --- Membuat File Excel ---
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Laporan Event');

// Header Kolom
$headers = [
    'ID Event', 'Nama Event', 'Username Input', 'Nama User', 'Waktu Input', 
    'Site ID', 'Site Name', 'Branch', 'Micro Cluster', 'Kecamatan', 'Kabupaten', 'Area', 'Region', 'Kategori Event',
    'Latitude', 'Longitude', 'URL Foto Event', // Kolom baru untuk URL
    'SP 0K', 'SP 3GB', 'SP 5GB', 'SP 7GB', 'SP 100GB', 'FWA', 'SP Existing', 'HIT Haji/Umroh',
    'Jumlah QSC', 'Benefit SP', 'Jumlah Audience', 'Reload', 'Mobo/Paket', 'Cost', 'Benefit Total', 'Ratio Cost/Benefit (%)',
    'Provider Digunakan', 'Provider Sinyal Terbaik', 'Kenal IM3?', 'Sudah Beli IM3?', 'Lokasi Beli', 'Tertarik Beli IM3?', 'Alasan/Feedback'
];
$sheet->fromArray($headers, NULL, 'A1');

// Mengisi data
$row_num = 2;
// Dapatkan base URL aplikasi Anda
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$project_folder = 'marcomm_bn'; // Sesuaikan dengan nama folder proyek Anda
$base_url .= '/' . $project_folder;

while ($row = $result->fetch_assoc()) {
    $foto_url = $base_url . '/' . $row['foto_event_url'];
    $data_row = [
        $row['unique_id'], $row['event_name'], $row['username'], $row['nama'], $row['waktu_input'],
        $row['site_code'], $row['site_name'], $row['nama_branch'], $row['nama_micro_cluster'], $row['kecamatan'], $row['kabupaten'], $row['area'], $row['region'], $row['nama_kategori'],
        $row['location_latitude'], $row['location_longitude'], $foto_url, // Masukkan URL lengkap
        $row['sp_0k'], $row['sp_3gb'], $row['sp_5gb'], $row['sp_7gb'], $row['sp_100gb'], $row['fwa'], $row['sp_existing'], $row['hit_haji_umroh'],
        $row['jumlah_qsc'], $row['benefit_sp'], $row['jumlah_audience'], $row['reload'], $row['mobo_paket'], $row['cost'], $row['benefit_total'], $row['ratio_cost_benefit'],
        $row['provider_digunakan'], $row['provider_terbaik'], $row['kenal_im3'], $row['sudah_beli_im3'], $row['lokasi_beli'], $row['tertarik_beli_im3'], $row['alasan']
    ];
    $sheet->fromArray($data_row, NULL, 'A' . $row_num);
    // Jadikan URL sebagai hyperlink
    $sheet->getCell('Q' . $row_num)->getHyperlink()->setUrl($foto_url);
    $row_num++;
}

// --- Mengirim File ke Browser untuk Diunduh ---
$filename = 'laporan_event_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
?>
