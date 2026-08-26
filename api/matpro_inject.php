<?php
require_once 'cors.php';
require_once '../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');

function send_json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] ?? '') !== 'user') {
    send_json_error('Akses ditolak. Silakan login kembali.', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('Metode tidak diizinkan.', 405);
}

// Helper: kompres gambar
function compress_image($source, $destination, $quality) {
    $info = @getimagesize($source);
    if ($info === false) return false;
    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg': $img = imagecreatefromjpeg($source); break;
        case 'image/png':  
            $img = imagecreatefrompng($source);  
            imagepalettetotruecolor($img); 
            imagealphablending($img, true); 
            imagesavealpha($img, true); 
            break;
        case 'image/gif':  $img = imagecreatefromgif($source);  break;
        default: return false;
    }
    if (!$img) return false;
    if ($mime === 'image/png') {
        $png_q = 9 - (int)round(($quality/100)*9);
        return imagepng($img, $destination, $png_q);
    }
    return imagejpeg($img, $destination, max(0, min(100, (int)$quality)));
}

function handle_photo_upload($file_input_name, $unique_id) {
    if (!isset($_FILES[$file_input_name]) || $_FILES[$file_input_name]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File foto $file_input_name wajib diupload.");
    }
    $file = $_FILES[$file_input_name];
    $target_dir = dirname(__DIR__) . '/uploads/matpro/';
    if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
    
    $allowed = ['image/jpeg','image/png','image/gif'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) throw new Exception("Tipe file tidak valid untuk $file_input_name");
    if ($file['size'] > 7*1024*1024) throw new Exception("Ukuran file $file_input_name melebihi 7MB.");

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new  = $unique_id . '_' . $file_input_name . '_' . time() . '.' . $ext;
    $path = $target_dir . $new;
    
    if (!move_uploaded_file($file['tmp_name'], $path)) throw new Exception("Gagal memindahkan file upload: $file_input_name");
    if (!compress_image($path, $path, 80)) { 
        @unlink($path); 
        throw new Exception("Gagal kompres gambar: $file_input_name"); 
    }
    
    return 'uploads/matpro/' . $new;
}

$user_id   = (int)($_SESSION['id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
if ($user_id <= 0 || $branch_id <= 0) {
    send_json_error('Sesi tidak valid (user/branch).', 403);
}

$activity_datetime = trim($_POST['activity_datetime'] ?? date('Y-m-d H:i:s'));
$input_level       = trim($_POST['input_level'] ?? '');
$micro_cluster_id  = ($input_level === 'outlet' && !empty($_POST['micro_cluster_id'])) ? (int)$_POST['micro_cluster_id'] : null;
$site_id           = (int)($_POST['site_id'] ?? 0);
$outlet_id         = (int)($_POST['outlet_id'] ?? 0);
$latitude          = trim($_POST['location_latitude']  ?? '');
$longitude         = trim($_POST['location_longitude'] ?? '');
$project_name_form = trim($_POST['project_name'] ?? '');
$type_name_form    = trim($_POST['type_name']    ?? '');
$qty_used          = (int)($_POST['qty_used']    ?? 0);

if (!$site_id || !$outlet_id || $project_name_form === '' || $type_name_form === '' || $qty_used <= 0 || ($input_level !== 'branch' && $input_level !== 'outlet')) {
    send_json_error('Data wajib tidak lengkap. Mohon isi kolom yang diperlukan.', 400);
}

$outlet_snapshot_name = 'N/A';
if ($outlet_id) {
    $stmt = $mysqli->prepare('SELECT Id_Outlet_Nama_Outlet FROM outlets WHERE id = ? LIMIT 1');
    if ($stmt) { 
        $stmt->bind_param('i', $outlet_id); 
        $stmt->execute(); 
        $r = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
        if ($r) $outlet_snapshot_name = $r['Id_Outlet_Nama_Outlet']; 
    }
}

$unique_id = 'MATPRO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

$mysqli->begin_transaction();
try {
    $photo_before_url = handle_photo_upload('photo_before', $unique_id);
    $photo_after_url  = handle_photo_upload('photo_after',  $unique_id);

    $sql = "SELECT id, stock_quantity, project_name, type_name
            FROM matpro_stocks
            WHERE user_id = ?
              AND project_name = ?
              AND type_name = ?
              AND branch_id = ?
              AND is_active = 1
              AND " . ($micro_cluster_id !== null ? "micro_cluster_id = ?" : "(micro_cluster_id IS NULL OR micro_cluster_id = 0)") . "
            FOR UPDATE";

    if ($micro_cluster_id !== null) {
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('issii', $user_id, $project_name_form, $type_name_form, $branch_id, $micro_cluster_id);
    } else {
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('issi', $user_id, $project_name_form, $type_name_form, $branch_id);
    }

    $stmt->execute();
    $res  = $stmt->get_result();
    if ($res->num_rows === 0) throw new Exception('Stok tidak ditemukan untuk kombinasi yang dipilih.');
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $total_stock = 0; 
    foreach ($rows as $rr) { $total_stock += (int)$rr['stock_quantity']; }
    if ($total_stock < $qty_used) throw new Exception("Stok tidak mencukupi. Sisa: $total_stock");

    usort($rows, function($a,$b){ return $a['id'] <=> $b['id']; });
    $remain = $qty_used;
    foreach ($rows as $r) {
        if ($remain <= 0) break;
        $take = min($remain, (int)$r['stock_quantity']);
        if ($take > 0) {
            $new_row_qty = (int)$r['stock_quantity'] - $take;
            $deactivate  = ($new_row_qty <= 0) ? 0 : 1;
            $u = $mysqli->prepare('UPDATE matpro_stocks SET stock_quantity = stock_quantity - ?, is_active = ?, last_updated_by = ? WHERE id = ?');
            $u->bind_param('iiii', $take, $deactivate, $user_id, $r['id']);
            if (!$u->execute()) { $u->close(); throw new Exception('Gagal mengurangi stok.'); }
            $u->close();
            $remain -= $take;
        }
    }

    $project_id   = 0; 
    $type_id      = 0; 

    $sql_ins = "INSERT INTO matpro_activities
        (unique_id, user_id, activity_datetime, branch_id, micro_cluster_id, site_id, outlet_id, outlet_snapshot_name,
         project_name, type_name, project_id, type_id, qty_used, location_latitude, location_longitude, photo_before_url, photo_after_url)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    $stmt = $mysqli->prepare($sql_ins);
    $lat = ($latitude !== '') ? $latitude : null;
    $lon = ($longitude !== '') ? $longitude : null;
    
    $stmt->bind_param('sisiiiisssiiissss', 
        $unique_id, $user_id, $activity_datetime, $branch_id, $micro_cluster_id, 
        $site_id, $outlet_id, $outlet_snapshot_name, $project_name_form, $type_name_form, 
        $project_id, $type_id, $qty_used, $lat, $lon, $photo_before_url, $photo_after_url
    );

    if (!$stmt->execute()) { throw new Exception('Gagal menyimpan aktivitas: ' . $stmt->error); }
    $stmt->close();

    $mysqli->commit();
    echo json_encode(['success' => true, 'message' => "Aktivitas Matpro $unique_id berhasil disimpan."]);

} catch (Exception $e) {
    $mysqli->rollback();
    send_json_error($e->getMessage(), 500);
}
