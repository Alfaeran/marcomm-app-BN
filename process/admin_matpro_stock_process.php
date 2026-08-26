<?php
// process/admin_matpro_stock_process.php
require_once '../config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

// Redundant local functions removed. Using central functions from config/database.php

$admin_id = $_SESSION['id'];
$admin_username = $_SESSION['username'];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $mysqli->begin_transaction();

    try {
        switch ($action) {
            case 'add':
                $project_id = (int)$_POST['project_id'];
                $type_id = (int)$_POST['type_id'];
                $branch_id = (int)$_POST['branch_id'];
                $micro_cluster_id = !empty($_POST['micro_cluster_id']) ? (int)$_POST['micro_cluster_id'] : null; 
                $stock_quantity = (int)$_POST['stock_quantity'];
                $user_id = (int)$_POST['user_id']; // User alokasi

                if (empty($project_id) || empty($type_id) || empty($branch_id) || empty($user_id)) {
                    throw new Exception("User, Proyek, Jenis, dan Branch tidak boleh kosong.");
                }

                // FIX: Look up names and brand based on IDs to ensure data consistency.
                $stmt_proj = $mysqli->prepare("SELECT project_name, brand FROM matpro_projects WHERE id = ?");
                $stmt_proj->bind_param("i", $project_id);
                $stmt_proj->execute();
                $proj_data = $stmt_proj->get_result()->fetch_assoc();
                $stmt_proj->close();
                if (!$proj_data) throw new Exception("Proyek tidak ditemukan.");
                $project_name = $proj_data['project_name'];
                $project_brand = $proj_data['brand'];

                $stmt_type = $mysqli->prepare("SELECT type_name FROM matpro_types WHERE id = ?");
                $stmt_type->bind_param("i", $type_id);
                $stmt_type->execute();
                $type_data = $stmt_type->get_result()->fetch_assoc();
                $stmt_type->close();
                if (!$type_data) throw new Exception("Jenis Matpro tidak ditemukan.");
                $type_name = $type_data['type_name'];

                // Cek apakah kombinasi stok sudah ada
                $sql_check_existing = "SELECT id FROM matpro_stocks WHERE user_id = ? AND project_id = ? AND type_id = ? AND branch_id = ?";
                $check_types = "iiii";
                $check_values = [$user_id, $project_id, $type_id, $branch_id];

                if ($micro_cluster_id !== null) {
                    $sql_check_existing .= " AND micro_cluster_id = ?";
                    $check_types .= "i";
                    $check_values[] = $micro_cluster_id;
                } else {
                    $sql_check_existing .= " AND micro_cluster_id IS NULL";
                }
                
                $stmt_check_existing = $mysqli->prepare($sql_check_existing);
                if (!$stmt_check_existing) { throw new Exception("Gagal menyiapkan statement cek stok existing: " . $mysqli->error); }
                call_user_func_array([$stmt_check_existing, 'bind_param'], array_merge([$check_types], ref_values($check_values)));
                $stmt_check_existing->execute();
                $result_existing = $stmt_check_existing->get_result();
                if ($result_existing->num_rows > 0) {
                    throw new Exception("Stok untuk kombinasi User, Proyek, Jenis, Branch, dan Micro Cluster ini sudah ada. Gunakan fungsi Edit.");
                }
                $stmt_check_existing->close();

                // FIX: Insert all columns, including the redundant text-based ones, for consistency.
                $sql_insert = "INSERT INTO matpro_stocks (user_id, project_id, project_name, project_brand, type_id, type_name, branch_id, micro_cluster_id, stock_quantity, last_updated_by, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                $stmt_insert = $mysqli->prepare($sql_insert);
                if (!$stmt_insert) { throw new Exception("Gagal menyiapkan statement insert stok: " . $mysqli->error); }
                
                $stmt_insert->bind_param("iisssisiii", $user_id, $project_id, $project_name, $project_brand, $type_id, $type_name, $branch_id, $micro_cluster_id, $stock_quantity, $admin_id);
                $stmt_insert->execute();
                $stmt_insert->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_STOCK_ADD', "Menambahkan stok Matpro baru (Proyek: {$project_name}, Jenis: {$type_name}, Qty: {$stock_quantity})");
                $_SESSION['success_message'] = "Stok Matpro baru berhasil ditambahkan.";
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $stock_quantity = (int)$_POST['stock_quantity'];

                if (empty($id)) {
                    throw new Exception("ID Stok tidak valid.");
                }
                
                // FIX: Simplified edit. Only quantity can be edited from this simplified modal.
                // The more complex `admin_manage_matpro.php` handles changing project/type etc.
                $sql_update = "UPDATE matpro_stocks SET stock_quantity = ?, last_updated_by = ?, last_updated_at = NOW() WHERE id = ?";
                $stmt_update = $mysqli->prepare($sql_update);
                if (!$stmt_update) { throw new Exception("Gagal menyiapkan statement update stok: " . $mysqli->error); }
                
                $stmt_update->bind_param("iii", $stock_quantity, $admin_id, $id);
                $stmt_update->execute();
                $stmt_update->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_STOCK_EDIT', "Mengedit jumlah stok Matpro (ID: {$id}, Qty Baru: {$stock_quantity})");
                $_SESSION['success_message'] = "Jumlah stok Matpro berhasil diperbarui.";
                break;

            case 'delete':
            case 'bulk_delete':
                // FIX: This action is now a "soft delete" by setting is_active = 0.
                // This is safer than hard deleting and checking for related activities.
                $ids_to_process = ($action === 'delete') ? [$_POST['id']] : ($_POST['stock_ids'] ?? []);
                if (empty($ids_to_process)) {
                    throw new Exception("Tidak ada stok yang dipilih.");
                }
                
                $ids_to_deactivate = array_map('intval', $ids_to_process);
                $placeholders = implode(',', array_fill(0, count($ids_to_deactivate), '?'));
                $types = 'i' . str_repeat('i', count($ids_to_deactivate));
                
                $sql = "UPDATE matpro_stocks SET is_active = 0, last_updated_by = ? WHERE id IN ($placeholders)";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) throw new Exception("Gagal menyiapkan statement nonaktifkan stok: " . $mysqli->error);

                $params = array_merge([$admin_id], $ids_to_deactivate);
                call_user_func_array([$stmt, 'bind_param'], array_merge([$types], ref_values($params)));
                
                $stmt->execute();
                $deactivated_count = $stmt->affected_rows;
                $stmt->close();

                log_activity($mysqli, $admin_id, $admin_username, 'MATPRO_STOCK_DEACTIVATE', "Menonaktifkan {$deactivated_count} stok Matpro.");
                $_SESSION['success_message'] = "{$deactivated_count} stok Matpro berhasil dinonaktifkan.";
                break;

            default:
                throw new Exception("Aksi tidak valid.");
        }
        
        $mysqli->commit();

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    
    // FIX: Redirect to the correct management page
    header("location: ../admin_manage_matpro.php");
    exit();
} else {
    header("location: ../admin_manage_matpro.php");
    exit();
}
