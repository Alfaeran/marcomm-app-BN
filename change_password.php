<?php
// D:\laragon\www\marcomm_bn\change_password.php (Asumsi lokasi file)

// Hapus ob_start() dan session_start() dari sini.
// Mereka akan ditangani oleh config/database.php.

// Memuat file konfigurasi database
require_once 'config/database.php';

// Inisialisasi variabel username sebelum digunakan di log_activity
$username = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
} else {
    // If you want to log activity on page load, and if the user is logged in
    // you might retrieve the username from a session variable here.
    // For now, we'll keep it empty if not a POST request.
    // Example (if user is logged in and session is used for username):
    // if (isset($_SESSION['username'])) {
    //     $username = $_SESSION['username'];
    // }
}

// Ensure $user is defined if you need $user['id'] for log_activity
// For this specific context, where change_password.php might be accessed directly
// without a logged-in user, $user['id'] would also be undefined.
// You might need to adjust log_activity to handle null user ID or fetch it
// based on the provided username if the user is not authenticated yet.
// For now, let's assume 'id' can be null or adjust log_activity if it expects an int.
$user_id_for_log = isset($user['id']) ? $user['id'] : null; // Initialize $user_id_for_log

log_activity($mysqli, $user_id_for_log, $username, 'PASSWORD_CHANGE', "Mengubah kata sandi.", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null);

// Inisialisasi variabel pesan
$success_message = '';
$error_message = '';
$warning_message = '';

// --- LOGIKA PEMROSESAN FORM ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $username = trim($_POST['username'] ?? ''); // This line re-assigns, which is fine
        $current_password = $_POST['current_password'] ?? ''; // Sekarang opsional
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($new_password) || empty($confirm_password)) {
            throw new Exception("Username, Kata Sandi Baru, dan Konfirmasi Kata Sandi wajib diisi.");
        }
        if ($new_password !== $confirm_password) {
            throw new Exception("Kata sandi baru dan konfirmasi kata sandi tidak cocok.");
        }
        if (strlen($new_password) < 6) { // Contoh validasi panjang kata sandi
            throw new Exception("Kata sandi baru minimal 6 karakter.");
        }

        // 1. Cek apakah username terdaftar
        $stmt = $mysqli->prepare("SELECT id, password FROM users WHERE username = ?");
        if ($stmt === false) throw new Exception("Gagal menyiapkan query cek pengguna: " . $mysqli->error);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            throw new Exception("Username tidak ditemukan.");
        }

        // Verifikasi kata sandi lama HANYA JIKA diisi
        if (!empty($current_password)) {
            if (!password_verify($current_password, $user['password'])) {
                throw new Exception("Kata sandi lama salah.");
            }
        } else {
            // Jika kata sandi lama tidak diisi, ini adalah celah keamanan.
            // Di sini Anda bisa menambahkan log peringatan keamanan jika perlu.
            error_log("[SECURITY WARNING] Password changed for user '$username' without old password verification.");
            $warning_message = "Kata sandi diubah tanpa verifikasi kata sandi lama. Pastikan Anda adalah pemilik akun yang sah.";
        }

        // 2. Update kata sandi pengguna
        $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
        if ($stmt === false) throw new Exception("Gagal menyiapkan query update password: " . $mysqli->error);
        $stmt->bind_param("si", $hashed_new_password, $user['id']);
        $stmt->execute();
        $stmt->close();

        $success_message = "Kata sandi Anda berhasil diubah."; // Tidak menampilkan kata sandi baru

    } catch (Exception $e) {
        $error_message = "Terjadi kesalahan: " . $e->getMessage();
        error_log("[ERROR] change_password.php - " . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Kata Sandi</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="w-full max-w-sm bg-white rounded-lg shadow-md p-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">Ubah Kata Sandi</h2>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <p><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($warning_message)): ?>
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
                <p><?php echo $warning_message; ?></p>
            </div>
        <?php endif; ?>

        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p class="font-bold">PERINGATAN KEAMANAN PENTING!</p>
            <p class="text-sm mt-2">Kolom "Kata Sandi Saat Ini" bersifat opsional. Ini berarti siapa pun yang mengetahui username Anda **dapat mengubah kata sandi Anda tanpa mengetahui kata sandi lama Anda.**</p>
            <p class="text-sm mt-2 font-bold">Kami sangat menyarankan untuk selalu mengisi "Kata Sandi Saat Ini" untuk keamanan akun Anda.</p>
        </div>

        <form action="" method="POST" class="space-y-4">
            <div>
                <label for="username" class="block text-gray-700 text-sm font-bold mb-2">Username:</label>
                <input type="text" id="username" name="username" required class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            </div>
            <div>
                <label for="current_password" class="block text-gray-700 text-sm font-bold mb-2">Kata Sandi Saat Ini (Opsional):</label>
                <input type="password" id="current_password" name="current_password" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            </div>
            <div>
                <label for="new_password" class="block text-gray-700 text-sm font-bold mb-2">Kata Sandi Baru:</label>
                <input type="password" id="new_password" name="new_password" required class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            </div>
            <div>
                <label for="confirm_password" class="block text-gray-700 text-sm font-bold mb-2">Konfirmasi Kata Sandi Baru:</label>
                <input type="password" id="confirm_password" name="confirm_password" required class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            </div>
            <div class="flex items-center justify-between">
                <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                    Ubah Kata Sandi
                </button>
            </div>
        </form>

        <div class="text-center mt-6">
            <a href="login.php" class="text-blue-500 hover:text-blue-800 text-sm">Kembali ke Login</a>
        </div>
    </div>
</body>
</html>
<?php
// Mengirimkan semua output yang di-buffer ke browser
// ob_end_flush(); // This was already commented out in your database.php, and typically handled there.
?>
