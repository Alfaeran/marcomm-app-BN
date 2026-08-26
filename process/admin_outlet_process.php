<?php
// process/admin_outlet_process.php
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
                $id_outlet = trim($_POST['id_outlet']);
                $nama_outlet = trim($_POST['nama_outlet']);
                $site_id = (int)$_POST['site_id'];
                $brand = trim($_POST['brand']);

                if (empty($id_outlet) || empty($nama_outlet) || empty($site_id) || empty($brand)) {
                    throw new Exception("ID Outlet, Nama Outlet, Site, dan Brand tidak boleh kosong.");
                }

                // Buat nilai untuk kolom Id_Outlet_Nama_Outlet
                $id_outlet_nama_outlet = $id_outlet . ' | ' . $nama_outlet;

                // Menyesuaikan INSERT statement sesuai struktur DB yang dikonfirmasi
                // Tanpa organization_id dan organization_name
                $sql = "INSERT INTO outlets (id_outlet, nama_outlet, Id_Outlet_Nama_Outlet, site_id, brand) VALUES (?, ?, ?, ?, ?)";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan statement add outlet: " . $mysqli->error);
                }
                $stmt->bind_param("sssis", $id_outlet, $nama_outlet, $id_outlet_nama_outlet, $site_id, $brand);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'OUTLET_ADD', "Menambahkan outlet baru: {$nama_outlet} (ID: {$id_outlet})");
                $_SESSION['success_message'] = "Outlet baru berhasil ditambahkan.";
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $id_outlet = trim($_POST['id_outlet']);
                $nama_outlet = trim($_POST['nama_outlet']);
                $site_id = (int)$_POST['site_id'];
                $brand = trim($_POST['brand']);

                if (empty($id) || empty($id_outlet) || empty($nama_outlet) || empty($site_id) || empty($brand)) {
                    throw new Exception("ID, ID Outlet, Nama Outlet, Site, dan Brand tidak boleh kosong.");
                }

                // Buat nilai untuk kolom Id_Outlet_Nama_Outlet
                $id_outlet_nama_outlet = $id_outlet . ' | ' . $nama_outlet;

                // Menyesuaikan UPDATE statement sesuai struktur DB yang dikonfirmasi
                // Tanpa organization_id dan organization_name
                $sql = "UPDATE outlets SET id_outlet = ?, nama_outlet = ?, Id_Outlet_Nama_Outlet = ?, site_id = ?, brand = ? WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan statement edit outlet: " . $mysqli->error);
                }
                $stmt->bind_param("sssiis", $id_outlet, $nama_outlet, $id_outlet_nama_outlet, $site_id, $brand, $id);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'OUTLET_EDIT', "Mengedit data outlet: {$nama_outlet} (ID: {$id_outlet})");
                $_SESSION['success_message'] = "Data outlet berhasil diperbarui.";
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                if (empty($id)) {
                    throw new Exception("ID Outlet tidak valid.");
                }

                // Cek keterkaitan dengan matpro_activities
                $stmt_check_activities = $mysqli->prepare("SELECT COUNT(id) FROM matpro_activities WHERE outlet_id = ?");
                $stmt_check_activities->bind_param("i", $id);
                $stmt_check_activities->execute();
                $activities_count = $stmt_check_activities->get_result()->fetch_row()[0];
                $stmt_check_activities->close();

                if ($activities_count > 0) {
                    throw new Exception("Tidak dapat menghapus outlet. Terdapat " . $activities_count . " aktivitas Matpro terkait. Harap hapus data terkait terlebih dahulu.");
                }

                $sql = "DELETE FROM outlets WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan statement delete outlet: " . $mysqli->error);
                }
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'OUTLET_DELETE', "Menghapus outlet (ID: {$id})");
                $_SESSION['success_message'] = "Outlet berhasil dihapus.";
                break;

            case 'bulk_delete':
                $outlet_ids = $_POST['outlet_ids'] ?? [];
                if (empty($outlet_ids)) {
                    throw new Exception("Tidak ada outlet yang dipilih untuk dihapus.");
                }

                $ids_to_delete = array_map('intval', $outlet_ids);
                $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
                $types_string = str_repeat('i', count($ids_to_delete));

                // Cek keterkaitan dengan matpro_activities sebelum menghapus massal
                $stmt_check_activities_bulk = $mysqli->prepare("SELECT COUNT(id) FROM matpro_activities WHERE outlet_id IN ($placeholders)");
                $stmt_check_activities_bulk->bind_param($types_string, ...$ids_to_delete);
                $stmt_check_activities_bulk->execute();
                $activities_count_bulk = $stmt_check_activities_bulk->get_result()->fetch_row()[0];
                $stmt_check_activities_bulk->close();

                if ($activities_count_bulk > 0) {
                    throw new Exception("Tidak dapat menghapus outlet yang dipilih. Terdapat aktivitas Matpro terkait. Harap hapus data terkait terlebih dahulu.");
                }

                $sql_bulk_delete = "DELETE FROM outlets WHERE id IN ($placeholders)";
                $stmt_bulk_delete = $mysqli->prepare($sql_bulk_delete);
                if (!$stmt_bulk_delete) {
                    throw new Exception("Gagal menyiapkan statement bulk delete outlet: " . $mysqli->error);
                }
                $stmt_bulk_delete->bind_param($types_string, ...$ids_to_delete);
                $stmt_bulk_delete->execute();
                
                $deleted_count = $stmt_bulk_delete->affected_rows;
                $stmt_bulk_delete->close();

                log_activity($mysqli, $admin_id, $admin_username, 'OUTLET_BULK_DELETE', "Menghapus {$deleted_count} outlet secara massal.");
                $_SESSION['success_message'] = "{$deleted_count} outlet berhasil dihapus.";
                break;

            case 'delete_all_outlets':
                // Hapus semua data dari tabel outlets
                // Perlu cek keterkaitan dengan matpro_activities terlebih dahulu
                $stmt_check_all_activities = $mysqli->query("SELECT COUNT(id) FROM matpro_activities");
                $all_activities_count = $stmt_check_all_activities->fetch_row()[0];
                if ($all_activities_count > 0) {
                    throw new Exception("Tidak dapat menghapus SEMUA outlet. Terdapat aktivitas Matpro terkait. Harap hapus data aktivitas Matpro terlebih dahulu.");
                }

                $mysqli->query("TRUNCATE TABLE outlets");
                log_activity($mysqli, $admin_id, $admin_username, 'OUTLET_CLEAR', 'Semua data outlet telah dihapus.');
                $_SESSION['success_message'] = "Semua data outlet berhasil dihapus.";
                break;

            default:
                throw new Exception("Aksi tidak valid.");
        }
        
        $mysqli->commit();

    } catch (Exception $e) {
        $mysqli->rollback();
        if ($mysqli->errno == 1062) { // Duplicate entry error
            $_SESSION['error_message'] = "Gagal! Terdapat ID Outlet duplikat.";
        } else {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
    
    header("location: ../admin_manage_outlets.php");
    exit();
} else {
    header("location: ../admin_manage_outlets.php");
    exit();
}
?>
