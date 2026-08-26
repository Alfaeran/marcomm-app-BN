<?php
// process/admin_matpro_activity_process.php
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
            case 'delete':
                $id = (int)$_POST['id'];
                if (empty($id)) {
                    throw new Exception("ID Aktivitas Matpro tidak valid.");
                }

                // Ambil detail aktivitas untuk mengembalikan stok
                // PERBAIKAN: Hanya mengambil project_name dan type_name (bukan project_id, type_id)
                $stmt_get_activity = $mysqli->prepare("SELECT project_name, type_name, branch_id, micro_cluster_id, qty_used, photo_before_url, photo_after_url FROM matpro_activities WHERE id = ?");
                if (!$stmt_get_activity) {
                    throw new Exception("Gagal menyiapkan statement ambil detail aktivitas: " . $mysqli->error);
                }
                $stmt_get_activity->bind_param("i", $id);
                $stmt_get_activity->execute();
                $activity_detail = $stmt_get_activity->get_result()->fetch_assoc();
                $stmt_get_activity->close();

                if (!$activity_detail) {
                    throw new Exception("Aktivitas Matpro tidak ditemukan.");
                }

                $project_name = $activity_detail['project_name'];
                $type_name = $activity_detail['type_name'];
                $branch_id = $activity_detail['branch_id'];
                $micro_cluster_id = $activity_detail['micro_cluster_id'];
                $quantity_to_return = $activity_detail['qty_used'];

                // Kembalikan stok
                $stock_update_sql = "UPDATE matpro_stocks SET stock_quantity = stock_quantity + ?, last_updated_by = ?, last_updated_at = NOW() WHERE project_name = ? AND type_name = ? AND branch_id = ?";
                $stock_update_params = [$quantity_to_return, $admin_id, $project_name, $type_name, $branch_id];
                $stock_update_types = "iissi"; // int, int, string, string, int

                if ($micro_cluster_id !== null && $micro_cluster_id != 0) {
                    $stock_update_sql .= " AND micro_cluster_id = ?";
                    $stock_update_types .= "i";
                    $stock_update_params[] = $micro_cluster_id;
                } else {
                    $stock_update_sql .= " AND (micro_cluster_id IS NULL OR micro_cluster_id = 0)";
                }

                $stmt_update_stock = $mysqli->prepare($stock_update_sql);
                if (!$stmt_update_stock) {
                    throw new Exception("Gagal menyiapkan statement update stok: " . $mysqli->error);
                }
                call_user_func_array([$stmt_update_stock, 'bind_param'], array_merge([$stock_update_types], $stock_update_params));
                $stmt_update_stock->execute();
                $stmt_update_stock->close();

                // Hapus file foto terkait
                if (!empty($activity_detail['photo_before_url']) && file_exists('../' . $activity_detail['photo_before_url'])) {
                    unlink('../' . $activity_detail['photo_before_url']);
                }
                if (!empty($activity_detail['photo_after_url']) && file_exists('../' . $activity_detail['photo_after_url'])) {
                    unlink('../' . $activity_detail['photo_after_url']);
                }

                // Hapus aktivitas
                $sql = "DELETE FROM matpro_activities WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan statement delete aktivitas: " . $mysqli->error);
                }
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_ACTIVITY_DELETE', "Menghapus aktivitas Matpro (ID: $id) dan mengembalikan stok.");
                $_SESSION['success_message'] = "Aktivitas Matpro berhasil dihapus dan stok dikembalikan.";
                break;

            case 'bulk_delete':
                $activity_ids = $_POST['activity_ids'] ?? [];
                if (empty($activity_ids)) {
                    throw new Exception("Tidak ada aktivitas Matpro yang dipilih untuk dihapus.");
                }

                $ids_to_delete = array_map('intval', $activity_ids);
                $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
                $types_string = str_repeat('i', count($ids_to_delete));

                // Ambil detail semua aktivitas yang akan dihapus untuk mengembalikan stok
                // PERBAIKAN: Hanya mengambil project_name dan type_name (bukan project_id, type_id)
                $sql_get_activities = "SELECT id, project_name, type_name, branch_id, micro_cluster_id, qty_used, photo_before_url, photo_after_url FROM matpro_activities WHERE id IN ($placeholders)";
                $stmt_get_activities = $mysqli->prepare($sql_get_activities);
                if (!$stmt_get_activities) {
                    throw new Exception("Gagal menyiapkan statement ambil detail aktivitas massal: " . $mysqli->error);
                }
                $stmt_get_activities->bind_param($types_string, ...$ids_to_delete);
                $stmt_get_activities->execute();
                $activities_to_delete = $stmt_get_activities->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_get_activities->close();

                // Kembalikan stok untuk setiap aktivitas yang dihapus
                foreach ($activities_to_delete as $activity) {
                    $project_name = $activity['project_name'];
                    $type_name = $activity['type_name'];
                    $branch_id = $activity['branch_id'];
                    $micro_cluster_id = $activity['micro_cluster_id'];
                    $quantity_to_return = $activity['qty_used'];

                    // Kembalikan stok
                    $stock_update_sql = "UPDATE matpro_stocks SET stock_quantity = stock_quantity + ?, last_updated_by = ?, last_updated_at = NOW() WHERE project_name = ? AND type_name = ? AND branch_id = ?";
                    $stock_update_params = [$quantity_to_return, $admin_id, $project_name, $type_name, $branch_id];
                    $stock_update_types = "iissi";

                    if ($micro_cluster_id !== null && $micro_cluster_id != 0) {
                        $stock_update_sql .= " AND micro_cluster_id = ?";
                        $stock_update_types .= "i";
                        $stock_update_params[] = $micro_cluster_id;
                    } else {
                        $stock_update_sql .= " AND (micro_cluster_id IS NULL OR micro_cluster_id = 0)";
                    }

                    $stmt_update_stock = $mysqli->prepare($stock_update_sql);
                    if (!$stmt_update_stock) {
                        throw new Exception("Gagal menyiapkan statement update stok massal: " . $mysqli->error);
                    }
                    call_user_func_array([$stmt_update_stock, 'bind_param'], array_merge([$stock_update_types], $stock_update_params));
                    $stmt_update_stock->execute();
                    $stmt_update_stock->close();

                    // Hapus file foto terkait (opsional, tergantung kebijakan)
                    if (!empty($activity['photo_before_url']) && file_exists('../' . $activity['photo_before_url'])) {
                        unlink('../' . $activity['photo_before_url']);
                    }
                    if (!empty($activity['photo_after_url']) && file_exists('../' . $activity['photo_after_url'])) {
                        unlink('../' . $activity['photo_after_url']);
                    }
                }

                // Hapus aktivitas secara massal
                $sql_bulk_delete = "DELETE FROM matpro_activities WHERE id IN ($placeholders)";
                $stmt_bulk_delete = $mysqli->prepare($sql_bulk_delete);
                if (!$stmt_bulk_delete) {
                    throw new Exception("Gagal menyiapkan statement bulk delete aktivitas: " . $mysqli->error);
                }
                $stmt_bulk_delete->bind_param($types_string, ...$ids_to_delete);
                $stmt_bulk_delete->execute();

                $deleted_count = $stmt_bulk_delete->affected_rows;
                $stmt_bulk_delete->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_ACTIVITY_BULK_DELETE', "Menghapus $deleted_count aktivitas Matpro secara massal dan mengembalikan stok.");
                $_SESSION['success_message'] = "$deleted_count aktivitas Matpro berhasil dihapus dan stok dikembalikan.";
                break;

            default:
                throw new Exception("Aksi tidak valid.");
        }

        $mysqli->commit();

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }

    header("location: ../admin_laporan_matpro.php");
    exit();
} else {
    header("location: ../admin_laporan_matpro.php");
    exit();
}
