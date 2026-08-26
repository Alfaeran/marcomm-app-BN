<?php
require_once 'cors.php';
require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

function send_json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    send_json_error('Akses ditolak.', 403);
}

function compress_image($source, $destination, $quality) {
    $info = getimagesize($source);
    if ($info['mime'] == 'image/jpeg') $image = imagecreatefromjpeg($source);
    elseif ($info['mime'] == 'image/gif') $image = imagecreatefromgif($source);
    elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
    } else return false;

    if ($info['mime'] == 'image/png') {
        $quality = (int)(($quality / 100) * 9);
        return imagepng($image, $destination, $quality);
    } else {
        return imagejpeg($image, $destination, $quality);
    }
}

function ref_values($arr){
    $refs = array();
    foreach($arr as $key => $value) $refs[$key] = &$arr[$key];
    return $refs;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// GET FORMDATA OPTIONS (Microclusters, Sites, Categories)
if ($action === 'get_form_options' && $method === 'GET') {
    $user_id = $_SESSION['id'];
    $user_branch_id = $_SESSION['branch_id'] ?? null;
    
    // Branch Name
    $branch_name = "Tidak Ditemukan";
    if ($user_branch_id) {
        $stmt = $mysqli->prepare("SELECT nama_branch FROM branches WHERE id = ?");
        $stmt->bind_param("i", $user_branch_id);
        $stmt->execute();
        $branch_name = $stmt->get_result()->fetch_assoc()['nama_branch'] ?? $branch_name;
        $stmt->close();
    }
    
    // Categories
    $res = $mysqli->query("SELECT id, nama_kategori FROM event_categories ORDER BY nama_kategori");
    $categories = $res->fetch_all(MYSQLI_ASSOC);
    
    // Micro Clusters
    $stmt_check = $mysqli->prepare("SELECT COUNT(user_id) as total FROM user_micro_clusters WHERE user_id = ?");
    $stmt_check->bind_param("i", $user_id);
    $stmt_check->execute();
    $has_specific = $stmt_check->get_result()->fetch_assoc()['total'] > 0;
    $stmt_check->close();
    
    if ($has_specific) {
        $stmt = $mysqli->prepare("SELECT mc.id, mc.nama_micro_cluster FROM user_micro_clusters umc JOIN micro_clusters mc ON umc.micro_cluster_id = mc.id WHERE umc.user_id = ? ORDER BY mc.nama_micro_cluster");
        $stmt->bind_param("i", $user_id);
    } else {
        $stmt = $mysqli->prepare("SELECT id, nama_micro_cluster FROM micro_clusters WHERE branch_id = ? ORDER BY nama_micro_cluster");
        $stmt->bind_param("i", $user_branch_id);
    }
    $stmt->execute();
    $micro_clusters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode([
        'branch_name' => $branch_name,
        'categories' => $categories,
        'micro_clusters' => $micro_clusters
    ]);
    exit;
}

// GET SITES BY MICRO CLUSTER
if ($action === 'get_sites' && $method === 'GET') {
    $mc_id = (int)($_GET['micro_cluster_id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT id, site_id, site_name FROM sites WHERE micro_cluster_id = ? ORDER BY site_name");
    $stmt->bind_param("i", $mc_id);
    $stmt->execute();
    $sites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['data' => $sites]);
    exit;
}

// SUBMIT EVENT
if ($action === 'submit_event' && $method === 'POST') {
    $mysqli->begin_transaction();
    try {
        $data_event = [
            'user_id' => $_SESSION['id'],
            'event_name' => trim($_POST['event_name'] ?? ''),
            'site_id' => (int)($_POST['site_id'] ?? 0),
            'kategori_event_id' => (int)($_POST['kategori_event_id'] ?? 0),
            'location_latitude' => (float)($_POST['latitude'] ?? 0.0),
            'location_longitude' => (float)($_POST['longitude'] ?? 0.0),
            'foto_event_url' => '',
            'sp_0k' => (int)($_POST['sp_0k'] ?? 0),
            'sp_3gb' => (int)($_POST['sp_3gb'] ?? 0),
            'sp_5gb' => (int)($_POST['sp_5gb'] ?? 0),
            'sp_7gb' => (int)($_POST['sp_7gb'] ?? 0),
            'sp_100gb' => (int)($_POST['sp_100gb'] ?? 0),
            'fwa' => (int)($_POST['fwa'] ?? 0),
            'fwa_5g' => (int)($_POST['fwa_5g'] ?? 0),
            'hit_haji_umroh' => (int)($_POST['hit_haji_umroh'] ?? 0),
            'reload' => (int)($_POST['reload'] ?? 0),
            'mobo_paket' => (int)($_POST['mobo_paket'] ?? 0),
            'cost' => (int)($_POST['cost'] ?? 0),
            'alasan' => trim($_POST['alasan'] ?? ''),
            'provider_digunakan' => $_POST['provider_digunakan'] ?? '',
            'provider_terbaik' => $_POST['provider_terbaik'] ?? '',
            'kenal_im3' => $_POST['kenal_im3'] ?? 'Tidak',
            'sudah_beli_im3' => $_POST['sudah_beli_im3'] ?? 'Tidak',
            'lokasi_beli' => trim($_POST['lokasi_beli'] ?? ''),
            'tertarik_beli_im3' => $_POST['tertarik_beli_im3'] ?? 'Tidak'
        ];

        // Upload Foto
        if (isset($_FILES['foto_event']) && $_FILES['foto_event']['error'] == 0) {
            $target_dir = "../uploads/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES["foto_event"]["name"], PATHINFO_EXTENSION));
            $filename = uniqid('event_', true) . '.' . $ext;
            $target_file = $target_dir . $filename;
            
            if ($_FILES['foto_event']['size'] > 7 * 1024 * 1024) throw new Exception("Ukuran foto > 7MB");
            if (compress_image($_FILES['foto_event']['tmp_name'], $target_file, 75)) {
                $data_event['foto_event_url'] = '../marcomm_bn/uploads/' . $filename;
            } else throw new Exception("Gagal mengompres foto");
        } else throw new Exception("Foto wajib diunggah");

        // Upload MSISDN
        $new_unique_msisdns = [];
        $found_duplicates_with_details = [];
        if (isset($_FILES['msisdn_file']) && $_FILES['msisdn_file']['error'] == 0) {
            $pass = $_POST['excel_password'] ?? null;
            $tmp = $_FILES['msisdn_file']['tmp_name'];
            $reader = IOFactory::createReaderForFile($tmp);
            if (!empty($pass)) $reader->setPassword($pass);
            $spreadsheet = $reader->load($tmp);
            $msisdns_from_file = [];
            
            foreach ($spreadsheet->getActiveSheet()->getRowIterator(1) as $row) {
                $val = trim($spreadsheet->getActiveSheet()->getCell('A' . $row->getRowIndex())->getValue() ?? '');
                $type = strtolower(trim($spreadsheet->getActiveSheet()->getCell('B' . $row->getRowIndex())->getValue() ?? 'new'));
                if (!in_array($type, ['existing', 'new'])) $type = 'new';
                
                if (substr($val, 0, 2) === '08') $val = '62' . substr($val, 1);
                elseif (substr($val, 0, 1) === '8') $val = '62' . $val;
                
                if (is_numeric($val) && !empty($val)) $msisdns_from_file[] = ['msisdn' => $val, 'type' => $type];
            }

            $unique_msisdns_only = array_unique(array_column($msisdns_from_file, 'msisdn'));
            $final_msisdn_data = [];
            foreach($msisdns_from_file as $item) $final_msisdn_data[$item['msisdn']] = $item['type'];

            if (!empty($unique_msisdns_only)) {
                $placeholders = implode(',', array_fill(0, count($unique_msisdns_only), '?'));
                $stmt_check = $mysqli->prepare("SELECT msisdn, submission_id FROM msisdn_data WHERE msisdn IN ($placeholders)");
                $stmt_check->bind_param(str_repeat('s', count($unique_msisdns_only)), ...$unique_msisdns_only);
                $stmt_check->execute();
                $existing_in_db = $stmt_check->get_result()->fetch_all(MYSQLI_ASSOC);
                $existing_msisdns_map = array_column($existing_in_db, 'submission_id', 'msisdn');
                
                $new_unique_keys = array_diff($unique_msisdns_only, array_keys($existing_msisdns_map));
                foreach($new_unique_keys as $key) $new_unique_msisdns[] = ['msisdn' => $key, 'type' => $final_msisdn_data[$key]];
                
                $found_duplicates_with_details = array_intersect_key($existing_msisdns_map, array_flip($unique_msisdns_only));
            }
        } else throw new Exception("File MSISDN wajib");

        // Kalkulasi
        $data_event['jumlah_qsc'] = $data_event['sp_0k'] + $data_event['sp_3gb'] + $data_event['sp_5gb'] + $data_event['sp_7gb'] + $data_event['sp_100gb'];
        $data_event['benefit_sp'] = ($data_event['sp_0k'] * 10000) + ($data_event['sp_3gb'] * 27000) + ($data_event['sp_5gb'] * 35000) + ($data_event['sp_7gb'] * 39000);
        $data_event['benefit_fwa'] = ($data_event['fwa'] * 150000) + ($data_event['fwa_5g'] * 750000); 
        $data_event['benefit_total'] = $data_event['benefit_sp'] + $data_event['benefit_fwa'] + $data_event['mobo_paket'];
        $data_event['ratio_cost_benefit'] = ($data_event['benefit_total'] > 0) ? ($data_event['cost'] / $data_event['benefit_total']) * 100 : 0;

        // DB Columns
        $stmt_cols = $mysqli->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_submissions' ORDER BY ORDINAL_POSITION");
        $stmt_cols->execute();
        $db_columns_info = [];
        while ($row = $stmt_cols->get_result()->fetch_assoc()) $db_columns_info[$row['COLUMN_NAME']] = $row['DATA_TYPE'];

        $columns = []; $placeholders = []; $bind_values = []; $types_string = "";
        foreach ($data_event as $key => $value) {
            if (array_key_exists($key, $db_columns_info)) {
                $columns[] = "`$key`"; $placeholders[] = "?"; $bind_values[] = $value;
                $t = $db_columns_info[$key];
                if (in_array($t, ['int','bigint','tinyint','mediumint'])) $types_string .= 'i';
                elseif (in_array($t, ['double','float','decimal'])) $types_string .= 'd';
                else $types_string .= 's';
            }
        }

        $sql = "INSERT INTO event_submissions (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")";
        $stmt_event = $mysqli->prepare($sql);
        $bind_params = array_merge([$types_string], $bind_values);
        call_user_func_array([$stmt_event, 'bind_param'], ref_values($bind_params));
        if (!$stmt_event->execute()) throw new Exception("Gagal menyimpan event: " . $stmt_event->error);
        $submission_id = $mysqli->insert_id;

        if (!empty($new_unique_msisdns)) {
            $stmt_msisdn = $mysqli->prepare("INSERT INTO msisdn_data (submission_id, msisdn, type) VALUES (?, ?, ?)");
            foreach ($new_unique_msisdns as $item) {
                $stmt_msisdn->bind_param("iss", $submission_id, $item['msisdn'], $item['type']);
                $stmt_msisdn->execute();
            }
        }
        
        if (!empty($found_duplicates_with_details)) {
            $stmt_log = $mysqli->prepare("INSERT INTO duplicate_msisdn_log (new_submission_id, duplicate_msisdn, original_submission_id) VALUES (?, ?, ?)");
            foreach ($found_duplicates_with_details as $msisdn => $original_submission_id) {
                $stmt_log->bind_param("isi", $submission_id, $msisdn, $original_submission_id);
                $stmt_log->execute();
            }
        }
        
        $mysqli->commit();
        echo json_encode([
            'success' => true, 
            'message' => "Data berhasil disimpan. " . count($new_unique_msisdns) . " MSISDN baru ditambahkan.",
            'duplicates' => count($found_duplicates_with_details) > 0 ? array_keys($found_duplicates_with_details) : []
        ]);

    } catch (Exception $e) {
        $mysqli->rollback();
        send_json_error($e->getMessage());
    }
    exit;
}

send_json_error('Aksi tidak valid.');
