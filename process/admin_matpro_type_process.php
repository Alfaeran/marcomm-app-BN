<?php
// process/admin_matpro_type_process.php
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
                $type_name = trim($_POST['type_name']);
                $project_id = (int)$_POST['project_id'];
                $is_active = (int)$_POST['is_active'];

                if (empty($type_name) || empty($project_id)) {
                    throw new Exception("Nama Jenis Matpro dan Proyek tidak boleh kosong.");
                }

                // Periksa apakah project_id valid dan aktif
                $stmt_check_project = $mysqli->prepare("SELECT id, brand FROM matpro_projects WHERE id = ? AND is_active = 1"); // Ambil brand juga
                if (!$stmt_check_project) {
                    throw new Exception("Gagal menyiapkan statement cek proyek: " . $mysqli->error);
                }
                $stmt_check_project->bind_param("i", $project_id);
                $stmt_check_project->execute();
                $result_check_project = $stmt_check_project->get_result();
                if ($result_check_project->num_rows === 0) {
                    throw new Exception("Proyek Matpro tidak ditemukan atau tidak aktif.");
                }
                $project_brand = $result_check_project->fetch_assoc()['brand']; // Ambil brand proyek
                $stmt_check_project->close();

                $sql = "INSERT INTO matpro_types (type_name, project_id, is_active) VALUES (?, ?, ?)";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                }
                $stmt->bind_param("sii", $type_name, $project_id, $is_active);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_TYPE_ADD', "Menambahkan jenis Matpro baru: $type_name (Proyek ID: $project_id, Brand Proyek: $project_brand)");
                $_SESSION['success_message'] = "Jenis Matpro baru berhasil ditambahkan.";
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $type_name = trim($_POST['type_name']);
                $project_id = (int)$_POST['project_id'];
                $is_active = (int)$_POST['is_active'];

                if (empty($id) || empty($type_name) || empty($project_id)) {
                    throw new Exception("ID, Nama Jenis Matpro, dan Proyek tidak boleh kosong.");
                }

                // Periksa apakah project_id valid dan aktif
                $stmt_check_project = $mysqli->prepare("SELECT id, brand FROM matpro_projects WHERE id = ? AND is_active = 1"); // Ambil brand juga
                if (!$stmt_check_project) {
                    throw new Exception("Gagal menyiapkan statement cek proyek: " . $mysqli->error);
                }
                $stmt_check_project->bind_param("i", $project_id);
                $stmt_check_project->execute();
                $result_check_project = $stmt_check_project->get_result();
                if ($result_check_project->num_rows === 0) {
                    throw new Exception("Proyek Matpro tidak ditemukan atau tidak aktif.");
                }
                $project_brand = $result_check_project->fetch_assoc()['brand']; // Ambil brand proyek
                $stmt_check_project->close();

                $sql = "UPDATE matpro_types SET type_name = ?, project_id = ?, is_active = ? WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                }
                $stmt->bind_param("siii", $type_name, $project_id, $is_active, $id);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_TYPE_EDIT', "Mengedit jenis Matpro: $type_name (ID: $id, Proyek ID: $project_id, Brand Proyek: $project_brand)");
                $_SESSION['success_message'] = "Jenis Matpro berhasil diperbarui.";
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                if (empty($id)) {
                    throw new Exception("ID Jenis Matpro tidak valid.");
                }

                // Cek apakah ada stok yang terkait
                $stmt_check_stocks = $mysqli->prepare("SELECT COUNT(id) FROM matpro_stocks WHERE type_id = ?");
                $stmt_check_stocks->bind_param("i", $id);
                $stmt_check_stocks->execute();
                $stocks_count = $stmt_check_stocks->get_result()->fetch_row()[0];
                $stmt_check_stocks->close();
                
                // Cek apakah ada aktivitas matpro yang terkait
                $stmt_check_activities = $mysqli->prepare("SELECT COUNT(id) FROM matpro_activities WHERE type_id = ?");
                $stmt_check_activities->bind_param("i", $id);
                $stmt_check_activities->execute();
                $activities_count = $stmt_check_activities->get_result()->fetch_row()[0];
                $stmt_check_activities->close();


                if ($stocks_count > 0 || $activities_count > 0) {
                    throw new Exception("Tidak dapat menghapus jenis Matpro. Terdapat " . $stocks_count . " stok dan " . $activities_count . " aktivitas terkait. Harap hapus data terkait terlebih dahulu.");
                }

                $sql = "DELETE FROM matpro_types WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                }
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_TYPE_DELETE', "Menghapus jenis Matpro (ID: $id)");
                $_SESSION['success_message'] = "Jenis Matpro berhasil dihapus.";
                break;

            case 'bulk_delete':
                $type_ids = $_POST['type_ids'] ?? [];
                if (empty($type_ids)) {
                    throw new Exception("Tidak ada jenis Matpro yang dipilih untuk dihapus.");
                }

                $ids_to_delete = array_map('intval', $type_ids);
                $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
                $types_string = str_repeat('i', count($ids_to_delete));

                // Cek keterkaitan stok dan aktivitas sebelum menghapus massal
                $stmt_check_stocks_bulk = $mysqli->prepare("SELECT COUNT(id) FROM matpro_stocks WHERE type_id IN ($placeholders)");
                $stmt_check_stocks_bulk->bind_param($types_string, ...$ids_to_delete);
                $stmt_check_stocks_bulk->execute();
                $stocks_count_bulk = $stmt_check_stocks_bulk->get_result()->fetch_row()[0];
                $stmt_check_stocks_bulk->close();

                $stmt_check_activities_bulk = $mysqli->prepare("SELECT COUNT(id) FROM matpro_activities WHERE type_id IN ($placeholders)");
                $stmt_check_activities_bulk->bind_param($types_string, ...$ids_to_delete);
                $stmt_check_activities_bulk->execute();
                $activities_count_bulk = $stmt_check_activities_bulk->get_result()->fetch_row()[0];
                $stmt_check_activities_bulk->close();


                if ($stocks_count_bulk > 0 || $activities_count_bulk > 0) {
                    throw new Exception("Tidak dapat menghapus jenis Matpro yang dipilih. Terdapat stok atau aktivitas terkait. Harap hapus data terkait terlebih dahulu.");
                }

                $sql_bulk_delete = "DELETE FROM matpro_types WHERE id IN ($placeholders)";
                $stmt_bulk_delete = $mysqli->prepare($sql_bulk_delete);
                if (!$stmt_bulk_delete) {
                    throw new Exception("Gagal menyiapkan statement bulk delete: " . $mysqli->error);
                }
                $stmt_bulk_delete->bind_param($types_string, ...$ids_to_delete);
                $stmt_bulk_delete->execute();
                
                $deleted_count = $stmt_bulk_delete->affected_rows;
                $stmt_bulk_delete->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_TYPE_BULK_DELETE', "Menghapus $deleted_count jenis Matpro secara massal.");
                $_SESSION['success_message'] = "$deleted_count jenis Matpro berhasil dihapus.";
                break;

            default:
                throw new Exception("Aksi tidak valid.");
        }
        
        $mysqli->commit();

    } catch (Exception $e) {
        $mysqli->rollback();
        // Tangani error duplikat type_name per project_id
        if ($mysqli->errno == 1062) {
            $_SESSION['error_message'] = "Gagal! Nama jenis '" . htmlspecialchars($type_name) . "' sudah ada untuk proyek yang dipilih.";
        } else {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
    
    header("location: ../admin_manage_matpro_types.php");
    exit();
} else {
    header("location: ../admin_manage_matpro_types.php");
    exit();
}
?>
