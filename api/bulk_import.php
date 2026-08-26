<?php
require_once 'cors.php';
require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

function send_json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    send_json_error('Akses ditolak.', 403);
}

function deleteDir($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDir($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_FILES['archive_file'])) {
    send_json_error('Metode tidak valid.');
}

$user_id = $_SESSION['id'];
$temp_folder = null;
$errors = [];
$import_count = 0;
$msisdn_stats = ['added' => 0, 'duplicated' => 0, 'failed' => 0];
$excel_password = $_POST['excel_password'] ?? null;

$REVENUE = [
    'sp_0k' => 10000, 'sp_3gb' => 27000, 'sp_5gb' => 35000, 'sp_7gb' => 39000,
    'sp_100gb' => 0, 'fwa' => 150000, 'hit_haji_umroh' => 0,
    'fwa_5g' => 750000, 'reload' => 0, 'mobo_paket' => 1
];

try {
    $zip_file = $_FILES['archive_file']['tmp_name'];
    $file_ext = strtolower(pathinfo($_FILES['archive_file']['name'], PATHINFO_EXTENSION));
    if ($file_ext !== 'zip') throw new Exception("Hanya file .ZIP yang diterima");
    
    $temp_folder = '../temp_import/' . uniqid('imp_', true);
    if (!mkdir($temp_folder, 0777, true)) throw new Exception("Gagal buat folder temp");
    
    $zip = new ZipArchive();
    if ($zip->open($zip_file) !== TRUE) throw new Exception("Gagal buka ZIP");
    if (!$zip->extractTo($temp_folder)) {
        $zip->close();
        throw new Exception("Gagal extract ZIP");
    }
    $zip->close();
    
    $excel_path = $temp_folder . '/data_event.xlsx';
    $sub_dir = null;
    if (!file_exists($excel_path)) {
        $items = array_diff(scandir($temp_folder), ['.', '..']);
        if (count($items) === 1 && is_dir($temp_folder . '/' . reset($items))) {
            $sub_dir = $temp_folder . '/' . reset($items);
            if (!file_exists($sub_dir . '/data_event.xlsx')) throw new Exception("File data_event.xlsx tidak ditemukan");
            $excel_path = $sub_dir . '/data_event.xlsx';
        } else throw new Exception("File data_event.xlsx tidak ditemukan");
    }
    
    $reader = IOFactory::createReaderForFile($excel_path);
    $reader->setReadDataOnly(true);
    if (!empty($excel_password)) $reader->setPassword($excel_password);
    
    $spreadsheet = $reader->load($excel_path);
    $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
    
    $header_row = $rows[1] ?? [];
    if (!in_array('Nama Event', array_values($header_row))) throw new Exception("Kolom 'Nama Event' tidak ditemukan di Excel");
    
    $sites = []; $categories = [];
    $res = $mysqli->query("SELECT id, site_id FROM sites");
    while ($r = $res->fetch_assoc()) $sites[$r['site_id']] = $r['id'];
    
    $res = $mysqli->query("SELECT id, nama_kategori FROM event_categories");
    while ($r = $res->fetch_assoc()) $categories[strtolower($r['nama_kategori'])] = $r['id'];
    
    $mysqli->begin_transaction();
    
    foreach ($rows as $row_idx => $row) {
        if ($row_idx === 1) continue;
        if (empty(array_filter($row))) continue;
        
        try {
            $data = [
                'nama_event' => trim($row['A'] ?? ''), 'kategori_event' => trim($row['B'] ?? ''),
                'site_id_code' => trim($row['C'] ?? ''), 'nama_branch' => trim($row['D'] ?? ''),
                'nama_micro_cluster' => trim($row['E'] ?? ''), 'latitude' => trim($row['F'] ?? ''),
                'longitude' => trim($row['G'] ?? ''), 'sp_0k' => trim($row['H'] ?? ''),
                'sp_3gb' => trim($row['I'] ?? ''), 'sp_5gb' => trim($row['J'] ?? ''),
                'sp_7gb' => trim($row['K'] ?? ''), 'sp_100gb' => trim($row['L'] ?? ''),
                'fwa' => trim($row['M'] ?? ''), 'fwa_5g' => trim($row['N'] ?? ''),
                'hit_haji_umroh' => trim($row['O'] ?? ''), 'reload' => trim($row['P'] ?? ''),
                'mobo_paket' => trim($row['Q'] ?? ''), 'cost' => trim($row['R'] ?? ''),
                'alasan' => trim($row['S'] ?? ''), 'foto' => trim($row['T'] ?? ''),
                'msisdn' => trim($row['U'] ?? '')
            ];
            
            if (empty($data['nama_event'])) throw new Exception("Nama Event kosong");
            if (empty($data['site_id_code'])) throw new Exception("Site ID wajib diisi");
            if (empty($data['latitude']) || empty($data['longitude'])) throw new Exception("Lat/Lon wajib");
            if (empty($data['cost']) || (float)$data['cost'] <= 0) throw new Exception("Cost wajib > 0");
            
            $site_id = $sites[$data['site_id_code']] ?? null;
            $cat_id = $categories[strtolower($data['kategori_event'])] ?? null;
            
            $sp_0k = (int)$data['sp_0k']; $sp_3gb = (int)$data['sp_3gb']; $sp_5gb = (int)$data['sp_5gb'];
            $sp_7gb = (int)$data['sp_7gb']; $sp_100gb = (int)$data['sp_100gb']; $fwa = (int)$data['fwa'];
            $fwa_5g = (int)$data['fwa_5g']; $hit = (int)$data['hit_haji_umroh'];
            $reload = (int)$data['reload']; $mobo = (int)$data['mobo_paket'];
            $lat = (float)$data['latitude']; $lng = (float)$data['longitude']; $cost = (float)$data['cost'];
            
            $jumlah_qsc = $sp_0k + $sp_3gb + $sp_5gb + $sp_7gb + $sp_100gb;
            $benefit_sp = ($sp_0k * $REVENUE['sp_0k']) + ($sp_3gb * $REVENUE['sp_3gb']) + ($sp_5gb * $REVENUE['sp_5gb']) + ($sp_7gb * $REVENUE['sp_7gb']);
            $benefit_fwa = ($fwa * $REVENUE['fwa']) + ($fwa_5g * $REVENUE['fwa_5g']);
            $benefit_total = $benefit_sp + $benefit_fwa + $mobo;
            $ratio = ($benefit_total > 0) ? ($cost / $benefit_total) * 100 : 0.0;
            
            $foto_url = '';
            if (!empty($data['foto'])) {
                $foto_path = $temp_folder . '/' . $data['foto'];
                if (!file_exists($foto_path) && $sub_dir) $foto_path = $sub_dir . '/' . $data['foto'];
                
                if (file_exists($foto_path)) {
                    $ext = pathinfo($data['foto'], PATHINFO_EXTENSION);
                    $new_name = 'photo_' . time() . '_' . uniqid() . '.' . $ext;
                    $dest = '../uploads/' . $new_name;
                    @mkdir('../uploads', 0777, true);
                    if (copy($foto_path, $dest)) $foto_url = 'uploads/' . $new_name;
                }
            }
            
            $query = "INSERT INTO event_submissions (
                user_id, site_id, kategori_event_id, location_latitude, location_longitude, event_name,
                sp_0k, sp_3gb, sp_5gb, sp_7gb, sp_100gb, fwa, fwa_5g, hit_haji_umroh,
                jumlah_qsc, reload, mobo_paket, cost, benefit_sp, benefit_fwa,
                benefit_total, ratio_cost_benefit, alasan, foto_event_url, site_snapshot_name
            ) VALUES (
                $user_id, " . ($site_id ? $site_id : 'NULL') . ", " . ($cat_id ? $cat_id : 'NULL') . ", $lat, $lng, 
                '" . $mysqli->real_escape_string($data['nama_event']) . "',
                $sp_0k, $sp_3gb, $sp_5gb, $sp_7gb, $sp_100gb, $fwa, $fwa_5g, $hit,
                $jumlah_qsc, $reload, $mobo,
                $cost, $benefit_sp, $benefit_fwa, $benefit_total, $ratio,
                '" . $mysqli->real_escape_string($data['alasan'] ?? '') . "',
                '" . $mysqli->real_escape_string($foto_url) . "',
                '" . $mysqli->real_escape_string($data['nama_branch'] ?? '') . "'
            )";
            
            if (!$mysqli->query($query)) throw new Exception("Query error: " . $mysqli->error);
            $submit_id = $mysqli->insert_id;
            
            if (!empty($data['msisdn'])) {
                $msisdn_path = $temp_folder . '/' . $data['msisdn'];
                if (!file_exists($msisdn_path) && $sub_dir) $msisdn_path = $sub_dir . '/' . $data['msisdn'];
                
                if (file_exists($msisdn_path)) {
                    try {
                        $ms_reader = IOFactory::createReaderForFile($msisdn_path);
                        if (!empty($excel_password)) $ms_reader->setPassword($excel_password);
                        $ms_spreadsheet = $ms_reader->load($msisdn_path);
                        $ms_rows = $ms_spreadsheet->getActiveSheet()->toArray(null, true, true, true);
                        
                        foreach (array_slice($ms_rows, 1) as $ms_row) {
                            $msisdn = preg_replace('/\D/', '', trim($ms_row['A'] ?? ''));
                            if (empty($msisdn)) continue;
                            if (strlen($msisdn) === 10 && $msisdn[0] === '8') $msisdn = '62' . $msisdn;
                            elseif (strlen($msisdn) === 11 && substr($msisdn, 0, 2) === '08') $msisdn = '62' . substr($msisdn, 1);
                            
                            $type = strtolower(trim($ms_row['B'] ?? 'new'));
                            if (!in_array($type, ['existing', 'new'])) $type = 'new';
                            
                            if (strlen($msisdn) >= 11) {
                                $res_c = $mysqli->query("SELECT submission_id FROM msisdn_data WHERE msisdn = '$msisdn' LIMIT 1");
                                if ($res_c->num_rows > 0) {
                                    $msisdn_stats['duplicated']++;
                                    $orig = $res_c->fetch_assoc()['submission_id'];
                                    $mysqli->query("INSERT INTO duplicate_msisdn_log (new_submission_id, duplicate_msisdn, original_submission_id) VALUES ($submit_id, '$msisdn', $orig)");
                                } else {
                                    if ($mysqli->query("INSERT INTO msisdn_data (submission_id, msisdn, type) VALUES ($submit_id, '$msisdn', '$type')")) $msisdn_stats['added']++;
                                    else $msisdn_stats['failed']++;
                                }
                            }
                        }
                    } catch (Exception $e) {}
                }
            }
            $import_count++;
        } catch (Exception $e) {
            $errors[] = "Baris $row_idx: " . $e->getMessage();
        }
    }
    
    if (empty($errors)) {
        $mysqli->commit();
        echo json_encode([
            'success' => true, 
            'message' => "Import berhasil! Event: $import_count. MSISDN (Baru: {$msisdn_stats['added']}, Duplikat: {$msisdn_stats['duplicated']}, Gagal: {$msisdn_stats['failed']})"
        ]);
    } else {
        $mysqli->rollback();
        echo json_encode(['error' => "Import gagal! Terdapat " . count($errors) . " error:\n" . implode("\n", array_slice($errors, 0, 5)) . (count($errors)>5 ? "\n...dst" : "")]);
    }
    
} catch (Exception $e) {
    if ($mysqli->in_transaction) $mysqli->rollback();
    send_json_error($e->getMessage());
} finally {
    if ($temp_folder && file_exists($temp_folder)) deleteDir($temp_folder);
}
