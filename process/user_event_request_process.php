<?php
// process/user_event_request_process.php
// File ini KHUSUS untuk menangani permintaan (request) edit atau hapus dari modul EVENT.

require_once '../config/database.php';

// Keamanan: Pastikan hanya user yang login yang bisa mengakses
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

// Pastikan request datang dari method POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("location: ../dashboard_user.php");
    exit;
}

$user_id = (int)$_SESSION['id'];
$event_id = (int)($_POST['event_id'] ?? 0);
$request_type = $_POST['request_type'] ?? ''; // 'edit' or 'delete'
$reason = trim($_POST['reason'] ?? '');
$requested_columns = $_POST['requested_columns'] ?? []; // Untuk request 'edit'

// Validasi input dasar
if (empty($event_id) || empty($request_type) || empty($reason) || ($request_type === 'edit' && empty($requested_columns))) {
    $_SESSION['error_message'] = "Data permintaan tidak lengkap. Mohon isi semua kolom yang diperlukan.";
    header("location: ../dashboard_user.php");
    exit;
}

// FIXED: Add length validation for reason field
if (strlen($reason) > 500) {
    $_SESSION['error_message'] = "Alasan terlalu panjang. Maksimal 500 karakter.";
    header("location: ../dashboard_user.php");
    exit;
}

// Cek apakah user ini adalah pemilik event yang dimaksud
$stmt_owner_check = $mysqli->prepare("SELECT user_id FROM event_submissions WHERE unique_id = ?");
$stmt_owner_check->bind_param("i", $event_id);
$stmt_owner_check->execute();
$owner_result = $stmt_owner_check->get_result();
if ($owner_result->num_rows === 0 || $owner_result->fetch_assoc()['user_id'] != $user_id) {
    $_SESSION['error_message'] = "Anda tidak memiliki izin untuk membuat permintaan untuk event ini.";
    header("location: ../dashboard_user.php");
    exit;
}
$stmt_owner_check->close();


// Cek apakah sudah ada permintaan yang pending atau approved untuk event ini dari user yang sama
// FIXED: Also check for 'approved' status to prevent duplicate approved requests
$stmt_check = $mysqli->prepare("SELECT id FROM event_requests WHERE event_id = ? AND user_id = ? AND status IN ('pending', 'approved')");
$stmt_check->bind_param("ii", $event_id, $user_id);
$stmt_check->execute();
if ($stmt_check->get_result()->num_rows > 0) {
    // FIXED: Changed to error_message for consistency
    $_SESSION['error_message'] = "Sudah ada permintaan yang sedang diproses untuk event ini. Mohon tunggu review dari admin.";
    header("location: ../dashboard_user.php");
    exit;
}
$stmt_check->close();

$mysqli->begin_transaction();
try {
    // Simpan permintaan ke tabel 'event_requests'
    $sql_insert = "INSERT INTO event_requests (event_id, user_id, request_type, reason, requested_columns, status, requested_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
    $stmt_insert = $mysqli->prepare($sql_insert);
    
    // Ubah array kolom yang diminta menjadi format JSON untuk disimpan di database
    $requested_columns_json = json_encode($requested_columns);

    $stmt_insert->bind_param("iisss", $event_id, $user_id, $request_type, $reason, $requested_columns_json);
    
    if (!$stmt_insert->execute()) {
        throw new Exception("Gagal menyimpan permintaan: " . $stmt_insert->error);
    }
    $stmt_insert->close();

    $mysqli->commit();
    $_SESSION['success_message'] = "Permintaan Anda telah berhasil dikirim dan akan segera ditinjau oleh Admin.";

} catch (Exception $e) {
    $mysqli->rollback();
    $_SESSION['error_message'] = "Terjadi kesalahan saat mengirim permintaan: " . $e->getMessage();
}

header("location: ../dashboard_user.php");
exit;
?>
