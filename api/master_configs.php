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

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] ?? '') !== 'admin') {
    send_json_error('Akses ditolak.', 403);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// --- EVENT CATEGORIES ---
if ($action === 'get_event_categories') {
    $result = $mysqli->query("SELECT id, nama_kategori FROM event_categories ORDER BY nama_kategori");
    echo json_encode(['data' => $result->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

if ($action === 'save_event_category' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    $nama = $mysqli->real_escape_string($data['nama_kategori'] ?? '');
    if (empty($nama)) send_json_error('Nama kategori wajib diisi.');
    
    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE event_categories SET nama_kategori=? WHERE id=?");
        $stmt->bind_param('si', $nama, $id);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO event_categories (nama_kategori) VALUES (?)");
        $stmt->bind_param('s', $nama);
    }
    
    if ($stmt->execute()) echo json_encode(['success' => true]);
    else send_json_error('Gagal menyimpan.');
    $stmt->close();
    exit;
}

if ($action === 'delete_event_category' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    if ($mysqli->query("DELETE FROM event_categories WHERE id=$id")) echo json_encode(['success' => true]);
    else send_json_error('Gagal menghapus.');
    exit;
}


// --- MATPRO TYPES ---
if ($action === 'get_matpro_types') {
    $result = $mysqli->query("SELECT id, nama_tipe FROM matpro_types ORDER BY nama_tipe");
    echo json_encode(['data' => $result->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

if ($action === 'save_matpro_type' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    $nama = $mysqli->real_escape_string($data['nama_tipe'] ?? '');
    if (empty($nama)) send_json_error('Nama tipe wajib diisi.');
    
    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE matpro_types SET nama_tipe=? WHERE id=?");
        $stmt->bind_param('si', $nama, $id);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO matpro_types (nama_tipe) VALUES (?)");
        $stmt->bind_param('s', $nama);
    }
    
    if ($stmt->execute()) echo json_encode(['success' => true]);
    else send_json_error('Gagal menyimpan.');
    $stmt->close();
    exit;
}

if ($action === 'delete_matpro_type' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    if ($mysqli->query("DELETE FROM matpro_types WHERE id=$id")) echo json_encode(['success' => true]);
    else send_json_error('Gagal menghapus.');
    exit;
}


// --- MATPRO PROJECTS ---
if ($action === 'get_matpro_projects') {
    $result = $mysqli->query("SELECT id, project_name FROM matpro_projects ORDER BY project_name");
    echo json_encode(['data' => $result->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

if ($action === 'save_matpro_project' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    $nama = $mysqli->real_escape_string($data['project_name'] ?? '');
    if (empty($nama)) send_json_error('Nama project wajib diisi.');
    
    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE matpro_projects SET project_name=? WHERE id=?");
        $stmt->bind_param('si', $nama, $id);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO matpro_projects (project_name) VALUES (?)");
        $stmt->bind_param('s', $nama);
    }
    
    if ($stmt->execute()) echo json_encode(['success' => true]);
    else send_json_error('Gagal menyimpan.');
    $stmt->close();
    exit;
}

if ($action === 'delete_matpro_project' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    if ($mysqli->query("DELETE FROM matpro_projects WHERE id=$id")) echo json_encode(['success' => true]);
    else send_json_error('Gagal menghapus.');
    exit;
}


// --- MATPRO STOCKS ---
if ($action === 'get_matpro_stocks') {
    $result = $mysqli->query("
        SELECT ms.*, b.nama_branch, p.project_name, t.nama_tipe 
        FROM matpro_stocks ms
        LEFT JOIN branches b ON ms.branch_id = b.id
        LEFT JOIN matpro_projects p ON ms.project_id = p.id
        LEFT JOIN matpro_types t ON ms.matpro_type_id = t.id
        ORDER BY b.nama_branch, ms.id DESC
        LIMIT 500
    ");
    echo json_encode(['data' => $result->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

if ($action === 'save_matpro_stock' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    $branch_id = (int)($data['branch_id'] ?? 0);
    $project_id = (int)($data['project_id'] ?? 0);
    $matpro_type_id = (int)($data['matpro_type_id'] ?? 0);
    $total_stock = (int)($data['total_stock'] ?? 0);
    $stock_terpakai = (int)($data['stock_terpakai'] ?? 0);
    $remaining_stock = $total_stock - $stock_terpakai;
    $keterangan = $mysqli->real_escape_string($data['keterangan'] ?? '');

    if ($branch_id <= 0 || $project_id <= 0 || $matpro_type_id <= 0) {
        send_json_error('Data branch, project, dan tipe wajib dipilih.');
    }

    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE matpro_stocks SET branch_id=?, project_id=?, matpro_type_id=?, total_stock=?, stock_terpakai=?, remaining_stock=?, keterangan=? WHERE id=?");
        $stmt->bind_param('iiiiissi', $branch_id, $project_id, $matpro_type_id, $total_stock, $stock_terpakai, $remaining_stock, $keterangan, $id);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO matpro_stocks (branch_id, project_id, matpro_type_id, total_stock, stock_terpakai, remaining_stock, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiiiiss', $branch_id, $project_id, $matpro_type_id, $total_stock, $stock_terpakai, $remaining_stock, $keterangan);
    }
    
    if ($stmt->execute()) echo json_encode(['success' => true]);
    else send_json_error('Gagal menyimpan.');
    $stmt->close();
    exit;
}

if ($action === 'delete_matpro_stock' && $method === 'POST') {
    $id = (int)($data['id'] ?? 0);
    if ($mysqli->query("DELETE FROM matpro_stocks WHERE id=$id")) echo json_encode(['success' => true]);
    else send_json_error('Gagal menghapus.');
    exit;
}

// IMPORT STOCKS
if ($action === 'import_stocks' && $method === 'POST') {
    if (!isset($_FILES['file_import'])) send_json_error('Tidak ada file Excel.');
    
    $file = $_FILES['file_import']['tmp_name'];
    $imported_count = 0;
    
    $mysqli->begin_transaction();
    try {
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        
        foreach ($sheet->getRowIterator(2) as $row) {
            $idx = $row->getRowIndex();
            $nama_branch = trim($sheet->getCell('A' . $idx)->getValue() ?? '');
            $project_name = trim($sheet->getCell('B' . $idx)->getValue() ?? '');
            $nama_tipe = trim($sheet->getCell('C' . $idx)->getValue() ?? '');
            $total_stock = (int)($sheet->getCell('D' . $idx)->getValue() ?? 0);
            $stock_terpakai = (int)($sheet->getCell('E' . $idx)->getValue() ?? 0);
            $remaining = $total_stock - $stock_terpakai;
            $keterangan = trim($sheet->getCell('F' . $idx)->getValue() ?? '');
            
            if (empty($nama_branch) || empty($project_name) || empty($nama_tipe)) continue;
            
            // Get IDs
            $branch_id = $mysqli->query("SELECT id FROM branches WHERE nama_branch='$nama_branch'")->fetch_assoc()['id'] ?? null;
            $project_id = $mysqli->query("SELECT id FROM matpro_projects WHERE project_name='$project_name'")->fetch_assoc()['id'] ?? null;
            $tipe_id = $mysqli->query("SELECT id FROM matpro_types WHERE nama_tipe='$nama_tipe'")->fetch_assoc()['id'] ?? null;
            
            if (!$branch_id || !$project_id || !$tipe_id) continue;
            
            $stmt = $mysqli->prepare("INSERT INTO matpro_stocks (branch_id, project_id, matpro_type_id, total_stock, stock_terpakai, remaining_stock, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iiiiiss', $branch_id, $project_id, $tipe_id, $total_stock, $stock_terpakai, $remaining, $keterangan);
            $stmt->execute();
            if ($stmt->affected_rows > 0) $imported_count++;
            $stmt->close();
        }
        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => "$imported_count stok berhasil diimpor."]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json_error($e->getMessage());
    }
    exit;
}

send_json_error('Aksi tidak valid.');
