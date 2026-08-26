<?php
// process/bulk_import_event_process.php - VERSI SIMPLE & CLEAN

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(900);

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (session_status() === PHP_SESSION_NONE) session_start();

function deleteDir($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDir($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// Validasi session & file
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SERVER["REQUEST_METHOD"] !== "POST") {
    $_SESSION['import_status'] = ['type' => 'error', 'message' => 'Akses ditolak'];
    header("location: ../bulk_import_event.php");
    exit;
}

// Enforce Restrictions
$restriction = is_bulk_import_allowed($mysqli);
if (!$restriction['allowed']) {
    $_SESSION['import_status'] = ['type' => 'error', 'message' => $restriction['message']];
    header("location: ../bulk_import_event.php");
    exit;
}

if (!isset($_FILES['archive_file']) || $_FILES['archive_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['import_status'] = ['type' => 'error', 'message' => 'Gagal upload file'];
    header("location: ../bulk_import_event.php");
    exit;
}

$user_id = $_SESSION['id'];
$temp_folder = null;
$errors = [];
$warnings = [];
$import_count = 0;
$msisdn_stats = ['added' => 0, 'duplicated' => 0, 'failed' => 0];
$excel_password = $_POST['excel_password'] ?? null;

// Revenue mapping
$REVENUE = [
    'sp_0k' => 10000, 'sp_3gb' => 27000, 'sp_5gb' => 35000, 'sp_7gb' => 39000,
    'sp_100gb' => 0, 'fwa' => 150000, 'hit_haji_umroh' => 0,
    'fwa_5g' => 750000, 'reload' => 0, 'mobo_paket' => 1
];

try {
    // Extract ZIP
    $zip_file = $_FILES['archive_file']['tmp_name'];
    $file_ext = strtolower(pathinfo($_FILES['archive_file']['name'], PATHINFO_EXTENSION));
    
    // FIXED: Add MIME type validation
    if ($file_ext !== 'zip') throw new Exception("Hanya file .ZIP yang diterima");
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $zip_file);
    finfo_close($finfo);
    
    $allowed_mime_types = ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'];
    if (!in_array($mime_type, $allowed_mime_types)) {
        throw new Exception("File bukan ZIP yang valid. MIME type: " . $mime_type);
    }
    
    $temp_folder = '../temp_import/' . uniqid('imp_', true);
    if (!mkdir($temp_folder, 0777, true)) throw new Exception("Gagal buat folder temp");
    
    $zip = new ZipArchive();
    if ($zip->open($zip_file) !== TRUE) throw new Exception("Gagal buka ZIP");
    if (!$zip->extractTo($temp_folder)) {
        $zip->close();
        throw new Exception("Gagal extract ZIP");
    }
    $zip->close();
    
    // Cari data_event.xlsx
    $excel_path = $temp_folder . '/data_event.xlsx';
    $sub_dir = null;
    
    if (!file_exists($excel_path)) {
        $items = array_diff(scandir($temp_folder), ['.', '..']);
        if (count($items) === 1 && is_dir($temp_folder . '/' . reset($items))) {
            $sub_dir = $temp_folder . '/' . reset($items);
            if (!file_exists($sub_dir . '/data_event.xlsx')) {
                throw new Exception("File data_event.xlsx tidak ditemukan");
            }
            $excel_path = $sub_dir . '/data_event.xlsx';
        } else {
            throw new Exception("File data_event.xlsx tidak ditemukan");
        }
    }
    
    // Load Excel with Password support
    $reader = IOFactory::createReaderForFile($excel_path);
    $reader->setReadDataOnly(true); // OPTIMIZATION: Only read data, ignore styles
    if (!empty($excel_password)) {
        $reader->setPassword($excel_password);
    }
    $spreadsheet = $reader->load($excel_path);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);
    
    // Header adalah baris 1 dengan key huruf (A, B, C, ...)
    $header_row = $rows[1] ?? [];  // ['A' => 'Nama Event', 'B' => 'Kategori Event', ...]
    
    // Map huruf kolom (A, B, C, ...) ke nama field
    $col_letters = array_keys($header_row);  // ['A', 'B', 'C', ...]
    $col_names = array_values($header_row);   // ['Nama Event', 'Kategori Event', ...]
    
    // Buat mapping: nama kolom Excel → huruf kolom
    $header_map = [
        'Nama Event' => 'A', 'Kategori Event' => 'B',
        'Site ID' => 'C', 'Nama Branch' => 'D',
        'Nama Micro Cluster' => 'E', 'Latitude' => 'F',
        'Longitude' => 'G', 'SP 0K' => 'H', 'SP 3GB' => 'I',
        'SP 5GB' => 'J', 'SP 7GB' => 'K', 'SP 100GB' => 'L',
        'FWA' => 'M', 'FWA 5G' => 'N', 'HIT Haji/Umroh' => 'O',
        'Reload' => 'P', 'Mobo/Paket' => 'Q', 'Cost' => 'R',
        'Alasan / Feedback' => 'S', 'Nama File Foto' => 'T',
        'Nama File MSISDN' => 'U'
    ];
    
    // Cek apakah kolom Nama Event ada
    if (!in_array('Nama Event', $col_names)) {
        throw new Exception("Kolom 'Nama Event' tidak ditemukan di Excel");
    }
    
    // Load master data
    $sites = $categories = [];
    $res = $mysqli->query("SELECT id, site_id FROM sites");
    while ($r = $res->fetch_assoc()) $sites[$r['site_id']] = $r['id'];
    
    $res = $mysqli->query("SELECT id, nama_kategori FROM event_categories");
    while ($r = $res->fetch_assoc()) $categories[strtolower($r['nama_kategori'])] = $r['id'];
    
    // Process data
    $mysqli->begin_transaction();
    
    foreach ($rows as $row_idx => $row) {
        // Skip header (baris 1)
        if ($row_idx === 1) continue;
        
        // Skip baris kosong
        if (empty(array_filter($row))) continue;
        
        try {
            // Map data dengan huruf kolom (A, B, C, ...)
            $data = [
                'nama_event' => trim($row['A'] ?? ''),
                'kategori_event' => trim($row['B'] ?? ''),
                'site_id_code' => trim($row['C'] ?? ''),
                'nama_branch' => trim($row['D'] ?? ''),
                'nama_micro_cluster' => trim($row['E'] ?? ''),
                'latitude' => trim($row['F'] ?? ''),
                'longitude' => trim($row['G'] ?? ''),
                'sp_0k' => trim($row['H'] ?? ''),
                'sp_3gb' => trim($row['I'] ?? ''),
                'sp_5gb' => trim($row['J'] ?? ''),
                'sp_7gb' => trim($row['K'] ?? ''),
                'sp_100gb' => trim($row['L'] ?? ''),
                'fwa' => trim($row['M'] ?? ''),
                'fwa_5g' => trim($row['N'] ?? ''),
                'hit_haji_umroh' => trim($row['O'] ?? ''),
                'reload' => trim($row['P'] ?? ''),
                'mobo_paket' => trim($row['Q'] ?? ''),
                'cost' => trim($row['R'] ?? ''),
                'alasan' => trim($row['S'] ?? ''),
                'foto' => trim($row['T'] ?? ''),
                'msisdn' => trim($row['U'] ?? '')
            ];
            
            // Validasi event name
            $event_name = trim($data['nama_event'] ?? '');
            if (empty($event_name)) throw new Exception("Nama Event kosong");
            
            // FIXED: Add validation for critical fields
            if (empty($data['site_id_code'])) {
                throw new Exception("Site ID wajib diisi");
            }
            
            if (empty($data['latitude']) || empty($data['longitude'])) {
                throw new Exception("Latitude dan Longitude wajib diisi");
            }
            
            if (empty($data['cost']) || (float)$data['cost'] <= 0) {
                throw new Exception("Cost wajib diisi dan harus lebih dari 0");
            }
            
            // Get IDs
            $site_id = null;
            if (!empty($data['site_id_code'])) {
                $site_id = $sites[$data['site_id_code']] ?? null;
            }
            
            $cat_id = null;
            if (!empty($data['kategori_event'])) {
                $cat_id = $categories[strtolower($data['kategori_event'])] ?? null;
            }
            
            // Convert numerics
            $sp_0k = (int)($data['sp_0k'] ?? 0);
            $sp_3gb = (int)($data['sp_3gb'] ?? 0);
            $sp_5gb = (int)($data['sp_5gb'] ?? 0);
            $sp_7gb = (int)($data['sp_7gb'] ?? 0);
            $sp_100gb = (int)($data['sp_100gb'] ?? 0);
            $fwa = (int)($data['fwa'] ?? 0);
            $fwa_5g = (int)($data['fwa_5g'] ?? 0);
            $hit = (int)($data['hit_haji_umroh'] ?? 0);
            $reload = (int)($data['reload'] ?? 0);
            $mobo = (int)($data['mobo_paket'] ?? 0);
            
            $lat = (float)($data['latitude'] ?? 0.0);
            $lng = (float)($data['longitude'] ?? 0.0);
            $cost = (float)($data['cost'] ?? 0.0);
            
            // Calculate benefits
            $jumlah_qsc = $sp_0k + $sp_3gb + $sp_5gb + $sp_7gb + $sp_100gb;
            $benefit_sp = ($sp_0k * $REVENUE['sp_0k']) + ($sp_3gb * $REVENUE['sp_3gb']) + 
                         ($sp_5gb * $REVENUE['sp_5gb']) + ($sp_7gb * $REVENUE['sp_7gb']);
            $benefit_fwa = ($fwa * $REVENUE['fwa']) + ($fwa_5g * $REVENUE['fwa_5g']);
            $benefit_total = $benefit_sp + $benefit_fwa + $mobo;
            $ratio = ($benefit_total > 0) ? ($cost / $benefit_total) * 100 : 0.0;
            
            // Process foto
            $foto_url = '';
            if (!empty($data['foto'])) {
                $foto_name = $data['foto'];
                $foto_path = $temp_folder . '/' . $foto_name;
                if (!file_exists($foto_path) && $sub_dir) {
                    $foto_path = $sub_dir . '/' . $foto_name;
                }
                
                if (file_exists($foto_path)) {
                    $ext = pathinfo($foto_name, PATHINFO_EXTENSION);
                    $new_name = 'photo_' . time() . '_' . uniqid() . '.' . $ext;
                    $dest = '../uploads/' . $new_name;
                    @mkdir('../uploads', 0777, true);
                    
                    if (copy($foto_path, $dest)) {
                        $foto_url = 'uploads/' . $new_name;
                    }
                }
            }
            
            // Build INSERT query dengan raw values (LEBIH TRANSPARAN)
            $query = "INSERT INTO event_submissions (
                user_id, site_id, kategori_event_id, location_latitude, location_longitude, event_name,
                sp_0k, sp_3gb, sp_5gb, sp_7gb, sp_100gb, fwa, fwa_5g, hit_haji_umroh,
                jumlah_qsc, reload, mobo_paket, cost, benefit_sp, benefit_fwa,
                benefit_total, ratio_cost_benefit, alasan, foto_event_url, site_snapshot_name
            ) VALUES (
                $user_id, 
                " . ($site_id ? $site_id : 'NULL') . ", 
                " . ($cat_id ? $cat_id : 'NULL') . ", 
                $lat, $lng, 
                '" . $mysqli->real_escape_string($event_name) . "',
                $sp_0k, $sp_3gb, $sp_5gb, $sp_7gb, $sp_100gb, $fwa, $fwa_5g, $hit,
                $jumlah_qsc, $reload, $mobo,
                $cost, $benefit_sp, $benefit_fwa, $benefit_total, $ratio,
                '" . $mysqli->real_escape_string($data['alasan'] ?? '') . "',
                '" . $mysqli->real_escape_string($foto_url) . "',
                '" . $mysqli->real_escape_string($data['nama_branch'] ?? '') . "'
            )";
            
            if (!$mysqli->query($query)) {
                throw new Exception("Query error: " . $mysqli->error);
            }
            
            $submit_id = $mysqli->insert_id;
            
            // Process MSISDN
            if (!empty($data['msisdn'])) {
                $msisdn_file = $data['msisdn'];
                $msisdn_path = $temp_folder . '/' . $msisdn_file;
                if (!file_exists($msisdn_path) && $sub_dir) {
                    $msisdn_path = $sub_dir . '/' . $msisdn_file;
                }
                
                if (file_exists($msisdn_path)) {
                    try {
                        $ms_reader = IOFactory::createReaderForFile($msisdn_path);
                        if (!empty($excel_password)) {
                            $ms_reader->setPassword($excel_password);
                        }
                        $ms_spreadsheet = $ms_reader->load($msisdn_path);
                        $ms_sheet = $ms_spreadsheet->getActiveSheet();
                        $ms_rows = $ms_sheet->toArray(null, true, true, true);
                        
                        foreach (array_slice($ms_rows, 1) as $ms_row) {
                            $msisdn = trim($ms_row['A'] ?? '');
                            $type = strtolower(trim($ms_row['B'] ?? 'new'));
                            
                            if (empty($msisdn)) continue;
                            
                            // Format MSISDN
                            $msisdn = preg_replace('/\D/', '', $msisdn);
                            if (strlen($msisdn) === 10 && $msisdn[0] === '8') {
                                $msisdn = '62' . $msisdn;
                            } elseif (strlen($msisdn) === 11 && substr($msisdn, 0, 2) === '08') {
                                $msisdn = '62' . substr($msisdn, 1);
                            }
                            
                            if (!in_array($type, ['existing', 'new'])) $type = 'new';
                            
                            if (strlen($msisdn) >= 11) {
                                // FIXED: Use prepared statement instead of direct query
                                $stmt_check_msisdn = $mysqli->prepare("SELECT submission_id FROM msisdn_data WHERE msisdn = ? LIMIT 1");
                                if ($stmt_check_msisdn) {
                                    $stmt_check_msisdn->bind_param("s", $msisdn);
                                    $stmt_check_msisdn->execute();
                                    $check_result = $stmt_check_msisdn->get_result();
                                    
                                    if ($check_result->num_rows > 0) {
                                        $orig = $check_result->fetch_assoc();
                                        $msisdn_stats['duplicated']++;
                                        
                                        // FIXED: Use prepared statement for duplicate log
                                        $stmt_dup_log = $mysqli->prepare("INSERT INTO duplicate_msisdn_log (new_submission_id, duplicate_msisdn, original_submission_id) VALUES (?, ?, ?)");
                                        if ($stmt_dup_log) {
                                            $stmt_dup_log->bind_param("isi", $submit_id, $msisdn, $orig['submission_id']);
                                            $stmt_dup_log->execute();
                                            $stmt_dup_log->close();
                                        }
                                    } else {
                                        // FIXED: Use prepared statement for insert
                                        $stmt_insert_msisdn = $mysqli->prepare("INSERT INTO msisdn_data (submission_id, msisdn, type) VALUES (?, ?, ?)");
                                        if ($stmt_insert_msisdn) {
                                            $stmt_insert_msisdn->bind_param("iss", $submit_id, $msisdn, $type);
                                            if ($stmt_insert_msisdn->execute()) {
                                                $msisdn_stats['added']++;
                                            } else {
                                                $msisdn_stats['failed']++;
                                            }
                                            $stmt_insert_msisdn->close();
                                        } else {
                                            $msisdn_stats['failed']++;
                                        }
                                    }
                                    $stmt_check_msisdn->close();
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // Skip MSISDN error, lanjut
                    }
                }
            }
            
            $import_count++;
            
        } catch (Exception $e) {
            if (!isset($errors[$row_idx])) {
                $errors[$row_idx] = [];
            }
            $errors[$row_idx][] = [
                'field' => 'general',
                'message' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    // Commit/Rollback
    if (empty($errors)) {
        $mysqli->commit();
        $msg = "✓ Import berhasil! Event: $import_count. MSISDN - Ditambah: {$msisdn_stats['added']}, Duplikat: {$msisdn_stats['duplicated']}, Gagal: {$msisdn_stats['failed']}";
        $type = 'success';
    } else {
        $mysqli->rollback();
        $msg = "✗ Import gagal! " . count($errors) . " baris error.";
        $type = 'error';
    }
    
} catch (Exception $e) {
    if ($mysqli->in_transaction) $mysqli->rollback();
    
    // FIXED: Cleanup uploaded files on error
    if (isset($foto_url) && !empty($foto_url) && file_exists('../' . $foto_url)) {
        @unlink('../' . $foto_url);
    }
    
    $msg = "Error: " . $e->getMessage();
    $type = 'error';
}

// Cleanup
if ($temp_folder && file_exists($temp_folder)) {
    deleteDir($temp_folder);
}

// Format error details - SESUAI DENGAN bulk_import_event.php
// Struktur: errors[row_idx] = [['field' => '', 'message' => '', 'timestamp' => ''], ...]
$error_details = json_encode([
    'errors' => $errors,
    'warnings' => [],
    'error_count' => count($errors),
    'warning_count' => 0,
    'generated_at' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Save to session
$_SESSION['import_status'] = [
    'type' => $type,
    'message' => $msg,
    'error_details' => $error_details,
    'import_count' => $import_count,
    'msisdn_stats' => $msisdn_stats
];

header("location: ../bulk_import_event.php");
exit;
?>
