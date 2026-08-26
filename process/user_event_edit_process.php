<?php
// process/user_event_edit_process.php

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Pastikan session dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cek hak akses user
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || ($_POST['action'] ?? '') !== 'edit_event') {
    header("location: ../dashboard_user.php");
    exit;
}

$user_id = (int)$_SESSION['id'];
$event_unique_id_raw = trim((string)($_POST['event_unique_id'] ?? ''));
$event_unique_id = (int)$event_unique_id_raw; // Ambil bagian integer saja (buang suffix jika ada)
$request_id = (int)($_POST['request_id'] ?? 0); // Ambil Request ID dari hidden field

if (empty($event_unique_id) || empty($request_id)) {
    $_SESSION['error_message'] = "Data event atau permintaan edit tidak lengkap.";
    header("location: ../dashboard_user.php");
    exit;
}

// --- FUNGSI HELPER UNTUK KOMPRESI GAMBAR --- (Sama seperti di input_form.php)
function compress_image($source, $destination, $quality) {
    $info = getimagesize($source);
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
    } else {
        return false; // Tipe file tidak didukung
    }

    if ($info['mime'] == 'image/png') {
        $quality = (int)(($quality / 100) * 9); // Kualitas untuk PNG (0-9)
        return imagepng($image, $destination, $quality);
    } else {
        return imagejpeg($image, $destination, $quality);
    }
}

// --- FUNGSI HELPER UNTUK MENDAPATKAN REFERENSI DARI NILAI ARRAY
function get_array_reference(&$array, $key) {
    if (array_key_exists($key, $array)) {
        return $array[$key];
    }
    return null;
}

// --- MAPPING NAMA KOLOM UNTUK PENGECEKAN HAK AKSES DAN UPDATE ---
// (Logika ini diimplementasikan di user_edit_form.php, tapi disiapkan di sini juga untuk validasi)
// Dalam konteks file proses, kita berasumsi semua data yang diterima dari form adalah yang disetujui,
// dan kita akan mengambil data lama untuk kolom yang tidak dikirim/tidak diubah.

// --- 1. VERIFIKASI PERMINTAAN EDIT (Cek Status) ---
if ($request_id > 0) {
    $sql_check_req = "SELECT requested_columns, status FROM event_requests WHERE id = ? AND event_id = ? AND user_id = ? AND request_type = 'edit' FOR UPDATE";
    $stmt_check_request = $mysqli->prepare($sql_check_req);
    $stmt_check_request->bind_param("iii", $request_id, $event_unique_id, $user_id);
} else {
    $sql_check_req = "SELECT requested_columns, status FROM event_requests WHERE event_id = ? AND user_id = ? AND status = 'approved' AND request_type = 'edit' ORDER BY requested_at DESC LIMIT 1 FOR UPDATE";
    $stmt_check_request = $mysqli->prepare($sql_check_req);
    $stmt_check_request->bind_param("ii", $event_unique_id, $user_id);
}

$stmt_check_request->execute();
$result_check_request = $stmt_check_request->get_result();

if ($result_check_request->num_rows === 0) {
    $_SESSION['error_message'] = "Tidak ada permintaan edit yang valid/disetujui yang ditemukan.";
    header("location: ../dashboard_user.php");
    exit;
}

$request_data = $result_check_request->fetch_assoc();
$stmt_check_request->close();

if ($request_data['status'] === 'completed') {
    $_SESSION['error_message'] = "Permintaan edit ini sudah selesai diproses. Anda tidak bisa mengedit data ini lagi.";
    header("location: ../dashboard_user.php");
    exit;
}

if ($request_data['status'] !== 'approved') {
    $_SESSION['error_message'] = "Permintaan edit belum disetujui atau sudah kedaluwarsa.";
    header("location: ../dashboard_user.php");
    exit;
}

$requested_columns = json_decode($request_data['requested_columns'], true) ?? [];


// --- 2. AMBIL DATA EVENT LAMA UNTUK KOLOM YANG TIDAK DIUBAH ---
$stmt_old_data = $mysqli->prepare("SELECT * FROM event_submissions WHERE unique_id = ? AND user_id = ?");
$stmt_old_data->bind_param("ii", $event_unique_id, $user_id);
$stmt_old_data->execute();
$old_event_data = $stmt_old_data->get_result()->fetch_assoc();
$stmt_old_data->close();

if (!$old_event_data) {
    $_SESSION['error_message'] = "Event tidak ditemukan.";
    header("location: ../dashboard_user.php");
    exit;
}

// --- 3. SIAPKAN DATA BARU UNTUK UPDATE ---
$data_to_update = [];
$param_types = "";
$param_values = [];
$update_set_sql = [];

// Kolom yang dapat diubah dan dipetakan ke input POST
$mappable_columns = [
    'event_name', 'kategori_event_id', 'site_id', 
    'location_latitude', 'location_longitude', 
    'sp_0k', 'sp_3gb', 'sp_5gb', 'sp_7gb', 'sp_100gb', 
    'fwa', 'fwa_5g', 
    'hit_haji_umroh', 'reload', 'mobo_paket', 'cost', 
    'alasan', 'kenal_im3', 'sudah_beli_im3', 'lokasi_beli', 'tertarik_beli_im3'
];


// Kolom Checkbox/Array (Membutuhkan penanganan khusus)
$data_to_update['provider_digunakan'] = implode(', ', $_POST['provider_digunakan'] ?? []);
$data_to_update['provider_terbaik'] = implode(', ', $_POST['provider_terbaik'] ?? []);

// Gabungkan semua data POST yang relevan ke $data_to_update
foreach ($mappable_columns as $col) {
    // Pastikan hanya kolom yang disetujui (atau kolom default yang harus diizinkan) yang diupdate
    // Di sini kita berasumsi form hanya mengirimkan kolom yang diizinkan untuk diedit.
    // Tetapi kita tetap menggunakan data lama jika kolom tidak ada di POST
    $post_key = $col;
    if ($col === 'location_latitude') $post_key = 'latitude';
    if ($col === 'location_longitude') $post_key = 'longitude';

    if (isset($_POST[$post_key])) {
        $data_to_update[$col] = $_POST[$post_key];
    } else {
        // Jika kolom tidak ada di POST (artinya disabled di form), gunakan nilai lama.
        $data_to_update[$col] = $old_event_data[$col];
    }
}

// Hitung Benefit Total dan Ratio
$benefit_sp = (
    ($data_to_update['sp_0k'] * 10000) + ($data_to_update['sp_3gb'] * 27000) + 
    ($data_to_update['sp_5gb'] * 35000) + ($data_to_update['sp_7gb'] * 39000)
);

$benefit_fwa = ($data_to_update['fwa'] * 150000) + ($data_to_update['fwa_5g'] * 750000);

$benefit_total = (
    $benefit_sp + 
    $benefit_fwa + 
    (float)$data_to_update['mobo_paket']
);

$data_to_update['benefit_sp'] = $benefit_sp;
$data_to_update['benefit_fwa'] = $benefit_fwa;
$data_to_update['benefit_total'] = $benefit_total;
$cost = (float)$data_to_update['cost'];
$ratio_cost_benefit = ($benefit_total > 0) ? round(($cost / $benefit_total) * 100, 2) : 0.00;
$data_to_update['ratio_cost_benefit'] = $ratio_cost_benefit;

// Atur array untuk persiapan statement
foreach ($data_to_update as $col => $value) {
    if (is_numeric($value) && $col !== 'kategori_event_id' && $col !== 'site_id' && $col !== 'location_latitude' && $col !== 'location_longitude') {
        $param_types .= "d"; // double/float
    } elseif ($col === 'kategori_event_id' || $col === 'site_id') {
         $param_types .= "i"; // integer
         $value = (int)$value;
    } else {
        $param_types .= "s"; // string
    }
    $param_values[] = $value;
    $update_set_sql[] = "{$col} = ?";
}

// Tambahkan timestamp update
$update_set_sql[] = "waktu_update = NOW()";


// --- 4. PENANGANAN FOTO (Jika ada file baru) ---
$existing_foto_url = $old_event_data['foto_event_url'];
$foto_event_url = $existing_foto_url;
$foto_upload_success = true; // Flag untuk melacak status upload foto

// Hanya proses upload jika kolom 'foto_event' diizinkan untuk diedit
if (in_array('foto_event', $requested_columns) && isset($_FILES['foto_event']) && $_FILES['foto_event']['error'] == UPLOAD_ERR_OK) {
    
    // Periksa ukuran file
    if ($_FILES['foto_event']['size'] > 7 * 1024 * 1024) { // Max 7MB
        $_SESSION['error_message'] = "Ukuran file foto melebihi batas 7MB!";
        header("location: ../user_edit_form.php?id=" . $event_unique_id);
        exit;
    }

    $target_dir = "../uploads/event_photos/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $file_extension = strtolower(pathinfo($_FILES['foto_event']['name'], PATHINFO_EXTENSION));
    $new_file_name = $event_unique_id . '_' . time() . '.' . $file_extension;
    $target_file = $target_dir . $new_file_name;
    $compressed_file = $target_dir . 'compressed_' . $event_unique_id . '_' . time() . '.jpg';
    
    // Coba upload file asli
    if (move_uploaded_file($_FILES['foto_event']['tmp_name'], $target_file)) {
        // Coba kompres
        if (compress_image($target_file, $compressed_file, 75)) {
            $foto_event_url = str_replace('../', '', $compressed_file);
            unlink($target_file); // Hapus file asli
        } else {
            // Gagal kompres, gunakan yang asli, tetapi perbarui nama
            $foto_event_url = str_replace('../', '', $target_file);
        }

        // Hapus foto lama jika ada dan berbeda dari yang baru
        if (!empty($existing_foto_url) && $existing_foto_url != $foto_event_url) {
            $old_file_path = "../" . $existing_foto_url;
            if (file_exists($old_file_path) && is_file($old_file_path)) {
                unlink($old_file_path);
            }
        }
        
        $data_to_update['foto_event_url'] = $foto_event_url;
        $update_set_sql[] = "foto_event_url = ?";
        $param_types .= "s";
        $param_values[] = $foto_event_url;
    } else {
        $foto_upload_success = false;
        error_log("Gagal memindahkan file foto yang diunggah untuk event ID: " . $event_unique_id);
    }
} elseif (in_array('foto_event', $requested_columns) && empty($existing_foto_url) && $_FILES['foto_event']['error'] != UPLOAD_ERR_OK) {
    // Jika foto diizinkan edit, tetapi foto lama kosong dan user tidak upload, ini adalah error (harus dicegah di frontend, tapi sebagai fallback)
    $foto_upload_success = false;
    $_SESSION['error_message'] = "Foto event wajib diunggah karena sebelumnya kosong dan kolom ini diizinkan untuk diedit.";
    header("location: ../user_edit_form.php?id=" . $event_unique_id);
    exit;
}


// --- 5. PENANGANAN MSISDN (Jika ada file baru) ---
$new_unique_msisdns = [];
$found_duplicates_with_details = [];
$msisdn_file_upload_success = true;

// Hanya proses MSISDN jika kolom 'msisdn_file_name' diizinkan untuk diedit
if (in_array('msisdn_file_name', $requested_columns) && isset($_FILES['msisdn_file']) && $_FILES['msisdn_file']['error'] == UPLOAD_ERR_OK) {
    try {
        $excel_file = $_FILES['msisdn_file']['tmp_name'];
        $spreadsheet = IOFactory::load($excel_file);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $msisdn = trim((string)$sheet->getCell('A' . $row)->getValue());
            if (!empty($msisdn) && strlen($msisdn) >= 10 && is_numeric($msisdn)) {
                $new_unique_msisdns[] = $msisdn;
            }
        }
        $new_unique_msisdns = array_unique($new_unique_msisdns); // Hanya ambil yang unik
        
    } catch (Exception $e) {
        $msisdn_file_upload_success = false;
        error_log("Gagal memproses file MSISDN: " . $e->getMessage());
        $_SESSION['warning_message'] = "Peringatan: Gagal memproses file MSISDN. Data event lain berhasil disimpan.";
    }
}


// --- 6. EKSEKUSI DATABASE UPDATE (Dalam Transaksi) ---
$mysqli->begin_transaction();
try {
    // Query Update Event
    // Hanya masukkan kolom yang sudah disiapkan di $data_to_update dan kolom foto/waktu update
    $sql_update_event = "UPDATE event_submissions SET " . implode(', ', $update_set_sql) . " WHERE unique_id = ? AND user_id = ?";
    
    // Tambahkan event_unique_id dan user_id ke akhir param_values untuk klausa WHERE
    $final_param_values = array_merge($param_values, [$event_unique_id, $user_id]);
    $final_param_types = $param_types . "ii"; 
    $stmt_update = $mysqli->prepare($sql_update_event);
    if (!$stmt_update) {
        throw new Exception("Gagal menyiapkan statement update event: " . $mysqli->error);
    }
    // Bind parameter secara dinamis
    $stmt_update->bind_param($final_param_types, ...$final_param_values);
    
    if (!$stmt_update->execute()) {
        throw new Exception("Gagal memperbarui event: " . $stmt_update->error);
    }
    $stmt_update->close();

    // --- 7.5. TANDAI REQUEST EDIT SEBAGAI SELESAI ('completed') ---
    $stmt_complete_request = $mysqli->prepare("UPDATE event_requests SET status = 'completed', reviewed_at = NOW() WHERE id = ? AND event_id = ? AND user_id = ? AND request_type = 'edit'");
    if (!$stmt_complete_request) {
        throw new Exception("Gagal menyiapkan statement update permintaan edit: " . $mysqli->error);
    }
    
    $stmt_complete_request->bind_param("iii", $request_id, $event_unique_id, $user_id);
    $stmt_complete_request->execute();
    
    if ($stmt_complete_request->affected_rows === 0) {
        throw new Exception("Gagal menutup permintaan edit. Request tidak ditemukan, sudah tertutup, atau event ID mismatch.");
    }
    $stmt_complete_request->close();


    // --- 7. INSERT MSISDN BARU (Jika ada) ---
    if (!empty($new_unique_msisdns)) {
        // Query untuk cek duplikat MSISDN di semua event (Global)
        $placeholders = implode(',', array_fill(0, count($new_unique_msisdns), '?'));
        $msisdn_types = str_repeat('s', count($new_unique_msisdns));
        
        $stmt_check_dupes = $mysqli->prepare("
            SELECT msisdn, submission_id 
            FROM msisdn_data 
            WHERE msisdn IN ({$placeholders})
        ");
        
        if (!$stmt_check_dupes) {
             throw new Exception("Gagal menyiapkan statement cek duplikat MSISDN: " . $mysqli->error);
        }
        $stmt_check_dupes->bind_param($msisdn_types, ...$new_unique_msisdns);
        $stmt_check_dupes->execute();
        $duplicate_results = $stmt_check_dupes->get_result();
        
        $existing_msisdns = [];
        while ($row = $duplicate_results->fetch_assoc()) {
            $existing_msisdns[$row['msisdn']] = $row['submission_id'];
        }
        $stmt_check_dupes->close();
        
        $msisdns_to_insert = [];
        foreach ($new_unique_msisdns as $msisdn) {
            if (!isset($existing_msisdns[$msisdn])) {
                $msisdns_to_insert[] = $msisdn;
            } else {
                $found_duplicates_with_details[$msisdn] = $existing_msisdns[$msisdn];
            }
        }
        
        if (!empty($msisdns_to_insert)) {
            // Bulk insert MSISDN
            $insert_values = [];
            $insert_types = "";
            $insert_params = [];
            $submission_id = $event_unique_id;

            foreach ($msisdns_to_insert as $msisdn) {
                $insert_values[] = "(?, ?)"; // msisdn, submission_id
                $insert_types .= "si";
                $insert_params[] = $msisdn;
                $insert_params[] = $submission_id;
            }

            $sql_insert_msisdn = "INSERT INTO msisdn_data (msisdn, submission_id) VALUES " . implode(', ', $insert_values);
            $stmt_insert_msisdn = $mysqli->prepare($sql_insert_msisdn);
            
            if (!$stmt_insert_msisdn) {
                 throw new Exception("Gagal menyiapkan statement insert MSISDN: " . $mysqli->error);
            }
            // Bind parameter secara dinamis
            $stmt_insert_msisdn->bind_param($insert_types, ...$insert_params);
            
            if (!$stmt_insert_msisdn->execute()) {
                throw new Exception("Gagal menyimpan MSISDN baru: " . $stmt_insert_msisdn->error);
            }
            $stmt_insert_msisdn->close();
        }
    }




    // --- 8. COMMIT & BUAT NOTIFIKASI ---\r\n
    $mysqli->commit();
    $_SESSION['success_message'] = "Event berhasil diperbarui. ";
    if (!empty($new_unique_msisdns)) {
        $_SESSION['success_message'] .= count($new_unique_msisdns) . " MSISDN baru ditambahkan.";
    }
    if (!empty($found_duplicates_with_details)) {
        $duplicate_list = implode(', ', array_keys($found_duplicates_with_details));
        $_SESSION['warning_message'] = "Peringatan: MSISDN berikut sudah ada di database dan telah dicatat sebagai duplikat: " . $duplicate_list;
    }

} catch (Exception $e) {
    $mysqli->rollback();
    $_SESSION['error_message'] = "Terjadi kesalahan saat menyimpan perubahan: " . $e->getMessage();
    // Coba hapus foto yang mungkin terupload sebagian/gagal jika terjadi error lain
    if (isset($compressed_file) && file_exists($compressed_file) && $foto_event_url != $existing_foto_url) {
        unlink($compressed_file);
    }
    if (isset($target_file) && file_exists($target_file) && $foto_event_url != $existing_foto_url) {
        unlink($target_file);
    }
}

// Redirect ke halaman detail event user
header("location: ../user_detail_event.php?id=" . $event_unique_id);
exit;
