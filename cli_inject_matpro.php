<?php
// cli_inject_matpro.php
// Script untuk inject data matpro langsung dari folder lokal ke database via Command Line.
// Cara pakai: php cli_inject_matpro.php "D:\Download\data_aktivitas_matpro" 1

if (php_sapi_name() !== 'cli') {
    die("Script ini hanya bisa dijalankan melalui Command Line (CLI).\n");
}

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);

require_once 'config/database.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($argc < 3) {
    die("Penggunaan: php cli_inject_matpro.php \"Path\Ke\Folder\" [USER_ID]\nContoh: php cli_inject_matpro.php \"D:\Download\data_aktivitas_matpro\" 1\n");
}

$source_dir = rtrim($argv[1], '\\/');
$user_id_session = (int)$argv[2];

if (!is_dir($source_dir)) {
    die("Error: Folder '$source_dir' tidak ditemukan.\n");
}

$excel_file_path = $source_dir . '/data_aktivitas_matpro.xlsx';
if (!file_exists($excel_file_path)) {
    die("Error: File 'data_aktivitas_matpro.xlsx' tidak ditemukan di dalam folder tersebut.\n");
}

// Cek User ID
$stmt = $mysqli->prepare("SELECT id, username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id_session);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    die("Error: User dengan ID $user_id_session tidak ditemukan di database.\n");
}
$user = $res->fetch_assoc();
echo "Memulai inject atas nama User: " . $user['username'] . " (ID: $user_id_session)\n";

// Fungsi compress (sama dengan di user_import_matpro.php)
function compress_image($source, $destination, $quality) {
    $info = @getimagesize($source);
    if ($info === false) return false;

    $image = null;
    if ($info['mime'] == 'image/jpeg') {
        $image = @imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/gif') {
        $image = @imagecreatefromgif($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = @imagecreatefrompng($source);
        if ($image) {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }
    } else {
        return false;
    }

    if (!$image) return false;

    if ($info['mime'] == 'image/png') {
        $quality = (int)(($quality / 100) * 9);
        $res = imagepng($image, $destination, $quality);
    } else {
        $res = imagejpeg($image, $destination, $quality);
    }
    imagedestroy($image);
    return $res;
}

$upload_dir_matpro_activities = 'uploads/matpro_activities/';
if (!is_dir($upload_dir_matpro_activities)) {
    mkdir($upload_dir_matpro_activities, 0755, true);
}

try {
    echo "Membaca file Excel...\n";
    $reader = IOFactory::createReaderForFile($excel_file_path);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($excel_file_path);
    $sheet = $spreadsheet->getActiveSheet();
    
    // Auto-detect column format (10 columns or 11 columns)
    $colDHeader = strtolower(trim($sheet->getCell('D1')->getValue()));
    $hasMicroCluster = (strpos($colDHeader, 'micro cluster') !== false);
    
    if ($hasMicroCluster) {
        echo "Format Excel: 11 Kolom (Ada Micro Cluster)\n";
    } else {
        echo "Format Excel: 10 Kolom (Tanpa Micro Cluster, otomatis disesuaikan)\n";
    }

    $mysqli->begin_transaction();
    
    $processed = 0;
    $errors = [];

    foreach ($sheet->getRowIterator(2) as $row) {
        $rowIndex = $row->getRowIndex();
        try {
            $project_name_excel = trim($sheet->getCell('A' . $rowIndex)->getValue());
            $type_name_excel = trim($sheet->getCell('B' . $rowIndex)->getValue());
            $branch_name_excel = trim($sheet->getCell('C' . $rowIndex)->getValue());
            
            if ($hasMicroCluster) {
                $micro_cluster_name_excel = trim($sheet->getCell('D' . $rowIndex)->getValue());
                $site_id_code_excel = trim($sheet->getCell('E' . $rowIndex)->getValue());
                $outlet_id_code_excel = trim($sheet->getCell('F' . $rowIndex)->getValue());
                $quantity_excel = (int)trim($sheet->getCell('G' . $rowIndex)->getValue());
                $latitude_excel = (float)trim($sheet->getCell('H' . $rowIndex)->getValue());
                $longitude_excel = (float)trim($sheet->getCell('I' . $rowIndex)->getValue());
                $photo_before_filename = trim($sheet->getCell('J' . $rowIndex)->getValue());
                $photo_after_filename = trim($sheet->getCell('K' . $rowIndex)->getValue());
            } else {
                $micro_cluster_name_excel = ''; // Kosongkan
                $site_id_code_excel = trim($sheet->getCell('D' . $rowIndex)->getValue());
                $outlet_id_code_excel = trim($sheet->getCell('E' . $rowIndex)->getValue());
                $quantity_excel = (int)trim($sheet->getCell('F' . $rowIndex)->getValue());
                $latitude_excel = (float)trim($sheet->getCell('G' . $rowIndex)->getValue());
                $longitude_excel = (float)trim($sheet->getCell('H' . $rowIndex)->getValue());
                $photo_before_filename = trim($sheet->getCell('I' . $rowIndex)->getValue());
                $photo_after_filename = trim($sheet->getCell('J' . $rowIndex)->getValue());
            }

            if (empty($project_name_excel) && empty($type_name_excel) && empty($site_id_code_excel)) continue;

            if (empty($project_name_excel) || empty($type_name_excel) || empty($branch_name_excel) || empty($site_id_code_excel) || empty($outlet_id_code_excel) || empty($quantity_excel) || empty($latitude_excel) || empty($longitude_excel) || empty($photo_before_filename) || empty($photo_after_filename)) {
                throw new Exception("Data tidak lengkap.");
            }

            $project_id_db = null;
            $type_id_db = null;

            // Lookup Branch
            $stmt_branch = $mysqli->prepare("SELECT id FROM branches WHERE nama_branch = ?");
            $stmt_branch->bind_param("s", $branch_name_excel);
            $stmt_branch->execute();
            $res_branch = $stmt_branch->get_result();
            if ($res_branch->num_rows === 0) throw new Exception("Branch '$branch_name_excel' tidak ditemukan.");
            $branch_id_db = $res_branch->fetch_assoc()['id'];

            // Lookup Micro Cluster
            $micro_cluster_id_db = null;
            if (!empty($micro_cluster_name_excel)) {
                $stmt_mc = $mysqli->prepare("SELECT id FROM micro_clusters WHERE nama_micro_cluster = ? AND branch_id = ?");
                $stmt_mc->bind_param("si", $micro_cluster_name_excel, $branch_id_db);
                $stmt_mc->execute();
                $res_mc = $stmt_mc->get_result();
                if ($res_mc->num_rows === 0) throw new Exception("Micro Cluster '$micro_cluster_name_excel' tidak ditemukan di branch ini.");
                $micro_cluster_id_db = $res_mc->fetch_assoc()['id'];
            }

            // Lookup Site
            $stmt_site = $mysqli->prepare("SELECT id FROM sites WHERE site_id = ?");
            $stmt_site->bind_param("s", $site_id_code_excel);
            $stmt_site->execute();
            $res_site = $stmt_site->get_result();
            if ($res_site->num_rows === 0) throw new Exception("Site ID '$site_id_code_excel' tidak ditemukan.");
            $site_id_db = $res_site->fetch_assoc()['id'];

            // Lookup Outlet
            $stmt_outlet = $mysqli->prepare("SELECT id, nama_outlet FROM outlets WHERE id_outlet = ? AND site_id = ?");
            $stmt_outlet->bind_param("si", $outlet_id_code_excel, $site_id_db);
            $stmt_outlet->execute();
            $res_outlet = $stmt_outlet->get_result();
            if ($res_outlet->num_rows === 0) throw new Exception("Outlet '$outlet_id_code_excel' tidak ditemukan di site ini.");
            $outlet_data = $res_outlet->fetch_assoc();
            $outlet_id_db = $outlet_data['id'];
            $outlet_snapshot_name = $outlet_data['nama_outlet'];

            // Photos
            $photo_before_path = $source_dir . '/' . $photo_before_filename;
            $photo_after_path = $source_dir . '/' . $photo_after_filename;
            
            if (!file_exists($photo_before_path)) throw new Exception("Foto sebelum '$photo_before_filename' tidak ditemukan di folder.");
            if (!file_exists($photo_after_path)) throw new Exception("Foto sesudah '$photo_after_filename' tidak ditemukan di folder.");

            $pb_name = $upload_dir_matpro_activities . uniqid('matpro_b_') . '.' . pathinfo($photo_before_filename, PATHINFO_EXTENSION);
            $pa_name = $upload_dir_matpro_activities . uniqid('matpro_a_') . '.' . pathinfo($photo_after_filename, PATHINFO_EXTENSION);

            if (!compress_image($photo_before_path, $pb_name, 75)) throw new Exception("Gagal memproses/kompres foto sebelum.");
            if (!compress_image($photo_after_path, $pa_name, 75)) throw new Exception("Gagal memproses/kompres foto sesudah.");

            // Stock Check
            $sql_stock = "SELECT id, stock_quantity FROM matpro_stocks 
                         WHERE project_name = ? AND type_name = ? AND user_id = ? 
                         AND is_active = 1 LIMIT 1";

            $stmt_stock = $mysqli->prepare($sql_stock);
            $stmt_stock->bind_param("ssi", $project_name_excel, $type_name_excel, $user_id_session);
            $stmt_stock->execute();
            $stock_res = $stmt_stock->get_result();
            if ($stock_res->num_rows === 0) throw new Exception("Stok tidak ditemukan untuk $project_name_excel - $type_name_excel.");
            $stock = $stock_res->fetch_assoc();
            if ($stock['stock_quantity'] < $quantity_excel) throw new Exception("Stok tidak cukup (Sisa: {$stock['stock_quantity']}).");

            $new_qty = $stock['stock_quantity'] - $quantity_excel;
            $is_active_new = ($new_qty <= 0) ? 0 : 1;
            
            $stmt_up_stock = $mysqli->prepare("UPDATE matpro_stocks SET stock_quantity = ?, is_active = ?, last_updated_by = ?, last_updated_at = NOW() WHERE id = ?");
            $stmt_up_stock->bind_param("iiii", $new_qty, $is_active_new, $user_id_session, $stock['id']);
            $stmt_up_stock->execute();

            // Insert Activity
            $unique_id = 'MATPRO-' . strtoupper(uniqid());
            $sql_ins = "INSERT INTO matpro_activities 
                        (unique_id, user_id, activity_datetime, location_latitude, location_longitude, 
                         branch_id, micro_cluster_id, site_id, outlet_id, outlet_snapshot_name, 
                         project_name, type_name, project_id, type_id, qty_used, photo_before_url, photo_after_url) 
                        VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_ins = $mysqli->prepare($sql_ins);
            $stmt_ins->bind_param("siddiiiisssiiiss", 
                $unique_id, $user_id_session, $latitude_excel, $longitude_excel, 
                $branch_id_db, $micro_cluster_id_db, $site_id_db, $outlet_id_db, $outlet_snapshot_name,
                $project_name_excel, $type_name_excel, $project_id_db, $type_id_db, 
                $quantity_excel, $pb_name, $pa_name);
            $stmt_ins->execute();

            echo "Baris $rowIndex: OK\n";
            $processed++;

        } catch (Exception $e) {
            $err = "Baris $rowIndex: " . $e->getMessage();
            echo "ERROR $err\n";
            $errors[] = $err;
        }
    }

    if (!empty($errors)) {
        $mysqli->rollback();
        echo "\n[GAGAL] Proses dibatalkan karena ada " . count($errors) . " error.\n";
        echo "Perbaiki data di Excel lalu jalankan ulang script ini.\n";
    } else {
        $mysqli->commit();
        echo "\n[SUKSES] $processed data berhasil di-inject ke database!\n";
    }

} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}
