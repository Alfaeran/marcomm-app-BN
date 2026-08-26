<?php
// process/user_marpro_allocate_process.php
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Guard login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] ?? '') !== 'user') {
    $_SESSION['error_message'] = 'Akses ditolak.';
    header('location: ../login.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('location: ../user_marpro_allocate.php');
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

function handle_photo_upload_alloc($file_input_name, $unique_id) {
    if (!isset($_FILES[$file_input_name]) || $_FILES[$file_input_name]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File foto bukti wajib diupload.');
    }
    $file       = $_FILES[$file_input_name];
    $target_dir = dirname(__DIR__) . '/uploads/matpro/allocations/';
    if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
    $allowed = ['image/jpeg', 'image/png', 'image/gif'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) throw new Exception('Tipe file tidak valid. Gunakan JPEG/PNG/GIF.');
    if ($file['size'] > 7 * 1024 * 1024) throw new Exception('Ukuran file foto melebihi batas 7MB.');

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new  = $unique_id . '_' . time() . '.' . $ext;
    $path = $target_dir . $new;
    if (!move_uploaded_file($file['tmp_name'], $path)) throw new Exception('Gagal menyimpan file foto.');
    if (!compress_image($path, $path, 80)) { @unlink($path); throw new Exception('Gagal mengompres gambar.'); }
    return 'uploads/matpro/allocations/' . $new;
}

// Ambil data sesi
$user_id    = (int)($_SESSION['id'] ?? 0);
$branch_id  = (int)($_SESSION['branch_id'] ?? 0);

if ($user_id <= 0 || $branch_id <= 0) {
    $_SESSION['error_message'] = 'Sesi tidak valid. Silakan login ulang.';
    header('location: ../login.php');
    exit;
}

// Ambil & validasi input form
$receive_id        = (int)($_POST['receive_id'] ?? 0);
$micro_cluster_id  = (int)($_POST['micro_cluster_id'] ?? 0);
$tanggal_alokasi   = trim($_POST['tanggal_alokasi'] ?? '');
$qty_pcs           = (int)($_POST['qty_pcs'] ?? 0);
$latitude          = trim($_POST['latitude'] ?? '');
$longitude         = trim($_POST['longitude'] ?? '');

if ($receive_id <= 0 || $micro_cluster_id <= 0 || empty($tanggal_alokasi) || $qty_pcs <= 0 || empty($latitude) || empty($longitude)) {
    $_SESSION['error_message'] = 'Data tidak lengkap. Pastikan semua kolom terisi dan GPS aktif.';
    header('location: ../user_marpro_allocate.php');
    exit;
}

if (!is_numeric($latitude) || !is_numeric($longitude)) {
    $_SESSION['error_message'] = 'Koordinat GPS tidak valid.';
    header('location: ../user_marpro_allocate.php');
    exit;
}

$unique_id = 'MRA-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

$mysqli->begin_transaction();
try {
    // 1) Upload foto
    $photo_url = handle_photo_upload_alloc('photo_bukti', $unique_id);

    // 2) Kunci & validasi data penerimaan (FOR UPDATE untuk concurrency-safe)
    $stmt_lock = $mysqli->prepare(
        "SELECT id, qty_branch, qty_allocated, project_name, type_name
         FROM marpro_receives
         WHERE id = ? AND branch_id = ?
         FOR UPDATE"
    );
    if (!$stmt_lock) throw new Exception('Gagal menyiapkan validasi penerimaan: ' . $mysqli->error);
    $stmt_lock->bind_param('ii', $receive_id, $branch_id);
    $stmt_lock->execute();
    $receive_data = $stmt_lock->get_result()->fetch_assoc();
    $stmt_lock->close();

    if (!$receive_data) throw new Exception('Data penerimaan tidak ditemukan atau tidak milik Branch ini.');

    $sisa = (int)$receive_data['qty_branch'] - (int)$receive_data['qty_allocated'];
    if ($qty_pcs > $sisa) {
        throw new Exception("QTY alokasi ({$qty_pcs}) melebihi sisa stok penerimaan ({$sisa} Pcs).");
    }
    if ($qty_pcs <= 0) throw new Exception('QTY alokasi harus lebih dari 0.');

    $project_name  = $receive_data['project_name'];
    $type_name     = $receive_data['type_name'];

    // Ambil brand dari proyek dari matpro_stocks
    $project_brand = '';
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

    // 3) Validasi micro_cluster milik branch ini
    $stmt_mc = $mysqli->prepare("SELECT id FROM micro_clusters WHERE id = ? AND branch_id = ?");
    if (!$stmt_mc) throw new Exception('Gagal validasi Micro Cluster.');
    $stmt_mc->bind_param('ii', $micro_cluster_id, $branch_id);
    $stmt_mc->execute();
    if (!$stmt_mc->get_result()->fetch_assoc()) throw new Exception('Micro Cluster tidak valid atau bukan milik Branch ini.');
    $stmt_mc->close();

    // 4) Simpan record alokasi
    $stmt_ins = $mysqli->prepare(
        "INSERT INTO marpro_allocations (receive_id, user_id, micro_cluster_id, tanggal_alokasi, qty_pcs, latitude, longitude, photo_url)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt_ins) throw new Exception('Gagal menyiapkan simpan alokasi: ' . $mysqli->error);
    $stmt_ins->bind_param('iiiisdds', $receive_id, $user_id, $micro_cluster_id, $tanggal_alokasi, $qty_pcs, $latitude, $longitude, $photo_url);
    if (!$stmt_ins->execute()) throw new Exception('Gagal menyimpan alokasi: ' . $stmt_ins->error);
    $stmt_ins->close();

    // 5) Update qty_allocated di marpro_receives
    $stmt_upd_recv = $mysqli->prepare("UPDATE marpro_receives SET qty_allocated = qty_allocated + ? WHERE id = ?");
    if (!$stmt_upd_recv) throw new Exception('Gagal menyiapkan update penerimaan.');
    $stmt_upd_recv->bind_param('ii', $qty_pcs, $receive_id);
    if (!$stmt_upd_recv->execute()) throw new Exception('Gagal update data penerimaan.');
    $stmt_upd_recv->close();

    // 6) KURANGI stok level Branch (micro_cluster_id NULL/0)
    $stmt_branch_stk = $mysqli->prepare(
        "SELECT id, stock_quantity FROM matpro_stocks
         WHERE user_id = ? AND project_name = ? AND type_name = ? AND branch_id = ?
           AND (micro_cluster_id IS NULL OR micro_cluster_id = 0) AND is_active = 1
         ORDER BY id ASC FOR UPDATE"
    );
    if (!$stmt_branch_stk) throw new Exception('Gagal menyiapkan cek stok branch.');
    $stmt_branch_stk->bind_param('issi', $user_id, $project_name, $type_name, $branch_id);
    $stmt_branch_stk->execute();
    $branch_rows = $stmt_branch_stk->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_branch_stk->close();

    $total_branch_stock = array_sum(array_column($branch_rows, 'stock_quantity'));
    if ($total_branch_stock < $qty_pcs) {
        throw new Exception("Stok Branch tidak mencukupi (tersedia: {$total_branch_stock} Pcs, diminta: {$qty_pcs} Pcs). Silakan hubungi Admin.");
    }

    // Kurangi FIFO
    $remain = $qty_pcs;
    foreach ($branch_rows as $brow) {
        if ($remain <= 0) break;
        $take    = min($remain, (int)$brow['stock_quantity']);
        $new_qty = (int)$brow['stock_quantity'] - $take;
        $active  = ($new_qty > 0) ? 1 : 0;
        $upd     = $mysqli->prepare("UPDATE matpro_stocks SET stock_quantity = stock_quantity - ?, is_active = ?, last_updated_by = ? WHERE id = ?");
        if (!$upd) throw new Exception('Gagal menyiapkan kurangi stok branch.');
        $upd->bind_param('iiii', $take, $active, $user_id, $brow['id']);
        if (!$upd->execute()) throw new Exception('Gagal kurangi stok branch.');
        $upd->close();
        $remain -= $take;
    }

    // 7) TAMBAH stok level MC (micro_cluster_id = MC_ID)
    $stmt_mc_stk = $mysqli->prepare(
        "SELECT id FROM matpro_stocks
         WHERE user_id = ? AND project_name = ? AND type_name = ? AND branch_id = ? AND micro_cluster_id = ?
         LIMIT 1 FOR UPDATE"
    );
    if (!$stmt_mc_stk) throw new Exception('Gagal menyiapkan cek stok MC.');
    $stmt_mc_stk->bind_param('issii', $user_id, $project_name, $type_name, $branch_id, $micro_cluster_id);
    $stmt_mc_stk->execute();
    $mc_existing = $stmt_mc_stk->get_result()->fetch_assoc();
    $stmt_mc_stk->close();

    if ($mc_existing) {
        $upd_mc = $mysqli->prepare("UPDATE matpro_stocks SET stock_quantity = stock_quantity + ?, is_active = 1, last_updated_by = ? WHERE id = ?");
        if (!$upd_mc) throw new Exception('Gagal menyiapkan update stok MC.');
        $upd_mc->bind_param('iii', $qty_pcs, $user_id, $mc_existing['id']);
        if (!$upd_mc->execute()) throw new Exception('Gagal update stok MC.');
        $upd_mc->close();
    } else {
        $ins_mc = $mysqli->prepare(
            "INSERT INTO matpro_stocks (user_id, project_name, project_brand, type_name, branch_id, micro_cluster_id, stock_quantity, is_active, last_updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)"
        );
        if (!$ins_mc) throw new Exception('Gagal menyiapkan insert stok MC.');
        $ins_mc->bind_param('isssiiii', $user_id, $project_name, $project_brand, $type_name, $branch_id, $micro_cluster_id, $qty_pcs, $user_id);
        if (!$ins_mc->execute()) throw new Exception('Gagal insert stok MC.');
        $ins_mc->close();
    }

    $mysqli->commit();
    $_SESSION['success_message'] = "Alokasi berhasil! {$qty_pcs} Pcs {$type_name} telah didistribusikan ke Micro Cluster.";
    header('location: ../user_marpro_allocate.php');
    exit;

} catch (Exception $e) {
    $mysqli->rollback();
    error_log('[marpro_allocate] ' . $e->getMessage());
    $_SESSION['error_message'] = 'Terjadi kesalahan: ' . $e->getMessage();
    header('location: ../user_marpro_allocate.php');
    exit;
}
?>
