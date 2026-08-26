<?php
// process/admin_export_matpro_activities_process.php

// *** PERBAIKAN: Optimasi untuk Ekspor Data Besar ***
@ob_start(); // Mulai output buffering
set_time_limit(0); // Hapus batas waktu eksekusi
ini_set('memory_limit', '512M'); // Tingkatkan batas memori jika perlu (CSV biasanya tidak butuh banyak)

require_once '../config/database.php';
// Vendor autoload tidak diperlukan lagi untuk CSV
// require_once '../vendor/autoload.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    if (ob_get_level() > 0) ob_end_clean(); // Bersihkan buffer jika ada error
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

// Helper function for bind_param with dynamic arguments
if (!function_exists('ref_values')) {
    function ref_values(&$arr){
        $refs = [];
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
}


// --- Logika Filter (disesuaikan dengan form baru) ---
$where_clauses = [];
$param_types = "";
$param_values = [];

// Mengambil nilai filter dari POST request
$search_keyword = $_POST['keyword'] ?? '';
$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';
$brand_filter = $_POST['brand'] ?? '';
$branch_filter = $_POST['branch_id'] ?? '';
$mc_filter = $_POST['mc_id'] ?? '';
$project_filter = $_POST['project_name'] ?? '';
$type_filter = $_POST['type_name'] ?? '';
$export_type = $_POST['action'] ?? 'export_filtered';

// --- Default Date Range (MTD) if no dates provided ---
// Only apply MTD default if it's NOT an explicit 'export_all' action
if ($export_type !== 'export_all' && empty($start_date) && empty($end_date)) {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
}

// Membangun klausa WHERE secara dinamis (SAMA SEPERTI SEBELUMNYA)
if (!empty($search_keyword)) {
    $where_clauses[] = "(u.username LIKE ? OR ma.project_name LIKE ? OR ma.type_name LIKE ? OR ma.outlet_snapshot_name LIKE ? OR s.site_name LIKE ?)";
    $param_types .= "sssss";
    $keyword_like = "%" . $search_keyword . "%";
    array_push($param_values, $keyword_like, $keyword_like, $keyword_like, $keyword_like, $keyword_like);
}
if (!empty($start_date) && !empty($end_date)) {
    $where_clauses[] = "ma.activity_datetime BETWEEN ? AND ?";
    $param_types .= "ss";
    $param_values[] = $start_date . " 00:00:00";
    $param_values[] = $end_date . " 23:59:59";
} elseif (!empty($start_date)) {
    $where_clauses[] = "ma.activity_datetime >= ?";
    $param_types .= "s";
    $param_values[] = $start_date . " 00:00:00";
} elseif (!empty($end_date)) {
    $where_clauses[] = "ma.activity_datetime <= ?";
    $param_types .= "s";
    $param_values[] = $end_date . " 23:59:59";
}
if (!empty($brand_filter)) { $where_clauses[] = "b.brand = ?"; $param_types .= "s"; $param_values[] = $brand_filter; }
if (!empty($branch_filter)) { $where_clauses[] = "ma.branch_id = ?"; $param_types .= "i"; $param_values[] = (int)$branch_filter; }
if (!empty($mc_filter)) { $where_clauses[] = "ma.micro_cluster_id = ?"; $param_types .= "i"; $param_values[] = (int)$mc_filter; }
if (!empty($project_filter)) { $where_clauses[] = "ma.project_name = ?"; $param_types .= "s"; $param_values[] = $project_filter; }
if (!empty($type_filter)) { $where_clauses[] = "ma.type_name = ?"; $param_types .= "s"; $param_values[] = $type_filter; }

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

// Query untuk mengambil semua data aktivitas Matpro yang difilter (SAMA SEPERTI SEBELUMNYA)
$sql = "SELECT ma.unique_id, ma.activity_datetime, u.username, u.nama as user_nama,
               ma.project_name, b.brand, ma.type_name,
               ma.qty_used, IFNULL(ms.stock_quantity, 0) as sisa_stock, b.nama_branch, mc.nama_micro_cluster,
               s.site_name, s.site_id as site_code,
               COALESCE(o.Id_Outlet_Nama_Outlet, ma.outlet_snapshot_name, 'Outlet Telah Dihapus') as outlet_display_name,
               s.kecamatan, s.kabupaten, ma.location_latitude, ma.location_longitude,
               ma.photo_before_url, ma.photo_after_url
        FROM matpro_activities ma
        JOIN users u ON ma.user_id = u.id
        JOIN branches b ON ma.branch_id = b.id
        LEFT JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
        JOIN sites s ON ma.site_id = s.id
        LEFT JOIN outlets o ON ma.outlet_id = o.id
        LEFT JOIN matpro_stocks ms ON ma.user_id = ms.user_id 
            AND ma.project_name = ms.project_name 
            AND ma.type_name = ms.type_name 
            AND ma.branch_id = ms.branch_id 
            AND (ma.micro_cluster_id = ms.micro_cluster_id OR (ma.micro_cluster_id IS NULL AND ms.micro_cluster_id IS NULL))"
        . $where_sql . " ORDER BY ma.activity_datetime DESC";

$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
    error_log("Export Matpro CSV Error (prepare): " . $mysqli->error);
    if (ob_get_level() > 0) ob_end_clean();
    $_SESSION['error_message'] = "Gagal menyiapkan query ekspor CSV: " . $mysqli->error;
    header("location: ../admin_laporan_matpro.php");
    exit;
}

if (!empty($param_values)) {
     if (!call_user_func_array([$stmt, 'bind_param'], array_merge([$param_types], ref_values($param_values)))) {
        error_log("Export Matpro CSV Error (bind): " . $stmt->error);
        if (ob_get_level() > 0) ob_end_clean();
        $_SESSION['error_message'] = "Gagal mengikat parameter ekspor CSV: " . $stmt->error;
        header("location: ../admin_laporan_matpro.php");
        exit;
    }
}

// *** PERBAIKAN: Menggunakan Ekspor CSV ***
try {
    $stmt->execute();
    $result = $stmt->get_result();

    $filename = 'Laporan_Aktivitas_Matpro_' . date('Ymd_His') . '.csv';

    // Bersihkan buffer sebelum mengirim header
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Set header untuk file CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');

    // Buka output stream
    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new Exception("Gagal membuka output stream.");
    }

    // Tulis header kolom ke CSV
    $headers = [
        'ID Aktivitas', 'Waktu', 'Username', 'Nama User', 'Proyek', 'Brand', 'Jenis Matpro', 'QTY', 'Sisa Stock', 'Branch', 'Micro Cluster', 'Site Name', 'Site Code', 'Outlet', 'Kecamatan', 'Kabupaten', 'Latitude', 'Longitude', 'URL Foto Sebelum', 'URL Foto Sesudah'
    ];
    fputcsv($output, $headers);

    // Persiapan base URL untuk foto (SAMA SEPERTI SEBELUMNYA)
    $base_domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
    $script_path = dirname($_SERVER['SCRIPT_NAME']);
    $app_base_path = dirname($script_path);
    $app_base_path = ($app_base_path == '/' || $app_base_path == '\\') ? '' : $app_base_path;
    $project_base_url = rtrim($base_domain, '/') . $app_base_path;

    // Iterasi hasil query dan tulis ke CSV baris per baris
    while ($row = $result->fetch_assoc()) {
        // Proses URL foto (SAMA SEPERTI SEBELUMNYA)
        $db_path_before = $row['photo_before_url'] ?? '';
        $db_path_after = $row['photo_after_url'] ?? '';
        $photo_before_full_url = '';
        $photo_after_full_url = '';

        if (!empty($db_path_before)) {
            if (preg_match('/^https?:\/\//', $db_path_before)) {
                $photo_before_full_url = $db_path_before;
            } else {
                $clean_path_before = ltrim($db_path_before, '/');
                $photo_before_full_url = $project_base_url . '/' . $clean_path_before;
            }
        }

        if (!empty($db_path_after)) {
            if (preg_match('/^https?:\/\//', $db_path_after)) {
                $photo_after_full_url = $db_path_after;
            } else {
                $clean_path_after = ltrim($db_path_after, '/');
                $photo_after_full_url = $project_base_url . '/' . $clean_path_after;
            }
        }

        // Siapkan data baris untuk CSV
        $data_row = [
            $row['unique_id'], $row['activity_datetime'], $row['username'], $row['user_nama'],
            $row['project_name'], $row['brand'], $row['type_name'],
            $row['qty_used'], $row['sisa_stock'], $row['nama_branch'], $row['nama_micro_cluster'] ?? '-',
            $row['site_name'], $row['site_code'], $row['outlet_display_name'],
            $row['kecamatan'], $row['kabupaten'], $row['location_latitude'], $row['location_longitude'],
            $photo_before_full_url,
            $photo_after_full_url
        ];

        // Tulis baris ke CSV
        fputcsv($output, $data_row);
    }

    // Tutup statement dan output stream
    $stmt->close();
    fclose($output);
    exit(); // Penting untuk menghentikan eksekusi skrip setelah file dikirim

} catch (Exception $e) {
    error_log("General Export CSV Error: " . $e->getMessage());
    if (ob_get_level() > 0) ob_end_clean();
    $_SESSION['error_message'] = "Terjadi kesalahan saat ekspor CSV: " . $e->getMessage();
    header("location: ../admin_laporan_matpro.php");
    exit;
}
// *** AKHIR PERBAIKAN CSV ***
?>

