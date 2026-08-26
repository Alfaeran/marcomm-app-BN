<?php
require_once 'cors.php';
require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function send_json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'user') {
    send_json_error('Akses ditolak.', 403);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$user_id = $_SESSION['id'];
$branch_id = $_SESSION['branch_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

if ($action === 'get_receives' && $method === 'GET') {
    $sql = "SELECT mr.id, mr.tanggal_terima, mr.project_name, mr.type_name, mr.qty_branch, mr.qty_allocated, mr.diterima_siapa, mr.photo_url 
            FROM marpro_receives mr WHERE mr.branch_id = ? ORDER BY mr.tanggal_terima DESC, mr.id DESC LIMIT 25";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['data' => $data]);
    exit;
}

if ($action === 'get_allocations' && $method === 'GET') {
    $sql = "SELECT ma.id, ma.tanggal_alokasi, mr.project_name, mr.type_name, mc.nama_micro_cluster, ma.qty_pcs, ma.photo_url 
            FROM marpro_allocations ma
            JOIN marpro_receives mr ON ma.receive_id = mr.id
            JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
            WHERE ma.user_id = ? ORDER BY ma.tanggal_alokasi DESC, ma.id DESC LIMIT 25";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['data' => $data]);
    exit;
}

if ($action === 'submit_receive' && $method === 'POST') {
    try {
        $project_id = (int)($_POST['project_id'] ?? 0);
        $type_id = (int)($_POST['type_id'] ?? 0);
        $qty = (int)($_POST['qty_branch'] ?? 0);
        $tanggal = $_POST['tanggal_terima'] ?? date('Y-m-d');
        $diterima = trim($_POST['diterima_siapa'] ?? $username);
        $lat = (float)($_POST['latitude'] ?? 0);
        $lon = (float)($_POST['longitude'] ?? 0);
        
        if ($qty <= 0) throw new Exception("QTY harus > 0");
        
        $pname = $mysqli->query("SELECT nama_project FROM matpro_projects WHERE id = $project_id")->fetch_assoc()['nama_project'] ?? '';
        $tname = $mysqli->query("SELECT nama_tipe FROM matpro_types WHERE id = $type_id")->fetch_assoc()['nama_tipe'] ?? '';
        
        $foto_url = '';
        if (isset($_FILES['photo_bukti']) && $_FILES['photo_bukti']['error'] == 0) {
            $ext = pathinfo($_FILES['photo_bukti']['name'], PATHINFO_EXTENSION);
            $filename = 'recv_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['photo_bukti']['tmp_name'], '../uploads/' . $filename)) {
                $foto_url = 'uploads/' . $filename;
            }
        }
        
        $stmt = $mysqli->prepare("INSERT INTO marpro_receives (branch_id, project_id, type_id, project_name, type_name, qty_branch, tanggal_terima, diterima_siapa, photo_url, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiississsdd", $branch_id, $project_id, $type_id, $pname, $tname, $qty, $tanggal, $diterima, $foto_url, $lat, $lon);
        $stmt->execute();
        
        // Add to global matpro_stocks
        $mysqli->query("INSERT INTO matpro_stocks (branch_id, project_id, tipe_id, stock_masuk, stock_keluar, type_name) VALUES ($branch_id, $project_id, $type_id, $qty, 0, '$tname') ON DUPLICATE KEY UPDATE stock_masuk = stock_masuk + $qty");
        
        echo json_encode(['success' => true, 'message' => 'Penerimaan berhasil disimpan']);
    } catch (Exception $e) {
        send_json_error($e->getMessage());
    }
    exit;
}

if ($action === 'submit_allocation' && $method === 'POST') {
    $mysqli->begin_transaction();
    try {
        $receive_id = (int)($_POST['receive_id'] ?? 0);
        $mc_id = (int)($_POST['micro_cluster_id'] ?? 0);
        $qty = (int)($_POST['qty_pcs'] ?? 0);
        $tanggal = $_POST['tanggal_alokasi'] ?? date('Y-m-d');
        $lat = (float)($_POST['latitude'] ?? 0);
        $lon = (float)($_POST['longitude'] ?? 0);
        
        if ($qty <= 0) throw new Exception("QTY harus > 0");
        
        $res = $mysqli->query("SELECT qty_branch, qty_allocated, project_id, type_id, type_name FROM marpro_receives WHERE id = $receive_id AND branch_id = $branch_id FOR UPDATE");
        $receive = $res->fetch_assoc();
        if (!$receive) throw new Exception("Data penerimaan tidak valid");
        
        $sisa = $receive['qty_branch'] - $receive['qty_allocated'];
        if ($qty > $sisa) throw new Exception("QTY melebihi sisa penerimaan ($sisa)");
        
        $foto_url = '';
        if (isset($_FILES['photo_bukti']) && $_FILES['photo_bukti']['error'] == 0) {
            $ext = pathinfo($_FILES['photo_bukti']['name'], PATHINFO_EXTENSION);
            $filename = 'alloc_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['photo_bukti']['tmp_name'], '../uploads/' . $filename)) {
                $foto_url = 'uploads/' . $filename;
            }
        }
        
        $stmt = $mysqli->prepare("INSERT INTO marpro_allocations (receive_id, user_id, micro_cluster_id, qty_pcs, tanggal_alokasi, photo_url, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiissdd", $receive_id, $user_id, $mc_id, $qty, $tanggal, $foto_url, $lat, $lon);
        $stmt->execute();
        
        $mysqli->query("UPDATE marpro_receives SET qty_allocated = qty_allocated + $qty WHERE id = $receive_id");
        
        $pid = $receive['project_id']; $tid = $receive['type_id'];
        $mysqli->query("UPDATE matpro_stocks SET stock_keluar = stock_keluar + $qty WHERE branch_id = $branch_id AND project_id = $pid AND tipe_id = $tid");
        
        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Alokasi berhasil disimpan']);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json_error($e->getMessage());
    }
    exit;
}

send_json_error('Aksi tidak valid.');
