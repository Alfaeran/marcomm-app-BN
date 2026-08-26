<?php
// admin_matpro_activity_detail.php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "ID Aktivitas Matpro tidak valid.";
    header("location: admin_laporan_matpro.php");
    exit;
}

$activity_id = (int) $_GET['id'];
$activity_data = null;

// Query untuk mengambil detail aktivitas Matpro
// PERBAIKAN: Menghapus JOIN ke matpro_projects dan matpro_types
// Mengambil project_name dan type_name langsung dari matpro_activities
// Mengambil brand dari tabel branches
// PERBAIKAN: Menghapus o.organization_id, o.organization_name
$sql_activity = "SELECT ma.*,
                        u.username, u.nama as user_nama,
                        b.brand, -- Mengambil brand dari tabel branches
                        b.nama_branch,
                        mc.nama_micro_cluster,
                        s.site_name, s.site_id as site_code, s.kecamatan, s.kabupaten, s.area, s.region,
                        COALESCE(o.Id_Outlet_Nama_Outlet, ma.outlet_snapshot_name, 'Outlet Telah Dihapus') as Id_Outlet_Nama_Outlet
                 FROM matpro_activities ma
                 JOIN users u ON ma.user_id = u.id
                 JOIN branches b ON ma.branch_id = b.id
                 LEFT JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
                 JOIN sites s ON ma.site_id = s.id
                 LEFT JOIN outlets o ON ma.outlet_id = o.id
                 WHERE ma.id = ?";

$stmt_activity = $mysqli->prepare($sql_activity);
if ($stmt_activity) {
    $stmt_activity->bind_param("i", $activity_id);
    $stmt_activity->execute();
    $result_activity = $stmt_activity->get_result();
    if ($result_activity->num_rows === 1) {
        $activity_data = $result_activity->fetch_assoc();
    } else {
        $_SESSION['error_message'] = "Aktivitas Matpro tidak ditemukan.";
        header("location: admin_laporan_matpro.php");
        exit;
    }
    $stmt_activity->close();
} else {
    $_SESSION['error_message'] = "Gagal menyiapkan query detail aktivitas: " . $mysqli->error;
    header("location: admin_laporan_matpro.php");
    exit;
}

// Fungsi helper untuk menampilkan detail
function display_detail($label, $value, $is_currency = false)
{
    $display_value = htmlspecialchars($value ?? '-');
    if ($is_currency && is_numeric($value)) {
        $display_value = 'Rp ' . number_format($value, 0, ',', '.');
    }
    echo '<div class="flex flex-col"><span class="text-sm font-medium text-gray-500">' . $label . '</span><span class="text-gray-900 text-base font-semibold">' . $display_value . '</span></div>';
}

// Dapatkan base URL aplikasi Anda untuk foto
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$project_folder = 'marcomm_bn'; // Sesuaikan dengan nama folder proyek Anda
$base_url .= '/' . $project_folder;

$photo_before_full_url = !empty($activity_data['photo_before_url']) ? $base_url . '/' . $activity_data['photo_before_url'] : '';
$photo_after_full_url = !empty($activity_data['photo_after_url']) ? $base_url . '/' . $activity_data['photo_after_url'] : '';

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Aktivitas Matpro - <?php echo htmlspecialchars($activity_data['unique_id']); ?></title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>

<body class="bg-gray-100 p-4 md:p-8">
    <div class="max-w-4xl mx-auto bg-white p-6 md:p-8 rounded-lg shadow-lg">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Detail Aktivitas Matpro</h1>
            <a href="admin_laporan_matpro.php"
                class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg">Kembali</a>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert">
                <p><?php echo $_SESSION['success_message']; ?></p>
            </div>
            <?php unset($_SESSION['success_message']); endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                <p><?php echo $_SESSION['error_message']; ?></p>
            </div>
            <?php unset($_SESSION['error_message']); endif; ?>

        <div class="space-y-6">
            <h3 class="text-xl font-semibold mb-4">Informasi Umum</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
                <?php
                display_detail("ID Aktivitas", $activity_data['unique_id']);
                display_detail("Waktu Aktivitas", date('d M Y, H:i:s', strtotime($activity_data['activity_datetime'])));
                display_detail("User Input", $activity_data['user_nama'] . ' (' . $activity_data['username'] . ')');
                display_detail("Level Input", !empty($activity_data['micro_cluster_id']) ? 'Outlet Level' : 'Branch Level');
                ?>
            </div>

            <hr class="my-6">
            <h3 class="text-xl font-semibold mb-4">Detail Lokasi</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
                <?php
                display_detail("Branch", $activity_data['nama_branch']);
                display_detail("Micro Cluster", $activity_data['nama_micro_cluster'] ?? '-');
                display_detail("Site Name", $activity_data['site_name'] . ' (' . $activity_data['site_code'] . ')');
                display_detail("ID Outlet | Nama Outlet", $activity_data['Id_Outlet_Nama_Outlet']);
                display_detail("Kecamatan", $activity_data['kecamatan']);
                display_detail("Kabupaten", $activity_data['kabupaten']);
                display_detail("Area", $activity_data['area'] ?? '-');
                display_detail("Region", $activity_data['region'] ?? '-');
                display_detail("Latitude", $activity_data['location_latitude']);
                display_detail("Longitude", $activity_data['location_longitude']);
                ?>
            </div>

            <hr class="my-6">
            <h3 class="text-xl font-semibold mb-4">Detail Matpro</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
                <?php
                // Menggunakan project_name dan brand dari activity_data
                display_detail("Proyek Matpro", $activity_data['project_name'] . ' (' . $activity_data['brand'] . ')');
                // Menggunakan type_name dari activity_data
                display_detail("Jenis Matpro", $activity_data['type_name']);
                display_detail("QTY Digunakan", number_format($activity_data['qty_used']));
                // PERBAIKAN: Menghapus display_detail untuk Organization ID dan Organization Name
                // display_detail("Organization ID", $activity_data['organization_id'] ?? '-');
                // display_detail("Organization Name", $activity_data['organization_name'] ?? '-');
                ?>
            </div>

            <hr class="my-6">
            <h3 class="text-xl font-semibold mb-4">Dokumentasi Foto</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <span class="text-sm font-medium text-gray-500">Foto Sebelum Pemasangan</span>
                    <?php if (!empty($photo_before_full_url)): ?>
                        <a href="<?php echo htmlspecialchars($photo_before_full_url); ?>" target="_blank"
                            class="block mt-2">
                            <img src="<?php echo htmlspecialchars($photo_before_full_url); ?>" alt="Foto Sebelum"
                                class="w-full h-64 object-cover rounded-lg shadow-md border border-gray-200">
                        </a>
                    <?php else: ?>
                        <p class="text-gray-700 mt-2">Tidak ada foto sebelum pemasangan.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-500">Foto Sesudah Pemasangan</span>
                    <?php if (!empty($photo_after_full_url)): ?>
                        <a href="<?php echo htmlspecialchars($photo_after_full_url); ?>" target="_blank" class="block mt-2">
                            <img src="<?php echo htmlspecialchars($photo_after_full_url); ?>" alt="Foto Sesudah"
                                class="w-full h-64 object-cover rounded-lg shadow-md border border-gray-200">
                        </a>
                    <?php else: ?>
                        <p class="text-gray-700 mt-2">Tidak ada foto sesudah pemasangan.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>

</html>