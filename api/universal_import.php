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

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_FILES['file_import'])) {
    send_json_error('Permintaan tidak valid.');
}

$type = $_POST['type'] ?? '';
$file = $_FILES['file_import']['tmp_name'];
$imported_count = 0;

$mysqli->begin_transaction();

try {
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    
    if ($type === 'branches') {
        foreach ($sheet->getRowIterator(2) as $row) {
            $nama_branch = trim($sheet->getCell('A' . $row->getRowIndex())->getValue() ?? '');
            $brand = trim($sheet->getCell('B' . $row->getRowIndex())->getValue() ?? '');
            if (empty($nama_branch) || empty($brand)) continue;
            
            $stmt = $mysqli->prepare("INSERT IGNORE INTO branches (nama_branch, brand) VALUES (?, ?)");
            $stmt->bind_param("ss", $nama_branch, $brand);
            $stmt->execute();
            if ($stmt->affected_rows > 0) $imported_count++;
            $stmt->close();
        }
    } 
    elseif ($type === 'micro_clusters') {
        foreach ($sheet->getRowIterator(2) as $row) {
            $nama_branch = trim($sheet->getCell('A' . $row->getRowIndex())->getValue() ?? '');
            $brand = trim($sheet->getCell('B' . $row->getRowIndex())->getValue() ?? '');
            $nama_mc = trim($sheet->getCell('C' . $row->getRowIndex())->getValue() ?? '');
            
            if (empty($nama_branch) || empty($nama_mc)) continue;
            
            // Find branch
            $stmt = $mysqli->prepare("SELECT id FROM branches WHERE nama_branch=? AND brand=?");
            $stmt->bind_param("ss", $nama_branch, $brand);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows === 0) throw new Exception("Branch $nama_branch ($brand) tidak ditemukan di baris " . $row->getRowIndex());
            $branch_id = $res->fetch_assoc()['id'];
            $stmt->close();
            
            $stmt = $mysqli->prepare("INSERT IGNORE INTO micro_clusters (branch_id, nama_micro_cluster) VALUES (?, ?)");
            $stmt->bind_param("is", $branch_id, $nama_mc);
            $stmt->execute();
            if ($stmt->affected_rows > 0) $imported_count++;
            $stmt->close();
        }
    }
    elseif ($type === 'sites') {
        foreach ($sheet->getRowIterator(2) as $row) {
            $idx = $row->getRowIndex();
            $nama_branch = trim($sheet->getCell('A' . $idx)->getValue() ?? '');
            $nama_mc = trim($sheet->getCell('B' . $idx)->getValue() ?? '');
            $site_id = trim($sheet->getCell('C' . $idx)->getValue() ?? '');
            $site_name = trim($sheet->getCell('D' . $idx)->getValue() ?? '');
            $area = trim($sheet->getCell('E' . $idx)->getValue() ?? '');
            
            if (empty($site_id) || empty($site_name)) continue;
            
            $branch_id = null;
            $mc_id = null;
            
            if (!empty($nama_branch)) {
                $stmt = $mysqli->prepare("SELECT id FROM branches WHERE nama_branch=?");
                $stmt->bind_param("s", $nama_branch);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) $branch_id = $res->fetch_assoc()['id'];
                $stmt->close();
            }
            
            if (!empty($nama_mc)) {
                $stmt = $mysqli->prepare("SELECT id FROM micro_clusters WHERE nama_micro_cluster=?");
                $stmt->bind_param("s", $nama_mc);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) $mc_id = $res->fetch_assoc()['id'];
                $stmt->close();
            }
            
            $stmt = $mysqli->prepare("INSERT IGNORE INTO sites (branch_id, micro_cluster_id, site_id, site_name, area) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisss", $branch_id, $mc_id, $site_id, $site_name, $area);
            $stmt->execute();
            if ($stmt->affected_rows > 0) $imported_count++;
            $stmt->close();
        }
    }
    elseif ($type === 'outlets') {
        foreach ($sheet->getRowIterator(2) as $row) {
            $idx = $row->getRowIndex();
            $site_id_str = trim($sheet->getCell('C' . $idx)->getValue() ?? '');
            $outlet_id = trim($sheet->getCell('D' . $idx)->getValue() ?? '');
            $nama_outlet = trim($sheet->getCell('E' . $idx)->getValue() ?? '');
            $tipe_outlet = trim($sheet->getCell('F' . $idx)->getValue() ?? '');
            $alamat = trim($sheet->getCell('G' . $idx)->getValue() ?? '');
            
            if (empty($outlet_id) || empty($nama_outlet)) continue;
            
            $site_id = null;
            if (!empty($site_id_str)) {
                $stmt = $mysqli->prepare("SELECT id FROM sites WHERE site_id=?");
                $stmt->bind_param("s", $site_id_str);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) $site_id = $res->fetch_assoc()['id'];
                $stmt->close();
            }
            
            $stmt = $mysqli->prepare("INSERT IGNORE INTO outlets (site_id, outlet_id, nama_outlet, tipe_outlet, alamat) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $site_id, $outlet_id, $nama_outlet, $tipe_outlet, $alamat);
            $stmt->execute();
            if ($stmt->affected_rows > 0) $imported_count++;
            $stmt->close();
        }
    }
    elseif ($type === 'stocks') {
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
    }
    else {
        throw new Exception("Tipe import '$type' tidak didukung.");
    }

    $mysqli->commit();
    echo json_encode(['success' => true, 'message' => "$imported_count data berhasil diimpor."]);

} catch (Exception $e) {
    $mysqli->rollback();
    send_json_error('Error: ' . $e->getMessage());
}
