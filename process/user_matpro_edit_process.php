<?php
// process/user_matpro_edit_process.php
require_once '../config/database.php';

// Cek jika user tidak login atau bukan 'user'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || ($_POST['action'] ?? '') !== 'edit_matpro_activity') {
    header("location: ../user_matpro_activities.php"); // Redirect jika bukan POST
    exit;
}

// Helper function for bind_param with dynamic arguments (for PHP < 8.1)
if (!function_exists('ref_values')) {
    function ref_values(&$arr){
        $refs = [];
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
}

// Fungsi untuk menangani upload file
function handle_photo_upload($file_input_name, $unique_id, $existing_photo_url = null) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$file_input_name];
        // FIXED: Use absolute path instead of hardcoded relative path
        $target_dir = dirname(__DIR__) . "/uploads/matpro/"; // Path absolut dari root aplikasi

        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = mime_content_type($file['tmp_name']);
        if (!in_array($file_type, $allowed_types)) {
            throw new Exception("Tipe file tidak valid untuk " . $file_input_name . ". Hanya JPEG, PNG, GIF yang diizinkan.");
        }

        if ($file['size'] > 7 * 1024 * 1024) { // 7MB limit
            throw new Exception("Ukuran file " . $file_input_name . " melebihi batas 7MB.");
        }

        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $new_filename = $unique_id . '_' . $file_input_name . '_' . time() . '.' . $file_extension;
        $target_file_path = $target_dir . $new_filename; // Path lengkap di server

        if (move_uploaded_file($file['tmp_name'], $target_file_path)) {
            // Hapus foto lama jika ada dan berbeda dengan yang baru
            if ($existing_photo_url && file_exists(dirname(__DIR__) . '/' . $existing_photo_url) && ('uploads/matpro/' . $new_filename) !== $existing_photo_url) {
                unlink(dirname(__DIR__) . '/' . $existing_photo_url);
            }
            return 'uploads/matpro/' . $new_filename; // Path relatif untuk DB
        } else {
            throw new Exception("Gagal memindahkan file yang diupload untuk " . $file_input_name . ".");
        }
    } else if ($existing_photo_url) {
        // Jika tidak ada file baru diupload tapi ada foto eksisting, gunakan yang lama
        return $existing_photo_url;
    }
    // Jika tidak ada file baru diupload DAN tidak ada foto eksisting, ini adalah error
    throw new Exception("File foto untuk " . $file_input_name . " wajib diupload.");
}

// Mulai transaksi
$mysqli->begin_transaction();

try {
    $user_id = (int)$_SESSION['id'];
    $activity_id_raw = $_POST['activity_id'] ?? 0;
    $activity_id = (int)$activity_id_raw; // Ambil bagian integer (buang suffix jika ada)
    $request_id = (int)($_POST['request_id'] ?? 0);

    // --- 1. Ambil Data Aktivitas Lama dan Verifikasi Hak Akses ---
    // PERBAIKAN: Menggunakan qty_used
    $stmt_old_activity = $mysqli->prepare("SELECT * FROM matpro_activities WHERE id = ? AND user_id = ? FOR UPDATE"); // Lock baris
    if (!$stmt_old_activity) throw new Exception("Gagal menyiapkan query aktivitas lama: " . $mysqli->error);
    $stmt_old_activity->bind_param("ii", $activity_id, $user_id);
    $stmt_old_activity->execute();
    $old_activity_data = $stmt_old_activity->get_result()->fetch_assoc();
    $stmt_old_activity->close();

    if (!$old_activity_data) {
        throw new Exception("Aktivitas Matpro tidak ditemukan atau Anda tidak memiliki izin.");
    }

    // --- Verifikasi Permintaan Edit ---
    // Jika request_id dikirimkan dari form, gunakan ID tersebut.
    // Jika tidak ada (kasus lama), coba cari request edit terakhir yang disetujui untuk aktivitas ini.
    if ($request_id > 0) {
        $sql_check_req = "SELECT id, status FROM matpro_edit_requests WHERE id = ? AND activity_id = ? AND user_id = ? FOR UPDATE";
        $stmt_check_request = $mysqli->prepare($sql_check_req);
        if (!$stmt_check_request) throw new Exception("Gagal menyiapkan query cek permintaan edit by ID: " . $mysqli->error);
        $stmt_check_request->bind_param("iii", $request_id, $activity_id, $user_id);
    } else {
        $sql_check_req = "SELECT id, status FROM matpro_edit_requests WHERE activity_id = ? AND user_id = ? AND status IN ('approved') ORDER BY requested_at DESC LIMIT 1 FOR UPDATE";
        $stmt_check_request = $mysqli->prepare($sql_check_req);
        if (!$stmt_check_request) throw new Exception("Gagal menyiapkan query cek permintaan edit terbaru: " . $mysqli->error);
        $stmt_check_request->bind_param("ii", $activity_id, $user_id);
    }
    
    $stmt_check_request->execute();
    $edit_request_data = $stmt_check_request->get_result()->fetch_assoc();
    $stmt_check_request->close();

    if (!$edit_request_data) {
        throw new Exception("Tidak ada permintaan edit yang valid/disetujui yang ditemukan.");
    }

    $request_id = $edit_request_data['id']; // Pastikan kita menggunakan ID dari database

    // FIXED: Check if request is already completed (race condition prevention)
    if ($edit_request_data['status'] === 'completed') {
        throw new Exception("Permintaan edit ini sudah selesai diproses. Jika Anda ingin mengedit lagi, silakan ajukan permintaan edit baru.");
    }
    
    if ($edit_request_data['status'] !== 'approved') {
        throw new Exception("Permintaan edit belum disetujui oleh admin.");
    }

    // --- 2. Ambil dan Validasi Data Baru dari Form ---
    $new_data = [
        'activity_datetime' => $_POST['activity_datetime'] ?? $old_activity_data['activity_datetime'], // Waktu aktivitas bisa diubah
        'branch_id' => (int)$_POST['branch_id'],
        'micro_cluster_id' => (!empty($_POST['micro_cluster_id']) ? (int)$_POST['micro_cluster_id'] : null),
        'site_id' => (int)$_POST['site_id'],
        'outlet_id' => (int)$_POST['outlet_id'],
        'location_latitude' => (float)$_POST['location_latitude'],
        'location_longitude' => (float)$_POST['location_longitude'],
        'project_id' => (int)$_POST['project_id'],
        'type_id' => (int)$_POST['type_id'],
        'qty_used' => (int)$_POST['qty_used'],
    ];

    if (empty($new_data['branch_id']) || empty($new_data['site_id']) || empty($new_data['outlet_id']) || empty($new_data['project_id']) || empty($new_data['type_id']) || $new_data['qty_used'] <= 0) {
        throw new Exception("Data wajib tidak lengkap. Mohon isi semua kolom yang bertanda *.");
    }

    // Ambil nama outlet untuk snapshot (jika outlet_id berubah, ambil nama baru)
    if ($new_data['outlet_id'] !== $old_activity_data['outlet_id']) {
        $stmt_new_outlet_name = $mysqli->prepare("SELECT Id_Outlet_Nama_Outlet FROM outlets WHERE id = ?");
        if (!$stmt_new_outlet_name) throw new Exception("Gagal menyiapkan query nama outlet baru.");
        $stmt_new_outlet_name->bind_param("i", $new_data['outlet_id']);
        $stmt_new_outlet_name->execute();
        $new_data['outlet_snapshot_name'] = $stmt_new_outlet_name->get_result()->fetch_assoc()['Id_Outlet_Nama_Outlet'] ?? 'N/A';
        $stmt_new_outlet_name->close();
    } else {
        $new_data['outlet_snapshot_name'] = $old_activity_data['outlet_snapshot_name'];
    }

    // --- 3. Penyesuaian Stok (jika ada perubahan QTY, Project, Type, Branch, atau MC) ---
    // FIXED: Moved stock adjustment BEFORE file upload to prevent orphaned files on rollback
    $old_qty = (int)$old_activity_data['qty_used']; // PERBAIKAN: Menggunakan qty_used
    $new_qty = (int)$new_data['qty_used'];

    // Cek apakah ada perubahan yang mempengaruhi stok
    $stock_affected = false;
    if ($old_activity_data['project_id'] != $new_data['project_id'] ||
        $old_activity_data['type_id'] != $new_data['type_id'] ||
        $old_activity_data['branch_id'] != $new_data['branch_id'] ||
        ($old_activity_data['micro_cluster_id'] != $new_data['micro_cluster_id'] && !($old_activity_data['micro_cluster_id'] === null && $new_data['micro_cluster_id'] === null))
    ) {
        $stock_affected = true; // Perubahan lokasi stok
    } elseif ($old_qty != $new_qty) {
        $stock_affected = true; // Perubahan kuantitas
    }

    // --- 4. Handle File Uploads (Foto) ---
    // FIXED: Upload photos AFTER stock validation but BEFORE database update
    $photo_before_url = handle_photo_upload('photo_before', $old_activity_data['unique_id'], $_POST['existing_photo_before_url'] ?? null);
    $photo_after_url = handle_photo_upload('photo_after', $old_activity_data['unique_id'], $_POST['existing_photo_after_url'] ?? null);

    if ($stock_affected) {
        // Kembalikan stok lama
        $sql_return_old_stock = "UPDATE matpro_stocks SET stock_quantity = stock_quantity + ?, last_updated_by = ?, last_updated_at = NOW() WHERE project_id = ? AND type_id = ? AND branch_id = ?";
        $params_return = [$old_qty, $user_id, $old_activity_data['project_id'], $old_activity_data['type_id'], $old_activity_data['branch_id']];
        $types_return = "iiiii";
        if ($old_activity_data['micro_cluster_id'] !== null && $old_activity_data['micro_cluster_id'] != 0) { // Menambahkan kondisi != 0
            $sql_return_old_stock .= " AND micro_cluster_id = ?";
            $types_return .= "i";
            $params_return[] = $old_activity_data['micro_cluster_id'];
        } else {
            $sql_return_old_stock .= " AND (micro_cluster_id IS NULL OR micro_cluster_id = 0)"; // Menambahkan kondisi = 0
        }
        $stmt_return_old_stock = $mysqli->prepare($sql_return_old_stock);
        if (!$stmt_return_old_stock) throw new Exception("Gagal menyiapkan query pengembalian stok lama: " . $mysqli->error);
        call_user_func_array([$stmt_return_old_stock, 'bind_param'], array_merge([$types_return], ref_values($params_return)));
        $stmt_return_old_stock->execute();
        $stmt_return_old_stock->close();

        // FIXED: Check stock availability BEFORE attempting deduction
        $sql_check_new_stock = "SELECT stock_quantity FROM matpro_stocks WHERE project_id = ? AND type_id = ? AND branch_id = ?";
        $params_check = [$new_data['project_id'], $new_data['type_id'], $new_data['branch_id']];
        $types_check = "iii";
        if ($new_data['micro_cluster_id'] !== null && $new_data['micro_cluster_id'] != 0) {
            $sql_check_new_stock .= " AND micro_cluster_id = ?";
            $types_check .= "i";
            $params_check[] = $new_data['micro_cluster_id'];
        } else {
            $sql_check_new_stock .= " AND (micro_cluster_id IS NULL OR micro_cluster_id = 0)";
        }
        
        $stmt_check_new_stock = $mysqli->prepare($sql_check_new_stock);
        if (!$stmt_check_new_stock) throw new Exception("Gagal menyiapkan query pengecekan stok baru: " . $mysqli->error);
        call_user_func_array([$stmt_check_new_stock, 'bind_param'], array_merge([$types_check], ref_values($params_check)));
        $stmt_check_new_stock->execute();
        $stock_result = $stmt_check_new_stock->get_result()->fetch_assoc();
        $stmt_check_new_stock->close();
        
        if (!$stock_result) {
            throw new Exception("Stok tidak ditemukan untuk lokasi baru (Proyek: {$new_data['project_id']}, Jenis: {$new_data['type_id']}, Branch: {$new_data['branch_id']}, MC: " . ($new_data['micro_cluster_id'] ?? 'NULL') . "). Pastikan stok tersedia.");
        }
        
        if ($stock_result['stock_quantity'] < $new_qty) {
            throw new Exception("Stok tidak mencukupi untuk lokasi baru. Stok tersedia: {$stock_result['stock_quantity']}, dibutuhkan: {$new_qty}.");
        }

        // Kurangi stok baru
        $sql_deduct_new_stock = "UPDATE matpro_stocks SET stock_quantity = stock_quantity - ?, last_updated_by = ?, last_updated_at = NOW() WHERE project_id = ? AND type_id = ? AND branch_id = ?";
        $params_deduct = [$new_qty, $user_id, $new_data['project_id'], $new_data['type_id'], $new_data['branch_id']];
        $types_deduct = "iiiii";
        if ($new_data['micro_cluster_id'] !== null && $new_data['micro_cluster_id'] != 0) { // Menambahkan kondisi != 0
            $sql_deduct_new_stock .= " AND micro_cluster_id = ?";
            $types_deduct .= "i";
            $params_deduct[] = $new_data['micro_cluster_id'];
        } else {
            $sql_deduct_new_stock .= " AND (micro_cluster_id IS NULL OR micro_cluster_id = 0)"; // Menambahkan kondisi = 0
        }

        $stmt_deduct_new_stock = $mysqli->prepare($sql_deduct_new_stock);
        if (!$stmt_deduct_new_stock) throw new Exception("Gagal menyiapkan query pengurangan stok baru: " . $mysqli->error);
        call_user_func_array([$stmt_deduct_new_stock, 'bind_param'], array_merge([$types_deduct], ref_values($params_deduct)));
        $stmt_deduct_new_stock->execute();
        if ($stmt_deduct_new_stock->affected_rows === 0) {
            // Jika tidak ada baris yang terpengaruh, mungkin stok tidak ditemukan atau tidak cukup.
            // Rollback pengembalian stok lama dan berikan pesan error.
            $mysqli->rollback();
            throw new Exception("Stok tidak ditemukan atau tidak mencukupi untuk lokasi baru. Pastikan stok tersedia.");
        }
        $stmt_deduct_new_stock->close();
    }

    // --- 5. Update Data Aktivitas ---
    $sql_update_activity = "UPDATE matpro_activities SET
                                activity_datetime = ?,
                                branch_id = ?,
                                micro_cluster_id = ?,
                                site_id = ?,
                                outlet_id = ?,
                                outlet_snapshot_name = ?,
                                project_id = ?,
                                type_id = ?,
                                qty_used = ?,
                                location_latitude = ?,
                                location_longitude = ?,
                                photo_before_url = ?,
                                photo_after_url = ?
                            WHERE id = ? AND user_id = ?";
    $stmt_update_activity = $mysqli->prepare($sql_update_activity);
    if (!$stmt_update_activity) throw new Exception("Gagal menyiapkan statement update aktivitas: " . $mysqli->error);

    // Perbaikan: Koreksi string tipe data
    $bind_types_update = "siiiisiiiddssii"; // 15 parameter: s, i, i, i, i, s, i, i, i, d, d, s, s, i, i
    $bind_params_update = [
        $new_data['activity_datetime'],
        $new_data['branch_id'],
        $new_data['micro_cluster_id'],
        $new_data['site_id'],
        $new_data['outlet_id'],
        $new_data['outlet_snapshot_name'],
        $new_data['project_id'],
        $new_data['type_id'],
        $new_data['qty_used'],
        $new_data['location_latitude'],
        $new_data['location_longitude'],
        $photo_before_url,
        $photo_after_url,
        $activity_id,
        $user_id
    ];
    call_user_func_array([$stmt_update_activity, 'bind_param'], array_merge([$bind_types_update], ref_values($bind_params_update)));

    if (!$stmt_update_activity->execute()) throw new Exception("Gagal memperbarui data aktivitas: " . $stmt_update_activity->error);
    $stmt_update_activity->close();

    // --- 6. Tandai Permintaan Edit sebagai 'completed' ---
    $sql_update_request = "UPDATE matpro_edit_requests SET status = 'completed', reviewed_at = NOW() WHERE id = ? AND activity_id = ? AND user_id = ?";
    $stmt_update_request = $mysqli->prepare($sql_update_request);
    if (!$stmt_update_request) throw new Exception("Gagal menyiapkan statement update permintaan edit: " . $mysqli->error);
    $stmt_update_request->bind_param("iii", $request_id, $activity_id, $user_id);
    $stmt_update_request->execute();
    if ($stmt_update_request->affected_rows === 0) {
        throw new Exception("Gagal menutup permintaan edit. Request tidak ditemukan, sudah tertutup, atau activity ID mismatch.");
    }
    $stmt_update_request->close();

    // --- 7. Commit Transaksi dan Redirect ---
    $mysqli->commit();
    $_SESSION['success_message'] = "Aktivitas Matpro dengan ID " . $old_activity_data['unique_id'] . " berhasil diperbarui.";
    header("location: ../user_matpro_activities.php");
    exit();

} catch (Exception $e) {
    $mysqli->rollback();
    
    // FIXED: Cleanup uploaded files on rollback
    if (isset($photo_before_url) && file_exists('../' . $photo_before_url) && strpos($photo_before_url, 'uploads/matpro/') === 0) {
        @unlink('../' . $photo_before_url);
    }
    if (isset($photo_after_url) && file_exists('../' . $photo_after_url) && strpos($photo_after_url, 'uploads/matpro/') === 0) {
        @unlink('../' . $photo_after_url);
    }
    
    $_SESSION['error_message'] = "Terjadi kesalahan saat menyimpan perubahan: " . $e->getMessage();
    header("location: ../user_matpro_activities.php");
    exit();
}
