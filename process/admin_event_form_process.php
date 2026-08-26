<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("location: admin_laporan_event.php");
    exit;
}

$event_id = (int)$_GET['id'];
$stmt = $mysqli->prepare("SELECT * FROM event_submissions WHERE unique_id = ?");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows !== 1) { die("Event tidak ditemukan."); }
$event_data = $result->fetch_assoc();
$stmt->close();

// Ambil data untuk dropdown
$categories_result = $mysqli->query("SELECT id, nama_kategori FROM event_categories ORDER BY nama_kategori");
$provider_digunakan_arr = explode(', ', $event_data['provider_digunakan']);
$provider_terbaik_arr = explode(', ', $event_data['provider_terbaik']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Event</title>
    <link href="../assets/css/tailwind.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-4xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Edit Event</h1>
    
    <form action="process/admin_event_process.php" method="post">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="unique_id" value="<?php echo $event_data['unique_id']; ?>">

        <!-- Semua field dari input_form.php dimasukkan di sini, dengan value yang sudah terisi -->
        <!-- Contoh: -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="mb-4">
                <label for="event_name" class="block text-gray-700 font-bold mb-2">Nama Event</label>
                <input type="text" name="event_name" value="<?php echo htmlspecialchars($event_data['event_name']); ?>" required class="w-full px-3 py-2 border rounded-lg">
            </div>
            <div class="mb-4">
                <label for="kategori_event_id" class="block text-gray-700 font-bold mb-2">Kategori Event</label>
                <select name="kategori_event_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                    <?php while($cat = $categories_result->fetch_assoc()): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php if($cat['id'] == $event_data['kategori_event_id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($cat['nama_kategori']); ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <!-- Lanjutkan untuk semua field lainnya (SP, Cost, Survey, dll.) -->
        </div>

        <div class="mt-8 flex justify-end">
            <a href="admin_detail_event.php?id=<?php echo $event_data['unique_id']; ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">Batal</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Simpan Perubahan</button>
        </div>
    </form>
</div>
</body>
</html>
