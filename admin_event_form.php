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

// Ambil data untuk dropdown dan checkbox
$categories_result = $mysqli->query("SELECT id, nama_kategori FROM event_categories ORDER BY nama_kategori");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Event</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-6xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Edit Event</h1>
    
    <form action="process/admin_event_process.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="unique_id" value="<?php echo $event_data['unique_id']; ?>">

        <!-- Bagian 1: Informasi Dasar & Lokasi -->
        <fieldset class="border p-4 rounded-lg mb-6">
            <legend class="text-xl font-semibold px-2">Informasi Event & Lokasi</legend>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-4">
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
                <div class="mb-4">
                    <label for="latitude" class="block text-gray-700 font-bold mb-2">Latitude</label>
                    <input type="text" name="latitude" value="<?php echo htmlspecialchars($event_data['location_latitude']); ?>" required class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div class="mb-4">
                    <label for="longitude" class="block text-gray-700 font-bold mb-2">Longitude</label>
                    <input type="text" name="longitude" value="<?php echo htmlspecialchars($event_data['location_longitude']); ?>" required class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
        </fieldset>

        <!-- Bagian 2: Data Penjualan & Biaya -->
        <fieldset class="border p-4 rounded-lg mb-6">
            <legend class="text-xl font-semibold px-2">Data Penjualan, Audience & Biaya</legend>
             <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mt-4">
                <div class="mb-4"><label class="block text-gray-700 mb-2">SP 0K</label><input type="number" name="sp_0k" value="<?php echo $event_data['sp_0k']; ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                <div class="mb-4"><label class="block text-gray-700 mb-2">SP 3GB</label><input type="number" name="sp_3gb" value="<?php echo $event_data['sp_3gb']; ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                <div class="mb-4"><label class="block text-gray-700 mb-2">SP 5GB</label><input type="number" name="sp_5gb" value="<?php echo $event_data['sp_5gb']; ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                <div class="mb-4"><label class="block text-gray-700 mb-2">SP 7GB</label><input type="number" name="sp_7gb" value="<?php echo $event_data['sp_7gb']; ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                <div class="mb-4"><label class="block text-gray-700 mb-2">SP 100GB</label><input type="number" name="sp_100gb" value="<?php echo $event_data['sp_100gb']; ?>" class="w-full px-3 py-2 border rounded-lg"></div>
                <div class="mb-4"><label class="block text-gray-700 mb-2">FWA</label><input type="number" name="fwa" value="<?php echo $event_data['fwa']; ?>" class="w-full px-3 py-2 border rounded-lg"></div>
                <div class="mb-4"><label class="block text-gray-700 mb-2">HIT Haji/Umroh</label><input type="number" name="hit_haji_umroh" value="<?php echo $event_data['hit_haji_umroh']; ?>" class="w-full px-3 py-2 border rounded-lg"></div>
                <div class="mb-4"><label class="block text-gray-700 mb-2">Reload</label><input type="number" name="reload" value="<?php echo $event_data['reload']; ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                <div class="mb-4"><label class="block text-gray-700 mb-2">Mobo/Paket</label><input type="number" name="mobo_paket" value="<?php echo $event_data['mobo_paket']; ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                <div class="mb-4"><label class="block text-gray-700 mb-2">Cost</label><input type="number" name="cost" value="<?php echo $event_data['cost']; ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
            </div>
        </fieldset>

        <!-- Bagian Baru: Upload MSISDN -->
        <fieldset class="border p-4 rounded-lg mb-6">
            <legend class="text-xl font-semibold px-2">Upload MSISDN Tambahan</legend>
            <div class="mt-4">
                <div class="flex justify-between items-center mb-2">
                    <label for="msisdn_file" class="block text-gray-700 font-bold">File MSISDN (Opsional)</label>
                    <a href="process/download_msisdn_template.php" class="text-sm text-blue-600 hover:underline"><i class="fas fa-download mr-1"></i> Unduh Template</a>
                </div>
                <input type="file" id="msisdn_file" name="msisdn_file" accept=".xlsx, .xls" class="w-full px-3 py-2 border rounded-lg">
                <p class="text-sm text-gray-600 mt-1">Unggah file Excel (.xlsx atau .xls) yang berisi MSISDN di kolom pertama. MSISDN baru akan ditambahkan ke event ini.</p>
            </div>
        </fieldset>

        <!-- Bagian 3: Feedback -->
        <fieldset class="border p-4 rounded-lg mb-6">
            <legend class="text-xl font-semibold px-2">Feedback</legend>
            <div class="mt-4">
                <label for="alasan" class="block text-gray-700 mb-2">Alasan / Feedback</label>
                <textarea name="alasan" rows="3" class="w-full px-3 py-2 border rounded-lg"><?php echo htmlspecialchars($event_data['alasan']); ?></textarea>
            </div>
        </fieldset>


        <div class="mt-8 flex justify-end">
            <a href="admin_detail_event.php?id=<?php echo $event_data['unique_id']; ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">Batal</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Simpan Perubahan</button>
        </div>
    </form>
</div>
</body>
</html>
