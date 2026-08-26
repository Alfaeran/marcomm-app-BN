<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['file_import'])) {
    
    $file = $_FILES['file_import']['tmp_name'];
    $imported_count = 0;
    $mysqli->begin_transaction();

    try {
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        
        foreach ($sheet->getRowIterator(2) as $row) {
            $nama_mc = trim($sheet->getCell('A' . $row->getRowIndex())->getValue());
            $nama_branch = trim($sheet->getCell('B' . $row->getRowIndex())->getValue());
            $brand = trim($sheet->getCell('C' . $row->getRowIndex())->getValue());

            if (empty($nama_mc) || empty($nama_branch) || empty($brand)) {
                continue; // Lewati baris kosong
            }

            // Lookup branch_id berdasarkan nama dan brand
            $stmt_branch = $mysqli->prepare("SELECT id FROM branches WHERE nama_branch = ? AND brand = ?");
            $stmt_branch->bind_param("ss", $nama_branch, $brand);
            $stmt_branch->execute();
            $result_branch = $stmt_branch->get_result();
            if ($result_branch->num_rows === 0) {
                throw new Exception("Error di baris " . $row->getRowIndex() . ": Parent Branch '" . htmlspecialchars($nama_branch) . "' dengan brand '" . htmlspecialchars($brand) . "' tidak ditemukan.");
            }
            $branch_id = $result_branch->fetch_assoc()['id'];
            $stmt_branch->close();

            // Insert data micro cluster
            $sql = "INSERT INTO micro_clusters (nama_micro_cluster, branch_id, brand) VALUES (?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sis", $nama_mc, $branch_id, $brand);
            $stmt->execute();
            $stmt->close();
            $imported_count++;
        }

        $mysqli->commit();
        $_SESSION['success_message'] = "Sukses! " . $imported_count . " data micro cluster berhasil diimpor.";

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("location: ../admin_manage_micro_clusters.php");
    exit();
}
?>
