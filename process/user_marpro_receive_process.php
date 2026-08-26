<?php
// process/user_marpro_receive_process.php
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Guard login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] ?? '') !== 'user') {
    $_SESSION['error_message'] = 'Akses ditolak.';
    header('location: ../login.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('location: ../user_marpro_receive.php');
    exit;
}

// Helper: kompres gambar
if (!function_exists('compress_image')) {
    function compress_image($source, $destination, $quality) {
        $info = @getimagesize($source);
        if ($info === false) return false;
        $mime = $info['mime'];
        switch ($mime) {
            case 'image/jpeg': $img = imagecreatefromjpeg($source); break;
            case 'image/png':  $img = imagecreatefrompng($source);  imagepalettetotruecolor($img); imagealphablending($img, true); imagesavealpha($img, true); break;
            case 'image/gif':  $img = imagecreatefromgif($source);  break;
            default: return false;
        }
        if (!$img) return false;
        if ($mime === 'image/png') {
            $png_q = 9 - (int)round(($quality / 100) * 9);
            return imagepng($img, $destination, $png_q);
        }
        return imagejpeg($img, $destination, max(0, min(100, (int)$quality)));
    }
}

function handle_photo_upload_receive($file_input_name, $unique_id) {
    if (!isset($_FILES[$file_input_name]) || $_FILES[$file_input_name]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File foto wajib diupload.');
    }
    $file       = $_FILES[$file_input_name];
    $target_dir = dirname(__DIR__) . '/uploads/matpro/receives/';
    if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
    $allowed = ['image/jpeg', 'image/png', 'image/gif'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) throw new Exception('Tipe file foto tidak valid. Gunakan JPEG/PNG/GIF.');
    if ($file['size'] > 7 * 1024 * 1024) throw new Exception('Ukuran file foto melebihi batas 7MB.');

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new  = $unique_id . '_' . time() . '.' . $ext;
    $path = $target_dir . $new;
    if (!move_uploaded_file($file['tmp_name'], $path)) throw new Exception('Gagal menyimpan file foto.');
    if (!compress_image($path, $path, 80)) { @unlink($path); throw new Exception('Gagal mengompres gambar.'); }
    return 'uploads/matpro/receives/' . $new;
}

// Ambil data sesi (gunakan session, bukan POST hidden)
$user_id    = (int)($_SESSION['id'] ?? 0);
$branch_id  = (int)($_SESSION['branch_id'] ?? 0);
$user_brand = $_SESSION['brand'] ?? '';

if ($user_id <= 0 || $branch_id <= 0) {
    $_SESSION['error_message'] = 'Sesi tidak valid. Silakan login ulang.';
    header('location: ../login.php');
    exit;
}

// Ambil & validasi data form
$project_name    = trim($_POST['project_id'] ?? '');
$type_name       = trim($_POST['type_id'] ?? '');
$tanggal_terima  = trim($_POST['tanggal_terima'] ?? '');
$qty_branch      = (int)($_POST['qty_branch'] ?? 0);
$diterima_siapa  = trim($_POST['diterima_siapa'] ?? '');
$latitude        = trim($_POST['latitude'] ?? '');
$longitude       = trim($_POST['longitude'] ?? '');

if (empty($project_name) || empty($type_name) || empty($tanggal_terima) || $qty_branch <= 0 || empty($diterima_siapa) || empty($latitude) || empty($longitude)) {
    $_SESSION['error_message'] = 'Data tidak lengkap. Pastikan semua kolom terisi dan GPS aktif.';
    header('location: ../user_marpro_receive.php');
    exit;
}

// Validasi koordinat numerik
if (!is_numeric($latitude) || !is_numeric($longitude)) {
    $_SESSION['error_message'] = 'Koordinat GPS tidak valid. Aktifkan GPS dan coba lagi.';
    header('location: ../user_marpro_receive.php');
    exit;
}

// Ambil brand dari proyek dari matpro_stocks
$project_brand = $user_brand; // fallback
$stmt_brand = $mysqli->prepare("SELECT project_brand FROM matpro_stocks WHERE project_name = ? LIMIT 1");
if ($stmt_brand) {
    $stmt_brand->bind_param('s', $project_name);
    $stmt_brand->execute();
    $res_brand = $stmt_brand->get_result()->fetch_assoc();
    if ($res_brand && !empty($res_brand['project_brand'])) {
        $project_brand = $res_brand['project_brand'];
    }
    $stmt_brand->close();
}

// Unique ID untuk penamaan file
$unique_id = 'MRR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

$mysqli->begin_transaction();
try {
    // 1) Upload foto
    $photo_url = handle_photo_upload_receive('photo_bukti', $unique_id);

    // 2) Simpan record penerimaan
    $qty_gap = 0;
    $status_receive = 'Received';
    $stmt_ins = $mysqli->prepare(
        "INSERT INTO marpro_receives (user_id, branch_id, project_name, type_name, tanggal_terima, qty_branch, qty_allocated, diterima_siapa, latitude, longitude, photo_url, qty_admin, qty_gap, status_receive, tanggal_kirim)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt_ins) throw new Exception('Gagal menyiapkan simpan penerimaan: ' . $mysqli->error);
    $stmt_ins->bind_param('iisssisddsiiss', $user_id, $branch_id, $project_name, $type_name, $tanggal_terima, $qty_branch, $diterima_siapa, $latitude, $longitude, $photo_url, $qty_branch, $qty_gap, $status_receive, $tanggal_terima);
    if (!$stmt_ins->execute()) throw new Exception('Gagal menyimpan penerimaan: ' . $stmt_ins->error);
    $stmt_ins->close();

    // 3) Sinkronisasi matpro_stocks — tambah stok level Branch (micro_cluster_id = 0)
    // Cek apakah record stok sudah ada
    $stmt_check = $mysqli->prepare(
        "SELECT id FROM matpro_stocks WHERE user_id = ? AND project_name = ? AND type_name = ? AND branch_id = ? AND (micro_cluster_id IS NULL OR micro_cluster_id = 0) LIMIT 1 FOR UPDATE"
    );
    if (!$stmt_check) throw new Exception('Gagal menyiapkan cek stok: ' . $mysqli->error);
    $stmt_check->bind_param('issi', $user_id, $project_name, $type_name, $branch_id);
    $stmt_check->execute();
    $existing_stock = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();

    if ($existing_stock) {
        // Update stok yang ada
        $stmt_upd = $mysqli->prepare(
            "UPDATE matpro_stocks SET stock_quantity = stock_quantity + ?, is_active = 1, last_updated_by = ? WHERE id = ?"
        );
        if (!$stmt_upd) throw new Exception('Gagal menyiapkan update stok: ' . $mysqli->error);
        $stmt_upd->bind_param('iii', $qty_branch, $user_id, $existing_stock['id']);
        if (!$stmt_upd->execute()) throw new Exception('Gagal update stok: ' . $stmt_upd->error);
        $stmt_upd->close();
    } else {
        // Insert record stok baru
        $mc_null = null;
        $stmt_ins_stk = $mysqli->prepare(
            "INSERT INTO matpro_stocks (user_id, project_name, project_brand, type_name, branch_id, micro_cluster_id, stock_quantity, is_active, last_updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)"
        );
        if (!$stmt_ins_stk) throw new Exception('Gagal menyiapkan insert stok: ' . $mysqli->error);
        $stmt_ins_stk->bind_param('isssiiii', $user_id, $project_name, $project_brand, $type_name, $branch_id, $mc_null, $qty_branch, $user_id);
        if (!$stmt_ins_stk->execute()) throw new Exception('Gagal insert stok: ' . $stmt_ins_stk->error);
        $stmt_ins_stk->close();
    }

    $mysqli->commit();
    $_SESSION['success_message'] = "Penerimaan Marpro berhasil dicatat! Stok Branch bertambah {$qty_branch} Pcs untuk {$type_name}.";
    header('location: ../user_marpro_receive.php');
    exit;

} catch (Exception $e) {
    $mysqli->rollback();
    error_log('[marpro_receive] ' . $e->getMessage());
    $_SESSION['error_message'] = 'Terjadi kesalahan: ' . $e->getMessage();
    header('location: ../user_marpro_receive.php');
    exit;
}
?>
