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
            $rowIndex = $row->getRowIndex();
            $nama = trim($sheet->getCell('A' . $rowIndex)->getValue());
            $username = trim($sheet->getCell('B' . $rowIndex)->getValue());
            $password = trim($sheet->getCell('C' . $rowIndex)->getValue());
            $role = trim($sheet->getCell('D' . $rowIndex)->getValue());
            $brand = trim($sheet->getCell('E' . $rowIndex)->getValue());
            $nama_branch = trim($sheet->getCell('F' . $rowIndex)->getValue());
            $mc_list_string = trim($sheet->getCell('G' . $rowIndex)->getValue());

            if (empty($nama) || empty($username) || empty($password)) continue;

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $brand_val = !empty($brand) ? $brand : null;
            $branch_id = null;
            if (!empty($nama_branch) && !empty($brand)) {
                $stmt_b = $mysqli->prepare("SELECT id FROM branches WHERE nama_branch = ? AND brand = ?");
                $stmt_b->bind_param("ss", $nama_branch, $brand);
                $stmt_b->execute();
                $res_b = $stmt_b->get_result();
                if ($res_b->num_rows > 0) {
                    $branch_id = $res_b->fetch_assoc()['id'];
                } else {
                    throw new Exception("Error baris $rowIndex: Branch '$nama_branch' dengan brand '$brand' tidak ditemukan.");
                }
            }

            $sql_user = "INSERT INTO users (nama, username, password, role, brand, branch_id) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_user = $mysqli->prepare($sql_user);
            $stmt_user->bind_param("sssssi", $nama, $username, $hashed_password, $role, $brand_val, $branch_id);
            $stmt_user->execute();
            $user_id = $mysqli->insert_id;

            if ($user_id > 0 && !empty($mc_list_string) && $branch_id) {
                $mc_names = array_map('trim', explode(',', $mc_list_string));
                $placeholders = implode(',', array_fill(0, count($mc_names), '?'));
                $stmt_mc_lookup = $mysqli->prepare("SELECT id FROM micro_clusters WHERE nama_micro_cluster IN ($placeholders) AND branch_id = ?");
                $types = str_repeat('s', count($mc_names)) . 'i';
                $params = array_merge($mc_names, [$branch_id]);
                $stmt_mc_lookup->bind_param($types, ...$params);
                $stmt_mc_lookup->execute();
                $res_mc = $stmt_mc_lookup->get_result();
                
                $sql_mc_insert = "INSERT INTO user_micro_clusters (user_id, micro_cluster_id) VALUES (?, ?)";
                $stmt_mc_insert = $mysqli->prepare($sql_mc_insert);
                while ($mc_row = $res_mc->fetch_assoc()) {
                    $stmt_mc_insert->bind_param("ii", $user_id, $mc_row['id']);
                    $stmt_mc_insert->execute();
                }
            }
            $imported_count++;
        }

        $mysqli->commit();
        $_SESSION['success_message'] = "Sukses! " . $imported_count . " user berhasil diimpor.";

    } catch (Exception $e) {
        $mysqli->rollback();
        if ($mysqli->errno == 1062) {
            $_SESSION['error_message'] = "Gagal! Terdapat username duplikat di file Excel atau database.";
        } else {
            $_SESSION['error_message'] = $e->getMessage();
        }
    }
    
    header("location: ../admin_manage_users.php");
    exit();
}
?>
