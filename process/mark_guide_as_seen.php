<?php
// Menampilkan semua error untuk debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';

// Cek jika user tidak login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
    exit;
}

$user_id = $_SESSION['id'];

// Siapkan statement untuk update database
$stmt = $mysqli->prepare("UPDATE users SET has_seen_guide = 1 WHERE id = ?");

// Cek jika prepare() gagal
if ($stmt === false) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyiapkan statement: ' . $mysqli->error]);
    exit;
}

// Bind parameter
$stmt->bind_param("i", $user_id);

// Eksekusi dan cek jika berhasil
if ($stmt->execute()) {
    // Update juga sesi agar pop-up tidak muncul lagi di sesi ini
    $_SESSION['has_seen_guide'] = 1;
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
} else {
    // Jika eksekusi gagal
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui status: ' . $stmt->error]);
}

$stmt->close();
$mysqli->close();
?>
