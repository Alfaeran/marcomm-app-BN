<?php
// process/admin_site_process.php
require_once '../config/database.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_POST['action'])) {
    header("location: ../admin_manage_sites.php");
    exit;
}

$admin_id = $_SESSION['id'];
$admin_username = $_SESSION['username'];

$action = $_POST['action'];

$mysqli->begin_transaction();
try {
    switch ($action) {
        case 'add':
            $site_id_code = trim($_POST['site_id']);
            $site_name = trim($_POST['site_name']);
            $branch_id = (int)$_POST['branch_id'];
            $mc_id = (int)$_POST['micro_cluster_id'];
            $kecamatan = trim($_POST['kecamatan']);
            $kabupaten = trim($_POST['kabupaten']);
            $area = trim($_POST['area']);
            $region = trim($_POST['region']);

            if (empty($site_id_code) || empty($site_name) || empty($branch_id) || empty($mc_id)) {
                throw new Exception("Site ID, Site Name, Branch, dan Micro Cluster wajib diisi.");
            }

            // Cek duplikat
            $stmt_check = $mysqli->prepare("SELECT id FROM sites WHERE site_id = ?");
            $stmt_check->bind_param("s", $site_id_code);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                throw new Exception("Site ID '" . htmlspecialchars($site_id_code) . "' sudah ada di database.");
            }
            $stmt_check->close();

            $sql_insert = "INSERT INTO sites (site_id, site_name, micro_cluster_id, branch_id, kecamatan, kabupaten, area, region) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_insert = $mysqli->prepare($sql_insert);
            $stmt_insert->bind_param("ssiissss", $site_id_code, $site_name, $mc_id, $branch_id, $kecamatan, $kabupaten, $area, $region);
            $stmt_insert->execute();
            $stmt_insert->close();
            
            log_activity($mysqli, $admin_id, $admin_username, 'SITE_ADD', "Menambahkan site baru: {$site_name} (ID: {$site_id_code})");
            $_SESSION['success_message'] = "Site baru berhasil ditambahkan.";
            break;

        case 'edit':
            $id = (int)$_POST['id'];
            $site_name = trim($_POST['site_name']);
            $branch_id = (int)$_POST['branch_id'];
            $mc_id = (int)$_POST['micro_cluster_id'];
            $kecamatan = trim($_POST['kecamatan']);
            $kabupaten = trim($_POST['kabupaten']);
            $area = trim($_POST['area']);
            $region = trim($_POST['region']);

            if (empty($id) || empty($site_name) || empty($branch_id) || empty($mc_id)) {
                throw new Exception("ID, Site Name, Branch, dan Micro Cluster wajib diisi.");
            }

            $sql_update = "UPDATE sites SET site_name = ?, branch_id = ?, micro_cluster_id = ?, kecamatan = ?, kabupaten = ?, area = ?, region = ? WHERE id = ?";
            $stmt_update = $mysqli->prepare($sql_update);
            $stmt_update->bind_param("siissssi", $site_name, $branch_id, $mc_id, $kecamatan, $kabupaten, $area, $region, $id);
            $stmt_update->execute();
            $stmt_update->close();

            log_activity($mysqli, $admin_id, $admin_username, 'SITE_EDIT', "Mengedit data site: {$site_name} - ID: {$id}");
            $_SESSION['success_message'] = "Data site berhasil diperbarui.";
            break;

        case 'delete':
        case 'bulk_delete':
            if ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if (empty($id)) throw new Exception("ID site tidak valid.");
                $ids_to_delete = [$id];
            } else { // bulk_delete
                $site_ids = $_POST['site_ids'] ?? [];
                if (empty($site_ids)) throw new Exception("Tidak ada site yang dipilih.");
                $ids_to_delete = array_map('intval', $site_ids);
            }

            $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
            $types_string = str_repeat('i', count($ids_to_delete));

            $sql_check_outlets = "SELECT COUNT(id) as total FROM outlets WHERE site_id IN ($placeholders)";
            $stmt_check = $mysqli->prepare($sql_check_outlets);
            $stmt_check->bind_param($types_string, ...$ids_to_delete);
            $stmt_check->execute();
            $related_outlets_count = $stmt_check->get_result()->fetch_assoc()['total'];
            $stmt_check->close();

            if ($related_outlets_count > 0) {
                throw new Exception("Gagal menghapus. Terdapat " . $related_outlets_count . " outlet yang masih terhubung dengan site yang dipilih. Hapus outletnya terlebih dahulu.");
            }

            $sql_update_events = "UPDATE event_submissions SET site_id = NULL WHERE site_id IN ($placeholders)";
            $stmt_update = $mysqli->prepare($sql_update_events);
            $stmt_update->bind_param($types_string, ...$ids_to_delete);
            $stmt_update->execute();
            $stmt_update->close();

            $sql_delete = "DELETE FROM sites WHERE id IN ($placeholders)";
            $stmt_delete = $mysqli->prepare($sql_delete);
            $stmt_delete->bind_param($types_string, ...$ids_to_delete);
            $stmt_delete->execute();
            $deleted_count = $stmt_delete->affected_rows;
            $stmt_delete->close();

            log_activity($mysqli, $admin_id, $admin_username, 'SITE_DELETE', "Menghapus {$deleted_count} site (Action: {$action})");
            $_SESSION['success_message'] = "$deleted_count site berhasil dihapus permanen. Riwayat laporan event tetap aman.";
            break;

        case 'delete_all_sites':
            $result_check_all_outlets = $mysqli->query("SELECT id FROM outlets LIMIT 1");
            if ($result_check_all_outlets && $result_check_all_outlets->num_rows > 0) {
                throw new Exception("Gagal menghapus semua site. Masih ada data outlet di dalam sistem. Hapus semua outlet terlebih dahulu.");
            }

            $mysqli->query("UPDATE event_submissions SET site_id = NULL WHERE site_id IS NOT NULL");
            $mysqli->query("TRUNCATE TABLE sites");
            
            log_activity($mysqli, $admin_id, $admin_username, 'SITE_CLEAR', "Mengosongkan seluruh data site.");
            $_SESSION['success_message'] = "PERHATIAN: Semua data site telah berhasil dihapus secara permanen.";
            break;

        default:
            throw new Exception("Aksi tidak valid.");
    }

    $mysqli->commit();
} catch (Exception $e) {
    $mysqli->rollback();
    $_SESSION['error_message'] = "Terjadi kesalahan: " . $e->getMessage();
}

header("location: ../admin_manage_sites.php");
exit();
