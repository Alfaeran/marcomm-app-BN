<?php
// user_matpro_activities.php
require_once 'config/database.php';

// Cek jika user tidak login atau bukan 'user'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}

$user_id = (int)$_SESSION['id'];

// Inisialisasi pesan
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// --- Logika Paginasi & Filter (opsional, bisa ditambahkan nanti jika diperlukan) ---
$records_per_page = 25; // Default records per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$where_clauses = ["ma.user_id = ?"]; // Filter utama: hanya aktivitas user yang login
$param_types = "i";
$param_values = [$user_id];
$filter_query_string = ""; // Untuk paginasi

// Query untuk menghitung total data
$count_sql = "SELECT COUNT(ma.id) as total 
              FROM matpro_activities ma
              JOIN users u ON ma.user_id = u.id
              JOIN matpro_projects mp ON ma.project_id = mp.id
              JOIN matpro_types mt ON ma.type_id = mt.id
              JOIN branches b ON ma.branch_id = b.id
              LEFT JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
              JOIN sites s ON ma.site_id = s.id
              JOIN outlets o ON ma.outlet_id = o.id
              WHERE ma.user_id = ?"; // Pastikan filter user_id ada di count
$stmt_count = $mysqli->prepare($count_sql);
if ($stmt_count) {
    $stmt_count->bind_param("i", $user_id); // Bind user_id untuk count
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
} else {
    $total_records = 0;
    $error_message = "Gagal menghitung total aktivitas Matpro: " . $mysqli->error;
}
$total_pages = ceil($total_records / $records_per_page);

// Query untuk mengambil data aktivitas Matpro
$sql = "SELECT ma.id, ma.unique_id, ma.activity_datetime, ma.location_latitude, ma.location_longitude,
               ma.quantity, ma.photo_before_url, ma.photo_after_url,
               u.username, u.nama as user_nama,
               mp.project_name, mp.brand,
               mt.type_name,
               b.nama_branch,
               mc.nama_micro_cluster,
               s.site_name, s.site_id as site_code, s.kecamatan, s.kabupaten,
               o.outlet_name, o.outlet_id_code
        FROM matpro_activities ma
        JOIN users u ON ma.user_id = u.id
        JOIN matpro_projects mp ON ma.project_id = mp.id
        JOIN matpro_types mt ON ma.type_id = mt.id
        JOIN branches b ON ma.branch_id = b.id
        LEFT JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
        JOIN sites s ON ma.site_id = s.id
        JOIN outlets o ON ma.outlet_id = o.id
        WHERE ma.user_id = ?
        ORDER BY ma.activity_datetime DESC LIMIT ? OFFSET ?";
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param("iii", $user_id, $records_per_page, $offset); // Bind user_id, limit, offset
    $stmt->execute();
    $activities_result = $stmt->get_result();
    $stmt->close();
} else {
    $activities_result = false;
    $error_message = "Gagal mengambil data aktivitas Matpro: " . $mysqli->error;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Aktivitas Matpro Saya</title>
    <link href="../assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        @media (max-width: 768px) {
            .responsive-table thead { display: none; }
            .responsive-table tbody, .responsive-table tr, .responsive-table td { display: block; width: 100%; }
            .responsive-table tr { margin-bottom: 1rem; border: 1px solid #ddd; border-radius: 0.5rem; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .responsive-table td { padding-left: 50% !important; position: relative; text-align: right; border-bottom: 1px solid #eee; }
            .responsive-table td:before { content: attr(data-label); position: absolute; left: 0; width: 45%; padding-left: 1rem; font-weight: bold; text-align: left; white-space: nowrap; }
            .responsive-table td:last-child { border-bottom: 0; }
        }
        .photo-thumbnail {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4 md:p-8">
        <div class="bg-white p-6 md:p-8 rounded-lg shadow-lg">
            
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 border-b pb-4">
                <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Daftar Aktivitas Matpro Saya</h1>
                <a href="dashboard_user.php" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg">Kembali</a>
            </div>

            <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert"><p><?php echo $success_message; ?></p></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert"><p><?php echo $error_message; ?></p></div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row justify-between items-center mb-4 gap-4">
                <div class="flex items-center gap-2">
                    <label for="limit" class="text-sm">Tampilkan:</label>
                    <select id="limit" onchange="window.location.href = 'user_matpro_activities.php?page=1<?php echo str_replace(['&limit='.$records_per_page, '&page='.$page], '', $filter_query_string); ?>&limit=' + this.value" class="rounded-md border-gray-300 shadow-sm">
                        <option value="10" <?php if($records_per_page == 10) echo 'selected'; ?>>10</option>
                        <option value="25" <?php if($records_per_page == 25) echo 'selected'; ?>>25</option>
                        <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50</option>
                        <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100</option>
                    </select>
                    <span class="text-sm text-gray-700">dari <?php echo $total_records; ?> total data</span>
                </div>
                <!-- Tombol Ekspor (opsional untuk user)
                <div class="flex gap-2">
                    <form action="process/user_export_matpro_activities_process.php" method="POST">
                        <input type="hidden" name="action" value="export_filtered">
                        <?php //foreach($_GET as $key => $value): ?>
                        <input type="hidden" name="<?php //echo htmlspecialchars($key); ?>" value="<?php //echo htmlspecialchars($value); ?>">
                        <?php //endforeach; ?>
                        <button type="submit" class="w-full md:w-auto bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-file-excel"></i> Ekspor Data
                        </button>
                    </form>
                </div>
                -->
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full bg-white responsive-table">
                    <thead class="bg-gray-800 text-white">
                        <tr>
                            <th class="text-left py-3 px-4">Waktu</th>
                            <th class="text-left py-3 px-4">Proyek</th>
                            <th class="text-left py-3 px-4">Jenis</th>
                            <th class="text-left py-3 px-4">QTY</th>
                            <th class="text-left py-3 px-4">Branch</th>
                            <th class="text-left py-3 px-4">Micro Cluster</th>
                            <th class="text-left py-3 px-4">Site Name</th>
                            <th class="text-left py-3 px-4">Outlet</th>
                            <th class="text-center py-3 px-4">Foto Sebelum</th>
                            <th class="text-center py-3 px-4">Foto Sesudah</th>
                            <!-- <th class="text-center py-3 px-4">Aksi</th> --> <!-- Aksi edit/delete tidak ada untuk user -->
                        </tr>
                    </thead>
                    <tbody class="text-gray-700">
                        <?php if ($activities_result && $activities_result->num_rows > 0): ?>
                            <?php while($activity = $activities_result->fetch_assoc()): ?>
                                <tr class="border-b">
                                    <td data-label="Waktu" class="py-3 px-4 text-sm whitespace-nowrap"><?php echo date('d M Y, H:i', strtotime($activity['activity_datetime'])); ?></td>
                                    <td data-label="Proyek" class="py-3 px-4"><?php echo htmlspecialchars($activity['project_name']); ?> (<?php echo htmlspecialchars($activity['brand']); ?>)</td>
                                    <td data-label="Jenis" class="py-3 px-4"><?php echo htmlspecialchars($activity['type_name']); ?></td>
                                    <td data-label="QTY" class="py-3 px-4 text-center"><?php echo number_format($activity['quantity']); ?></td>
                                    <td data-label="Branch" class="py-3 px-4"><?php echo htmlspecialchars($activity['nama_branch']); ?></td>
                                    <td data-label="Micro Cluster" class="py-3 px-4"><?php echo htmlspecialchars($activity['nama_micro_cluster'] ?? '-'); ?></td>
                                    <td data-label="Site Name" class="py-3 px-4"><?php echo htmlspecialchars($activity['site_name']); ?> (<?php echo htmlspecialchars($activity['site_code']); ?>)</td>
                                    <td data-label="Outlet" class="py-3 px-4"><?php echo htmlspecialchars($activity['outlet_name']); ?> (<?php echo htmlspecialchars($activity['outlet_id_code']); ?>)</td>
                                    <td data-label="Foto Sebelum" class="py-3 px-4 text-center">
                                        <?php if (!empty($activity['photo_before_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($activity['photo_before_url']); ?>" target="_blank">
                                                <img src="<?php echo htmlspecialchars($activity['photo_before_url']); ?>" alt="Sebelum" class="photo-thumbnail mx-auto">
                                            </a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Foto Sesudah" class="py-3 px-4 text-center">
                                        <?php if (!empty($activity['photo_after_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($activity['photo_after_url']); ?>" target="_blank">
                                                <img src="<?php echo htmlspecialchars($activity['photo_after_url']); ?>" alt="Sesudah" class="photo-thumbnail mx-auto">
                                            </a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <!-- <td data-label="Aksi" class="py-3 px-4 text-center">
                                        Aksi tidak tersedia untuk user
                                    </td> -->
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="10" class="text-center py-4">Tidak ada aktivitas Matpro yang tercatat untuk Anda.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginasi -->
            <div class="mt-6 flex justify-center">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php
                    if($total_pages > 1) {
                        $max_pages_to_show = 5;
                        $start_page = max(1, $page - floor($max_pages_to_show / 2));
                        $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                        $start_page = max(1, $end_page - $max_pages_to_show + 1);

                        if ($page > 1) echo '<a href="?page='.($page-1).$filter_query_string.'" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">Sebelumnya</a>';
                        if ($start_page > 1) { echo '<a href="?page=1'.$filter_query_string.'" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>'; if ($start_page > 2) echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>'; }
                        for ($i = $start_page; $i <= $end_page; $i++) { $active_class = ($i == $page) ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; echo '<a href="?page='.$i.$filter_query_string.'" class="relative inline-flex items-center px-4 py-2 border text-sm font-medium '.$active_class.'">'.$i.'</a>'; }
                        if ($end_page < $total_pages) { if ($end_page < $total_pages - 1) echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>'; echo '<a href="?page='.$total_pages.$filter_query_string.'" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">'.$total_pages.'</a>'; }
                        if ($page < $total_pages) echo '<a href="?page='.($page+1).$filter_query_string.'" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">Selanjutnya</a>';
                    }
                    ?>
                </nav>
            </div>
        </div>
    </div>
</body>
</html>
