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
                $nama_kategori = trim($_POST['nama_kategori']);
                if (empty($nama_kategori)) {
                    throw new Exception("Nama Kategori tidak boleh kosong.");
                }
                $sql = "INSERT INTO event_categories (nama_kategori) VALUES (?)";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("s", $nama_kategori);
                $stmt->execute();
                log_activity($mysqli, $admin_id, $admin_username, 'CAT_ADD', "Menambahkan kategori event baru: {$nama_kategori}");
                $_SESSION['success_message'] = "Kategori baru berhasil ditambahkan.";
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $nama_kategori = trim($_POST['nama_kategori']);
                if (empty($nama_kategori) || empty($id)) {
                    throw new Exception("ID dan Nama Kategori tidak boleh kosong.");
                }
                $sql = "UPDATE event_categories SET nama_kategori = ? WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("si", $nama_kategori, $id);
                $stmt->execute();
                log_activity($mysqli, $admin_id, $admin_username, 'CAT_EDIT', "Mengedit kategori event: {$nama_kategori} - ID: {$id}");
                $_SESSION['success_message'] = "Data kategori berhasil diperbarui.";
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                if (empty($id)) {
                    throw new Exception("ID Kategori tidak valid.");
                }
                $sql = "DELETE FROM event_categories WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                log_activity($mysqli, $admin_id, $admin_username, 'CAT_DELETE', "Menghapus kategori event - ID: {$id}");
                $_SESSION['success_message'] = "Kategori berhasil dihapus.";
                break;
        }
    } catch (Exception $e) {
        // Tangani error duplikat nama kategori
        if ($mysqli->errno == 1062) {
            $_SESSION['error_message'] = "Gagal! Nama kategori '" . htmlspecialchars($nama_kategori) . "' sudah ada.";
        } else {
            $_SESSION['error_message'] = $e->getMessage();
        }
    }
    
    header("location: ../admin_manage_event_categories.php");
    exit();
}
?>
