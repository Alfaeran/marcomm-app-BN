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

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] ?? '') !== 'admin') {
    send_json_error('Akses ditolak.', 403);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// --- BRANCHES ---
if ($action === 'get_branches') {
    $result = $mysqli->query("SELECT id, nama_branch, brand FROM branches ORDER BY brand, nama_branch");
    echo json_encode(['data' => $result->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

if ($action === 'save_branch' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    $nama_branch = $mysqli->real_escape_string($data['nama_branch'] ?? '');
    $brand = $mysqli->real_escape_string($data['brand'] ?? '');
    
    if (empty($nama_branch) || empty($brand)) send_json_error('Nama branch dan brand wajib diisi.');
    
    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE branches SET nama_branch=?, brand=? WHERE id=?");
        $stmt->bind_param('ssi', $nama_branch, $brand, $id);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO branches (nama_branch, brand) VALUES (?, ?)");
        $stmt->bind_param('ss', $nama_branch, $brand);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Branch berhasil disimpan.']);
    } else {
        send_json_error('Gagal menyimpan branch: ' . $stmt->error);
    }
    $stmt->close();
    exit;
}

if ($action === 'delete_branch' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) send_json_error('ID tidak valid.');
    
    if ($mysqli->query("DELETE FROM branches WHERE id=$id")) {
        echo json_encode(['success' => true, 'message' => 'Branch berhasil dihapus.']);
    } else {
        send_json_error('Gagal menghapus branch.');
    }
    exit;
}

// --- MICRO CLUSTERS ---
if ($action === 'get_micro_clusters') {
    $result = $mysqli->query("SELECT mc.id, mc.branch_id, mc.nama_micro_cluster, b.nama_branch, b.brand 
                              FROM micro_clusters mc 
                              JOIN branches b ON mc.branch_id = b.id 
                              ORDER BY b.nama_branch, mc.nama_micro_cluster");
    echo json_encode(['data' => $result->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

if ($action === 'save_micro_cluster' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    $branch_id = (int)($data['branch_id'] ?? 0);
    $nama = $mysqli->real_escape_string($data['nama_micro_cluster'] ?? '');
    
    if (empty($nama) || $branch_id <= 0) send_json_error('Data tidak lengkap.');
    
    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE micro_clusters SET branch_id=?, nama_micro_cluster=? WHERE id=?");
        $stmt->bind_param('isi', $branch_id, $nama, $id);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO micro_clusters (branch_id, nama_micro_cluster) VALUES (?, ?)");
        $stmt->bind_param('is', $branch_id, $nama);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Micro Cluster berhasil disimpan.']);
    } else {
        send_json_error('Gagal menyimpan: ' . $stmt->error);
    }
    $stmt->close();
    exit;
}

if ($action === 'delete_micro_cluster' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    if ($mysqli->query("DELETE FROM micro_clusters WHERE id=$id")) {
        echo json_encode(['success' => true, 'message' => 'Micro Cluster dihapus.']);
    } else {
        send_json_error('Gagal menghapus.');
    }
    exit;
}

// --- SITES ---
if ($action === 'get_sites') {
    $result = $mysqli->query("SELECT s.*, mc.nama_micro_cluster, b.nama_branch 
                              FROM sites s 
                              LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id
                              LEFT JOIN branches b ON s.branch_id = b.id 
                              ORDER BY b.nama_branch, s.site_name LIMIT 500"); // limit to avoid massive payloads if many
    echo json_encode(['data' => $result->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

if ($action === 'save_site' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    $branch_id = (int)($data['branch_id'] ?? 0);
    $mc_id = (int)($data['micro_cluster_id'] ?? 0);
    $site_id = $mysqli->real_escape_string($data['site_id'] ?? '');
    $site_name = $mysqli->real_escape_string($data['site_name'] ?? '');
    $area = $mysqli->real_escape_string($data['area'] ?? '');
    
    if (empty($site_id) || empty($site_name)) send_json_error('Site ID dan Site Name wajib diisi.');
    
    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE sites SET branch_id=?, micro_cluster_id=?, site_id=?, site_name=?, area=? WHERE id=?");
        $stmt->bind_param('iisssi', $branch_id, $mc_id, $site_id, $site_name, $area, $id);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO sites (branch_id, micro_cluster_id, site_id, site_name, area) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('iisss', $branch_id, $mc_id, $site_id, $site_name, $area);
    }
    
    if ($stmt->execute()) echo json_encode(['success' => true, 'message' => 'Site berhasil disimpan.']);
    else send_json_error('Gagal menyimpan: ' . $stmt->error);
    
    $stmt->close();
    exit;
}

if ($action === 'delete_site' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    if ($mysqli->query("DELETE FROM sites WHERE id=$id")) echo json_encode(['success' => true]);
    else send_json_error('Gagal menghapus.');
    exit;
}

// --- OUTLETS ---
if ($action === 'get_outlets') {
    $result = $mysqli->query("SELECT o.*, s.site_name, mc.nama_micro_cluster, b.nama_branch 
                              FROM outlets o 
                              LEFT JOIN sites s ON o.site_id = s.id
                              LEFT JOIN micro_clusters mc ON o.micro_cluster_id = mc.id
                              LEFT JOIN branches b ON o.branch_id = b.id 
                              ORDER BY b.nama_branch, o.nama_outlet LIMIT 500");
    echo json_encode(['data' => $result->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

if ($action === 'save_outlet' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    $branch_id = (int)($data['branch_id'] ?? 0);
    $mc_id = (int)($data['micro_cluster_id'] ?? 0);
    $site_id = (int)($data['site_id'] ?? 0);
    $outlet_id = $mysqli->real_escape_string($data['outlet_id'] ?? '');
    $nama_outlet = $mysqli->real_escape_string($data['nama_outlet'] ?? '');
    $tipe = $mysqli->real_escape_string($data['tipe_outlet'] ?? '');
    $address = $mysqli->real_escape_string($data['alamat'] ?? '');
    
    if (empty($outlet_id) || empty($nama_outlet)) send_json_error('Outlet ID dan Nama wajib diisi.');
    
    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE outlets SET branch_id=?, micro_cluster_id=?, site_id=?, outlet_id=?, nama_outlet=?, tipe_outlet=?, alamat=? WHERE id=?");
        $stmt->bind_param('iiissssi', $branch_id, $mc_id, $site_id, $outlet_id, $nama_outlet, $tipe, $address, $id);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO outlets (branch_id, micro_cluster_id, site_id, outlet_id, nama_outlet, tipe_outlet, alamat) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiissss', $branch_id, $mc_id, $site_id, $outlet_id, $nama_outlet, $tipe, $address);
    }
    
    if ($stmt->execute()) echo json_encode(['success' => true, 'message' => 'Outlet berhasil disimpan.']);
    else send_json_error('Gagal menyimpan: ' . $stmt->error);
    
    $stmt->close();
    exit;
}

if ($action === 'delete_outlet' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    if ($mysqli->query("DELETE FROM outlets WHERE id=$id")) echo json_encode(['success' => true]);
    else send_json_error('Gagal menghapus.');
    exit;
}

send_json_error('Aksi tidak valid.');
