<?php
// process/user_request_process.php
require_once '../config/database.php';

// Cek jika user tidak login atau bukan 'user'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("location: ../user_matpro_activities.php");
    exit;
}

$user_id = (int)$_SESSION['id'];
$activity_id = (int)($_POST['activity_id'] ?? 0);
$request_type = $_POST['request_type'] ?? ''; // 'edit' or 'delete'
$reason = trim($_POST['reason'] ?? '');

// FIXED: Add length validation for reason field
if (empty($activity_id) || empty($request_type) || empty($reason)) {
    $_SESSION['error_message'] = "Data permintaan tidak lengkap. Mohon isi semua kolom.";
    header("location: ../user_matpro_activities.php");
    exit;
}

if (strlen($reason) > 500) {
    $_SESSION['error_message'] = "Alasan terlalu panjang. Maksimal 500 karakter.";
    header("location: ../user_matpro_activities.php");
    exit;
}

// Cek apakah sudah ada permintaan yang pending untuk aktivitas ini
$stmt_check = $mysqli->prepare("
    (SELECT id FROM deletion_requests WHERE activity_id = ? AND status = 'pending')
    UNION
    (SELECT id FROM matpro_edit_requests WHERE activity_id = ? AND status = 'pending')
");
if (!$stmt_check) {
    $_SESSION['error_message'] = "Gagal menyiapkan query pengecekan: " . $mysqli->error;
    header("location: ../user_matpro_activities.php");
    exit;
}
$stmt_check->bind_param("ii", $activity_id, $activity_id);
$stmt_check->execute();
if ($stmt_check->get_result()->num_rows > 0) {
    // FIXED: Standardized error message
    $_SESSION['error_message'] = "Sudah ada permintaan yang sedang diproses untuk aktivitas ini. Mohon tunggu review dari admin.";
    header("location: ../user_matpro_activities.php");
    exit;
}
$stmt_check->close();


$mysqli->begin_transaction();
try {
    $table_name = '';
    if ($request_type === 'delete') {
        $table_name = 'deletion_requests';
    } elseif ($request_type === 'edit') {
        $table_name = 'matpro_edit_requests';
    } else {
        throw new Exception("Tipe permintaan tidak valid.");
    }

    // Masukkan permintaan ke tabel yang sesuai
    $sql_insert = "INSERT INTO {$table_name} (activity_id, user_id, reason, requested_at, status) VALUES (?, ?, ?, NOW(), 'pending')";
    $stmt_insert = $mysqli->prepare($sql_insert);
    if (!$stmt_insert) {
        throw new Exception("Gagal menyiapkan statement insert: " . $mysqli->error);
    }
    $stmt_insert->bind_param("iis", $activity_id, $user_id, $reason);
    
    if (!$stmt_insert->execute()) {
        throw new Exception("Gagal menyimpan permintaan: " . $stmt_insert->error);
    }
    $stmt_insert->close();

    $mysqli->commit();
    $_SESSION['success_message'] = "Permintaan Anda telah berhasil dikirim dan sedang menunggu review dari admin.";

} catch (Exception $e) {
    $mysqli->rollback();
    $_SESSION['error_message'] = "Terjadi kesalahan: " . $e->getMessage();
}

header("location: ../user_matpro_activities.php");
exit();
