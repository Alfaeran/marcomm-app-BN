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
            $nama_branch = trim($sheet->getCell('A' . $row->getRowIndex())->getValue());
            $brand = trim($sheet->getCell('B' . $row->getRowIndex())->getValue());

            if (empty($nama_branch) || empty($brand)) {
                continue; // Lewati baris kosong
            }
            if ($brand !== 'IM3' && $brand !== '3ID') {
                throw new Exception("Error di baris " . $row->getRowIndex() . ": Brand '" . htmlspecialchars($brand) . "' tidak valid. Harus 'IM3' atau '3ID'.");
            }

            $sql = "INSERT INTO branches (nama_branch, brand) VALUES (?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ss", $nama_branch, $brand);
            $stmt->execute();
            $stmt->close();
            $imported_count++;
        }

        $mysqli->commit();
        $_SESSION['success_message'] = "Sukses! " . $imported_count . " data branch berhasil diimpor.";

    } catch (Exception $e) {
        $mysqli->rollback();
        if ($mysqli->errno == 1062) {
            $_SESSION['error_message'] = "Gagal! Terdapat nama branch dan brand yang duplikat di file Excel atau database.";
        } else {
            $_SESSION['error_message'] = $e->getMessage();
        }
    }
    
    header("location: ../admin_manage_branches.php");
    exit();
}
?>
