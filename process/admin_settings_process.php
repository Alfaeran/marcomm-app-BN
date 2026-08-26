<?php
require_once '../config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $app_name = trim($_POST['app_name']);
    $admin_id = $_SESSION['id'];
    $admin_username = $_SESSION['username'];

    try {
        // Update nama aplikasi
        $stmt_name = $mysqli->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'app_name'");
        $stmt_name->bind_param("s", $app_name);
        $stmt_name->execute();

        // Update pengaturan bulky import
        $bulk_status = isset($_POST['bulk_import_status']) ? '1' : '0';
        $bulk_start  = $_POST['bulk_import_start_time'] ?? '08:00';
        $bulk_end    = $_POST['bulk_import_end_time'] ?? '17:00';
        $bulk_day_start = $_POST['bulk_import_start_day'] ?? '1';
        $bulk_day_end   = $_POST['bulk_import_end_day'] ?? '31';

        $bulk_settings = [
            'bulk_import_status' => $bulk_status,
            'bulk_import_start_time' => $bulk_start,
            'bulk_import_end_time' => $bulk_end,
            'bulk_import_start_day' => $bulk_day_start,
            'bulk_import_end_day' => $bulk_day_end
        ];

        foreach ($bulk_settings as $key => $val) {
            $stmt = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param("sss", $key, $val, $val);
            $stmt->execute();
            $stmt->close();
        }

        // Cek jika ada file logo baru yang diunggah
        if (isset($_FILES['app_logo']) && $_FILES['app_logo']['error'] == 0) {
            $target_dir = "../assets/";
            if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
            
            $file_extension = strtolower(pathinfo($_FILES["app_logo"]["name"], PATHINFO_EXTENSION));
            $new_logo_filename = "app_logo." . $file_extension;
            $target_file = $target_dir . $new_logo_filename;

            // Validasi file
            $check = getimagesize($_FILES["app_logo"]["tmp_name"]);
            if($check === false) {
                throw new Exception("File yang diunggah bukan gambar.");
            }
            if ($_FILES["app_logo"]["size"] > 2000000) { // 2MB
                throw new Exception("Ukuran file logo terlalu besar (maks 2MB).");
            }

            // Pindahkan file
            if (move_uploaded_file($_FILES["app_logo"]["tmp_name"], $target_file)) {
                $logo_path = "assets/" . $new_logo_filename;
                $stmt_logo = $mysqli->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'app_logo'");
                $stmt_logo->bind_param("s", $logo_path);
                $stmt_logo->execute();
            } else {
                throw new Exception("Gagal mengunggah logo baru.");
            }
        }

        log_activity($mysqli, $admin_id, $admin_username, 'SETTINGS_UPDATE', "Memperbarui pengaturan sistem: Nama Aplikasi -> {$app_name}");
        $_SESSION['success_message'] = "Pengaturan berhasil diperbarui.";

    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }

    header("location: ../admin_settings.php");
    exit();
}
?>
