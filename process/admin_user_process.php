<?php
require_once '../config/database.php';

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
                $nama = trim($_POST['nama']);
                $username = trim($_POST['username']);
                $password = trim($_POST['password']);
                $role = $_POST['role'];
                $brand = !empty($_POST['brand']) ? $_POST['brand'] : null;
                $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
                $micro_cluster_ids = $_POST['micro_cluster_ids'] ?? [];

                if (empty($nama) || empty($username) || empty($password) || empty($role)) {
                    throw new Exception("Nama, username, password, dan role wajib diisi.");
                }
                
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $sql_user = "INSERT INTO users (nama, username, password, role, force_password_change, brand, branch_id) VALUES (?, ?, ?, ?, 1, ?, ?)";
                $stmt_user = $mysqli->prepare($sql_user);
                $stmt_user->bind_param("sssssi", $nama, $username, $hashed_password, $role, $brand, $branch_id);
                $stmt_user->execute();
                $user_id = $mysqli->insert_id;
                
                if ($user_id > 0 && !empty($micro_cluster_ids)) {
                    $sql_mc = "INSERT INTO user_micro_clusters (user_id, micro_cluster_id) VALUES (?, ?)";
                    $stmt_mc = $mysqli->prepare($sql_mc);
                    foreach ($micro_cluster_ids as $mc_id) {
                        $stmt_mc->bind_param("ii", $user_id, $mc_id);
                        $stmt_mc->execute();
                    }
                }
                
                log_activity($mysqli, $admin_id, $admin_username, 'USER_ADD', "Menambahkan user baru: $username");
                $_SESSION['success_message'] = "User baru berhasil ditambahkan.";
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $nama = trim($_POST['nama']);
                $username = trim($_POST['username']);
                $password = trim($_POST['password']);
                $role = $_POST['role'];
                $brand = !empty($_POST['brand']) ? $_POST['brand'] : null;
                $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
                $micro_cluster_ids = $_POST['micro_cluster_ids'] ?? [];

                if (empty($nama) || empty($username) || empty($role) || empty($id)) {
                    throw new Exception("Nama, username, dan role tidak boleh kosong.");
                }

                if (!empty($password)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET nama=?, username=?, password=?, role=?, brand=?, branch_id=?, force_password_change=1 WHERE id=?";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("sssssii", $nama, $username, $hashed_password, $role, $brand, $branch_id, $id);
                } else {
                    $sql = "UPDATE users SET nama=?, username=?, role=?, brand=?, branch_id=? WHERE id=?";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("ssssii", $nama, $username, $role, $brand, $branch_id, $id);
                }
                $stmt->execute();
                
                // Hapus relasi MC yang lama
                $stmt_delete_mc = $mysqli->prepare("DELETE FROM user_micro_clusters WHERE user_id = ?");
                $stmt_delete_mc->bind_param("i", $id);
                $stmt_delete_mc->execute();
                
                // Masukkan relasi MC yang baru
                if (!empty($micro_cluster_ids)) {
                    $sql_mc = "INSERT INTO user_micro_clusters (user_id, micro_cluster_id) VALUES (?, ?)";
                    $stmt_mc = $mysqli->prepare($sql_mc);
                    foreach ($micro_cluster_ids as $mc_id) {
                        $stmt_mc->bind_param("ii", $id, $mc_id);
                        $stmt_mc->execute();
                    }
                }

                log_activity($mysqli, $admin_id, $admin_username, 'USER_EDIT', "Mengedit data user: $username (ID: $id)");
                $_SESSION['success_message'] = "Data user berhasil diperbarui.";
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                if (empty($id)) throw new Exception("ID User tidak valid.");
                if ($id === $admin_id) throw new Exception("Anda tidak dapat menghapus akun Anda sendiri.");
                
                // Ambil username untuk log sebelum dihapus
                $stmt_get = $mysqli->prepare("SELECT username FROM users WHERE id=?");
                $stmt_get->bind_param("i", $id);
                $stmt_get->execute();
                $deleted_username = $stmt_get->get_result()->fetch_assoc()['username'] ?? 'N/A';

                $sql = "DELETE FROM users WHERE id = ? AND role != 'admin'";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();

                log_activity($mysqli, $admin_id, $admin_username, 'USER_DELETE', "Menghapus user: $deleted_username (ID: $id)");
                $_SESSION['success_message'] = "User berhasil dihapus.";
                break;
            
            case 'bulk_delete':
                $user_ids = $_POST['user_ids'] ?? [];
                if (empty($user_ids)) {
                    throw new Exception("Tidak ada user yang dipilih untuk dihapus.");
                }

                // Pastikan semua ID adalah integer
                $ids_to_delete = array_map('intval', $user_ids);
                
                // Pastikan admin tidak bisa menghapus dirinya sendiri
                if (($key = array_search($admin_id, $ids_to_delete)) !== false) {
                    unset($ids_to_delete[$key]);
                }

                if (empty($ids_to_delete)) {
                    throw new Exception("Aksi tidak valid (misal: mencoba menghapus diri sendiri).");
                }

                $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
                $sql = "DELETE FROM users WHERE id IN ($placeholders) AND role != 'admin'";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param(str_repeat('i', count($ids_to_delete)), ...$ids_to_delete);
                $stmt->execute();
                
                $deleted_count = $stmt->affected_rows;
                log_activity($mysqli, $admin_id, $admin_username, 'USER_BULK_DELETE', "Menghapus $deleted_count user secara massal.");
                $_SESSION['success_message'] = "$deleted_count user berhasil dihapus.";
                break;
        }
        
        $mysqli->commit();
        header("location: ../admin_manage_users.php");
        exit();

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['error_message'] = $e->getMessage();
        header("location: ../admin_manage_users.php");
        exit();
    }
}
?>
