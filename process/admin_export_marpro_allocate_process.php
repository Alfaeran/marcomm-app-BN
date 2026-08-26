<?php
// process/admin_export_marpro_allocate_process.php

@ob_start(); // Mulai output buffering
set_time_limit(0); // Hapus batas waktu eksekusi
ini_set('memory_limit', '512M');

require_once '../config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    if (ob_get_level() > 0) ob_end_clean();
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if (!function_exists('ref_values')) {
    function ref_values(&$arr){
        $refs = [];
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
}

$where_clauses = [];
$param_types = "";
$param_values = [];

$search_keyword = $_POST['keyword'] ?? '';
$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';
$brand_filter = $_POST['brand'] ?? '';
$branch_filter = $_POST['branch_id'] ?? '';
$mc_filter = $_POST['mc_id'] ?? '';
$project_filter = $_POST['project_name'] ?? '';
$type_filter = $_POST['type_name'] ?? '';
$export_type = $_POST['action'] ?? 'export_filtered';

if ($export_type === 'export_mtd') {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
} elseif ($export_type === 'export_filtered') {
    // Keep user submitted dates if any
} else {
    // export_all: clear dates
    $start_date = '';
    $end_date = '';
}

if (!empty($search_keyword)) {
    $where_clauses[] = "(u.username LIKE ? OR mr.project_name LIKE ? OR mr.type_name LIKE ? OR b.nama_branch LIKE ? OR mc.nama_micro_cluster LIKE ?)";
    $param_types .= "sssss";
    $keyword_like = "%" . $search_keyword . "%";
    array_push($param_values, $keyword_like, $keyword_like, $keyword_like, $keyword_like, $keyword_like);
}
if (!empty($start_date) && !empty($end_date)) {
    $where_clauses[] = "ma.tanggal_alokasi BETWEEN ? AND ?";
    $param_types .= "ss";
    $param_values[] = $start_date;
    $param_values[] = $end_date;
} elseif (!empty($start_date)) {
    $where_clauses[] = "ma.tanggal_alokasi >= ?";
    $param_types .= "s";
    $param_values[] = $start_date;
} elseif (!empty($end_date)) {
    $where_clauses[] = "ma.tanggal_alokasi <= ?";
    $param_types .= "s";
    $param_values[] = $end_date;
}
if (!empty($brand_filter)) { $where_clauses[] = "b.brand = ?"; $param_types .= "s"; $param_values[] = $brand_filter; }
if (!empty($branch_filter)) { $where_clauses[] = "mr.branch_id = ?"; $param_types .= "i"; $param_values[] = (int)$branch_filter; }
if (!empty($mc_filter)) { $where_clauses[] = "ma.micro_cluster_id = ?"; $param_types .= "i"; $param_values[] = (int)$mc_filter; }
if (!empty($project_filter)) { $where_clauses[] = "mr.project_name = ?"; $param_types .= "s"; $param_values[] = $project_filter; }
if (!empty($type_filter)) { $where_clauses[] = "mr.type_name = ?"; $param_types .= "s"; $param_values[] = $type_filter; }

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

$sql = "SELECT ma.*, u.username, u.nama as user_nama, mr.project_name, mr.type_name, mc.nama_micro_cluster, b.nama_branch, b.brand
        FROM marpro_allocations ma
        JOIN marpro_receives mr ON ma.receive_id = mr.id
        JOIN users u ON ma.user_id = u.id
        JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
        JOIN branches b ON mr.branch_id = b.id"
        . $where_sql . " ORDER BY ma.tanggal_alokasi DESC, ma.id DESC";

$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
    if (ob_get_level() > 0) ob_end_clean();
    $_SESSION['error_message'] = "Gagal menyiapkan query ekspor CSV: " . $mysqli->error;
    header("location: ../admin_laporan_marpro_allocate.php");
    exit;
}

if (!empty($param_values)) {
     if (!call_user_func_array([$stmt, 'bind_param'], array_merge([$param_types], ref_values($param_values)))) {
        if (ob_get_level() > 0) ob_end_clean();
        $_SESSION['error_message'] = "Gagal mengikat parameter ekspor CSV: " . $stmt->error;
        header("location: ../admin_laporan_marpro_allocate.php");
        exit;
    }
}

try {
    $stmt->execute();
    $result = $stmt->get_result();

    $filename = 'Laporan_Alokasi_Marpro_' . date('Ymd_His') . '.csv';

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new Exception("Gagal membuka output stream.");
    }

    $headers = [
        'ID Alokasi', 'Tanggal Alokasi', 'Username Alokator', 'Nama Alokator', 
        'Branch Asal', 'Brand', 'Proyek', 'Jenis Marpro', 'Jumlah Alokasi (Pcs)', 
        'Micro Cluster Tujuan', 'Latitude', 'Longitude', 'URL Foto Bukti'
    ];
    fputcsv($output, $headers);

    $base_domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
    $script_path = dirname($_SERVER['SCRIPT_NAME']);
    $app_base_path = dirname($script_path);
    $app_base_path = ($app_base_path == '/' || $app_base_path == '\\') ? '' : $app_base_path;
    $project_base_url = rtrim($base_domain, '/') . $app_base_path;

    while ($row = $result->fetch_assoc()) {
        $db_path = $row['photo_url'] ?? '';
        $photo_full_url = '';

        if (!empty($db_path)) {
            if (preg_match('/^https?:\/\//', $db_path)) {
                $photo_full_url = $db_path;
            } else {
                $clean_path = ltrim($db_path, '/');
                $photo_full_url = $project_base_url . '/' . $clean_path;
            }
        }

        $data_row = [
            $row['id'], $row['tanggal_alokasi'], $row['username'], $row['user_nama'],
            $row['nama_branch'], $row['brand'], $row['project_name'], $row['type_name'],
            $row['qty_pcs'], $row['nama_micro_cluster'],
            $row['latitude'], $row['longitude'], $photo_full_url
        ];

        fputcsv($output, $data_row);
    }

    $stmt->close();
    fclose($output);
    exit();

} catch (Exception $e) {
    if (ob_get_level() > 0) ob_end_clean();
    $_SESSION['error_message'] = "Terjadi kesalahan saat ekspor CSV: " . $e->getMessage();
    header("location: ../admin_laporan_marpro_allocate.php");
    exit;
}
?>
