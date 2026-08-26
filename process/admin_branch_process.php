<?php
// process/admin_branch_process.php
require_once '../config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

$admin_id = $_SESSION['id'];
$admin_username = $_SESSION['username'];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $mysqli->begin_transaction();

    try {
        switch ($action) {
            case 'add':
                $nama_branch = trim($_POST['nama_branch']);
                $brand = trim($_POST['brand']);

                if (empty($nama_branch) || empty($brand)) {
                    throw new Exception("Nama Branch dan Brand tidak boleh kosong.");
                }

                $sql = "INSERT INTO branches (nama_branch, brand) VALUES (?, ?)";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                
                $stmt->bind_param("ss", $nama_branch, $brand);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'BRANCH_ADD', "Menambahkan branch baru: {$nama_branch} ({$brand})");
                $_SESSION['success_message'] = "Branch baru berhasil ditambahkan.";
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $nama_branch = trim($_POST['nama_branch']);
                $brand = trim($_POST['brand']);

                if (empty($id) || empty($nama_branch) || empty($brand)) {
                    throw new Exception("ID, Nama Branch, dan Brand tidak boleh kosong.");
                }

                $sql = "UPDATE branches SET nama_branch = ?, brand = ? WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                
                $stmt->bind_param("ssi", $nama_branch, $brand, $id);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'BRANCH_EDIT', "Mengedit data branch: {$nama_branch} ({$brand}) - ID: {$id}");
                $_SESSION['success_message'] = "Data branch berhasil diperbarui.";
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                if (empty($id)) {
                    throw new Exception("ID Branch tidak valid.");
                }

                // Cek apakah ada Micro Cluster yang terhubung
                $stmt_check = $mysqli->prepare("SELECT COUNT(id) FROM micro_clusters WHERE branch_id = ?");
                $stmt_check->bind_param("i", $id);
                $stmt_check->execute();
                $mc_count = $stmt_check->get_result()->fetch_row()[0];
                $stmt_check->close();

                if ($mc_count > 0) {
                    throw new Exception("Gagal menghapus. Masih ada {$mc_count} Micro Cluster yang terhubung dengan branch ini.");
                }

                // Ambil info branch untuk log
                $stmt_info = $mysqli->prepare("SELECT nama_branch FROM branches WHERE id = ?");
                $stmt_info->bind_param("i", $id);
                $stmt_info->execute();
                $branch_name = $stmt_info->get_result()->fetch_assoc()['nama_branch'] ?? "ID: $id";
                $stmt_info->close();

                $sql = "DELETE FROM branches WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) throw new Exception("Gagal menyiapkan statement delete: " . $mysqli->error);
                
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'BRANCH_DELETE', "Menghapus branch: {$branch_name} (ID: {$id})");
                $_SESSION['success_message'] = "Branch berhasil dihapus.";
                break;

            default:
                throw new Exception("Aksi tidak valid.");
        }
        
        $mysqli->commit();

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("location: ../admin_manage_branches.php");
    exit();
} else {
    header("location: ../admin_manage_branches.php");
    exit();
}
?>
