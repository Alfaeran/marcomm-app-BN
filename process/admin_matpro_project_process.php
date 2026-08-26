<?php
// process/admin_matpro_project_process.php
require_once '../config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
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
                $project_name = trim($_POST['project_name']);
                $brand = trim($_POST['brand']);
                $is_active = (int)$_POST['is_active'];

                if (empty($project_name) || empty($brand)) {
                    throw new Exception("Nama Proyek dan Brand tidak boleh kosong.");
                }
                // START PERBAIKAN: Tambahkan 'BOTH' sebagai brand yang valid
                if (!in_array($brand, ['IM3', '3ID', 'BOTH'])) {
                    throw new Exception("Brand tidak valid. Harus 'IM3', '3ID', atau 'BOTH'.");
                }
                // END PERBAIKAN

                $sql = "INSERT INTO matpro_projects (project_name, brand, is_active) VALUES (?, ?, ?)";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                }
                $stmt->bind_param("ssi", $project_name, $brand, $is_active);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_PROJECT_ADD', "Menambahkan proyek Matpro baru: $project_name ($brand)");
                $_SESSION['success_message'] = "Proyek Matpro baru berhasil ditambahkan.";
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $project_name = trim($_POST['project_name']);
                $brand = trim($_POST['brand']);
                $is_active = (int)$_POST['is_active'];

                if (empty($id) || empty($project_name) || empty($brand)) {
                    throw new Exception("ID, Nama Proyek, dan Brand tidak boleh kosong.");
                }
                // START PERBAIKAN: Tambahkan 'BOTH' sebagai brand yang valid
                if (!in_array($brand, ['IM3', '3ID', 'BOTH'])) {
                    throw new Exception("Brand tidak valid. Harus 'IM3', '3ID', atau 'BOTH'.");
                }
                // END PERBAIKAN

                $sql = "UPDATE matpro_projects SET project_name = ?, brand = ?, is_active = ? WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                }
                $stmt->bind_param("ssii", $project_name, $brand, $is_active, $id);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_PROJECT_EDIT', "Mengedit proyek Matpro: $project_name (ID: $id)");
                $_SESSION['success_message'] = "Proyek Matpro berhasil diperbarui.";
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                if (empty($id)) {
                    throw new Exception("ID Proyek tidak valid.");
                }

                // Cek apakah ada jenis matpro atau stok yang terkait
                $stmt_check_types = $mysqli->prepare("SELECT COUNT(id) FROM matpro_types WHERE project_id = ?");
                $stmt_check_types->bind_param("i", $id);
                $stmt_check_types->execute();
                $types_count = $stmt_check_types->get_result()->fetch_row()[0];
                $stmt_check_types->close();

                $stmt_check_stocks = $mysqli->prepare("SELECT COUNT(id) FROM matpro_stocks WHERE project_id = ?");
                $stmt_check_stocks->bind_param("i", $id);
                $stmt_check_stocks->execute();
                $stocks_count = $stmt_check_stocks->get_result()->fetch_row()[0];
                $stmt_check_stocks->close();

                if ($types_count > 0 || $stocks_count > 0) {
                    throw new Exception("Tidak dapat menghapus proyek. Terdapat " . $types_count . " jenis Matpro dan " . $stocks_count . " stok terkait. Harap hapus data terkait terlebih dahulu.");
                }

                $sql = "DELETE FROM matpro_projects WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                }
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_PROJECT_DELETE', "Menghapus proyek Matpro (ID: $id)");
                $_SESSION['success_message'] = "Proyek Matpro berhasil dihapus.";
                break;

            case 'bulk_delete':
                $project_ids = $_POST['project_ids'] ?? [];
                if (empty($project_ids)) {
                    throw new Exception("Tidak ada proyek yang dipilih untuk dihapus.");
                }

                $ids_to_delete = array_map('intval', $project_ids);
                $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
                $types_string = str_repeat('i', count($ids_to_delete));

                // Cek keterkaitan sebelum menghapus massal
                $stmt_check_types_bulk = $mysqli->prepare("SELECT COUNT(id) FROM matpro_types WHERE project_id IN ($placeholders)");
                $stmt_check_types_bulk->bind_param($types_string, ...$ids_to_delete);
                $stmt_check_types_bulk->execute();
                $types_count_bulk = $stmt_check_types_bulk->get_result()->fetch_row()[0];
                $stmt_check_types_bulk->close();

                $stmt_check_stocks_bulk = $mysqli->prepare("SELECT COUNT(id) FROM matpro_stocks WHERE project_id IN ($placeholders)");
                $stmt_check_stocks_bulk->bind_param($types_string, ...$ids_to_delete);
                $stmt_check_stocks_bulk->execute();
                $stocks_count_bulk = $stmt_check_stocks_bulk->get_result()->fetch_row()[0];
                $stmt_check_stocks_bulk->close();

                if ($types_count_bulk > 0 || $stocks_count_bulk > 0) {
                    throw new Exception("Tidak dapat menghapus proyek yang dipilih. Terdapat jenis Matpro atau stok terkait. Harap hapus data terkait terlebih dahulu.");
                }

                $sql_bulk_delete = "DELETE FROM matpro_projects WHERE id IN ($placeholders)";
                $stmt_bulk_delete = $mysqli->prepare($sql_bulk_delete);
                if (!$stmt_bulk_delete) {
                    throw new Exception("Gagal menyiapkan statement bulk delete: " . $mysqli->error);
                }
                $stmt_bulk_delete->bind_param($types_string, ...$ids_to_delete);
                $stmt_bulk_delete->execute();
                
                $deleted_count = $stmt_bulk_delete->affected_rows;
                $stmt_bulk_delete->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_PROJECT_BULK_DELETE', "Menghapus $deleted_count proyek Matpro secara massal.");
                $_SESSION['success_message'] = "$deleted_count proyek Matpro berhasil dihapus.";
                break;

            default:
                throw new Exception("Aksi tidak valid.");
        }
        
        $mysqli->commit();

    } catch (Exception $e) {
        $mysqli->rollback();
        // Tangani error duplikat project_name
        if ($mysqli->errno == 1062) {
            $_SESSION['error_message'] = "Gagal! Nama proyek '" . htmlspecialchars($project_name) . "' sudah ada.";
        } else {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
    
    header("location: ../admin_manage_matpro_projects.php");
    exit();
} else {
    header("location: ../admin_manage_matpro_projects.php");
    exit();
}
?>
