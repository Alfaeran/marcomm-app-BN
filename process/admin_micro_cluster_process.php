<?php
require_once '../config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $admin_id = $_SESSION['id'];
    $admin_username = $_SESSION['username'];

    try {
        switch ($action) {
            case 'add':
                $nama_mc = trim($_POST['nama_micro_cluster']);
                $branch_id = (int)$_POST['branch_id'];
                $brand = $_POST['brand']; // Diambil dari hidden input
                if (empty($nama_mc) || empty($branch_id) || empty($brand)) {
                    throw new Exception("Nama Micro Cluster dan Parent Branch tidak boleh kosong.");
                }
                $sql = "INSERT INTO micro_clusters (nama_micro_cluster, branch_id, brand) VALUES (?, ?, ?)";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sis", $nama_mc, $branch_id, $brand);
                $stmt->execute();
                log_activity($mysqli, $admin_id, $admin_username, 'MC_ADD', "Menambahkan Micro Cluster baru: {$nama_mc} (Branch ID: {$branch_id})");
                $_SESSION['success_message'] = "Micro Cluster baru berhasil ditambahkan.";
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $nama_mc = trim($_POST['nama_micro_cluster']);
                $branch_id = (int)$_POST['branch_id'];
                $brand = $_POST['brand'];
                if (empty($nama_mc) || empty($branch_id) || empty($id) || empty($brand)) {
                    throw new Exception("ID, Nama, Parent Branch, dan Brand tidak boleh kosong.");
                }
                $sql = "UPDATE micro_clusters SET nama_micro_cluster = ?, branch_id = ?, brand = ? WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sisi", $nama_mc, $branch_id, $brand, $id);
                $stmt->execute();
                log_activity($mysqli, $admin_id, $admin_username, 'MC_EDIT', "Mengedit data Micro Cluster: {$nama_mc} - ID: {$id}");
                $_SESSION['success_message'] = "Data micro cluster berhasil diperbarui.";
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                if (empty($id)) {
                    throw new Exception("ID Micro Cluster tidak valid.");
                }
                $sql = "DELETE FROM micro_clusters WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                log_activity($mysqli, $admin_id, $admin_username, 'MC_DELETE', "Menghapus Micro Cluster - ID: {$id}");
                $_SESSION['success_message'] = "Micro Cluster berhasil dihapus.";
                break;
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("location: ../admin_manage_micro_clusters.php");
    exit();
}
?>
