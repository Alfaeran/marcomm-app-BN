<?php
// user_outlets.php
require_once 'config/database.php';

// Cek hak akses user
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION['id'];
$user_branch_id = $_SESSION['branch_id'] ?? null;

// Cek apakah user punya spesifik micro clusters
$stmt_check_mc = $mysqli->prepare("SELECT micro_cluster_id FROM user_micro_clusters WHERE user_id = ?");
$stmt_check_mc->bind_param("i", $user_id);
$stmt_check_mc->execute();
$mc_res = $stmt_check_mc->get_result();
$user_mc_ids = [];
while($row = $mc_res->fetch_assoc()) {
    $user_mc_ids[] = (int)$row['micro_cluster_id'];
}
$stmt_check_mc->close();

$has_specific_mc = count($user_mc_ids) > 0;

// --- Logika Paginasi & Filter ---
$records_per_page = isset($_GET['limit']) && in_array($_GET['limit'], [10, 25, 50, 100]) ? (int)$_GET['limit'] : 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$where_clauses = [];
$param_types = "";
$param_values = [];
$filter_query_string = "";
$export_query_string = "";

$search_keyword = $_GET['keyword'] ?? '';
$mc_filter = $_GET['mc_id'] ?? '';


// Hak akses selalu
if ($has_specific_mc) {
    $mc_in = implode(',', $user_mc_ids);
    $where_clauses[] = "s.micro_cluster_id IN ($mc_in)";
} else {
    $where_clauses[] = "s.branch_id = " . (int)$user_branch_id;
}


// Filter pencarian
if (!empty($search_keyword)) {
    $where_clauses[] = "(o.id_outlet LIKE ? OR o.nama_outlet LIKE ? OR s.site_name LIKE ?)";
    $param_types .= "sss";
    $keyword_like = "%" . $search_keyword . "%";
    $param_values[] = $keyword_like;
    $param_values[] = $keyword_like;
    $param_values[] = $keyword_like;
    $filter_query_string .= "&keyword=" . urlencode($search_keyword);
    $export_query_string  .= "&keyword=" . urlencode($search_keyword);
}

if (!empty($mc_filter)) {
    if (!$has_specific_mc || in_array((int)$mc_filter, $user_mc_ids)) {
        $where_clauses[] = "s.micro_cluster_id = ?";
        $param_types .= "i";
        $param_values[] = (int)$mc_filter;
        $filter_query_string .= "&mc_id=" . urlencode($mc_filter);
        $export_query_string  .= "&mc_id=" . urlencode($mc_filter);
    }
}

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

// Query untuk menghitung total data
$count_sql = "SELECT COUNT(o.id) as total 
              FROM outlets o 
              LEFT JOIN sites s ON o.site_id = s.id 
              LEFT JOIN branches b ON s.branch_id = b.id 
              LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id" . $where_sql;
$stmt_count = $mysqli->prepare($count_sql);
if ($stmt_count) {
    if (!empty($param_values)) {
        $stmt_count->bind_param($param_types, ...$param_values);
    }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
} else {
    $total_records = 0;
}
$total_pages = ceil($total_records / $records_per_page);

// Query untuk mengambil data outlet
$sql = "SELECT o.id, o.id_outlet, o.nama_outlet, o.Id_Outlet_Nama_Outlet, o.brand,
               s.site_name, s.site_id as site_code, s.kecamatan, s.kabupaten,
               b.nama_branch, mc.nama_micro_cluster
        FROM outlets o 
        LEFT JOIN sites s ON o.site_id = s.id
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id" 
        . $where_sql . " ORDER BY o.nama_outlet ASC LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param($param_types_page, ...$param_values_page);
    $stmt->execute();
    $outlets_result = $stmt->get_result();
    $stmt->close();
} else {
    $outlets_result = false;
}

// Ambil daftar micro cluster yang sudah dipilih jika ada
if ($has_specific_mc) {
    $mc_in = implode(',', $user_mc_ids);
    $initial_mcs_query = $mysqli->query("SELECT id, nama_micro_cluster FROM micro_clusters WHERE id IN ($mc_in) ORDER BY nama_micro_cluster");
} else {
    $initial_mcs_query = $mysqli->query("SELECT id, nama_micro_cluster FROM micro_clusters WHERE branch_id = " . (int)$user_branch_id . " ORDER BY nama_micro_cluster");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Penelusuran Outlet - <?= htmlspecialchars($app_name ?? 'Marcomm Apps') ?></title>
    <?php include 'components/head_shared.php'; ?>
</head>
<body class="min-h-screen">
    <!-- Aurora Background Blobs -->
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>

    <div class="flex">
        <!-- Sidebar -->
        <?php include 'components/sidebar_user.php'; ?>

        <!-- Main Content -->
        <main class="flex-grow p-4 lg:p-10 lg:ml-64 min-w-0">
            <!-- Header -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-3 text-slate-600 glass-card relative z-[60] active:scale-95 transition-transform"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Data Outlet</h2>
                        <p class="text-slate-500 font-medium text-sm">Lihat daftar outlet pada wilayah penugasan Anda.</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="process/export_outlets_csv.php?<?php echo htmlspecialchars(ltrim($export_query_string, '&')); ?>" 
                       class="px-5 py-3 bg-emerald-600 text-white font-bold text-sm rounded-2xl hover:bg-emerald-700 shadow-lg shadow-emerald-200 active:scale-95 transition-all flex items-center gap-2">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                </div>
            </header>

            <div class="max-w-7xl mx-auto space-y-8">

                <!-- Filter Card -->
                <div class="glass-card p-6 border border-white/50 shadow-xl shadow-blue-900/5">
                    <form action="user_outlets.php" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 items-end">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Cari ID/Nama/Site</label>
                            <input type="text" name="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" placeholder="Ketik sesuatu..." 
                                   class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Micro Cluster</label>
                            <select name="mc_id" id="mc_filter" class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none">
                                <option value="">Semua Micro Cluster</option>
                                <?php if ($initial_mcs_query) { while($mc = $initial_mcs_query->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($mc['id']); ?>" <?php if ($mc_filter == $mc['id']) echo 'selected'; ?>><?php echo htmlspecialchars($mc['nama_micro_cluster']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="flex-grow bg-slate-800 text-white font-bold py-3 px-4 rounded-2xl hover:bg-slate-900 transition-all text-sm shadow-lg shadow-slate-200 flex items-center justify-center gap-2">
                                <i class="fas fa-filter"></i> Terapkan
                            </button>
                            <a href="user_outlets.php" class="bg-white border border-slate-200 text-slate-600 font-bold py-3 px-6 rounded-2xl hover:bg-slate-50 transition-all text-sm flex items-center justify-center">
                                <i class="fas fa-undo"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Table Section -->
                <div>
                    <div class="flex flex-col md:flex-row justify-between items-center mb-6 px-1 gap-4">
                        <div class="flex items-center gap-4 text-sm font-medium text-slate-500 bg-white/50 backdrop-blur-md rounded-2xl p-2 border border-white/50 shadow-sm">
                            <select onchange="window.location.href = 'user_outlets.php?page=1<?php echo str_replace(['&limit='.$records_per_page, '&page='.$page], '', $filter_query_string); ?>&limit=' + this.value" 
                                    class="bg-transparent border-none focus:ring-0 font-bold text-slate-800 cursor-pointer">
                                <option value="10" <?php if($records_per_page == 10) echo 'selected'; ?>>10</option>
                                <option value="25" <?php if($records_per_page == 25) echo 'selected'; ?>>25</option>
                                <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50</option>
                                <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100</option>
                            </select>
                            <span class="pr-4 border-l border-slate-200 pl-4">Menampilkan <strong class="text-slate-800"><?php echo $total_records; ?></strong> total data</span>
                        </div>
                    </div>

                    <div class="glass-card rounded-3xl border border-white/50 overflow-hidden shadow-xl shadow-blue-900/5">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50/50 border-b border-slate-100">
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">ID & Nama Outlet</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Wilayah (Branch/MC)</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Site & Lokasi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white/30 backdrop-blur-sm">
                                    <?php if ($outlets_result && $outlets_result->num_rows > 0): ?>
                                        <?php while($outlet = $outlets_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors group">
                                            <td class="py-5 px-6">
                                                <p class="font-bold text-slate-800 line-clamp-1"><?php echo htmlspecialchars($outlet['nama_outlet']); ?></p>
                                                <div class="flex items-center gap-2 mt-1">
                                                    <span class="text-xs font-medium text-slate-400"><?php echo htmlspecialchars($outlet['id_outlet']); ?></span>
                                                    <span class="px-2 py-0.5 bg-blue-50 text-blue-600 rounded text-[9px] font-black uppercase tracking-tighter border border-blue-100"><?php echo htmlspecialchars($outlet['brand'] ?? '-'); ?></span>
                                                </div>
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="space-y-1">
                                                    <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($outlet['nama_branch'] ?? '-'); ?></p>
                                                    <p class="text-[10px] font-medium text-slate-400 flex items-center gap-1"><i class="fas fa-map-marker-alt text-[8px]"></i> <?php echo htmlspecialchars($outlet['nama_micro_cluster'] ?? '-'); ?></p>
                                                </div>
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="space-y-1">
                                                    <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($outlet['site_name'] ?? '-'); ?> <span class="text-[10px] font-normal text-slate-400">(<?php echo htmlspecialchars($outlet['site_code'] ?? '-'); ?>)</span></p>
                                                    <p class="text-[10px] font-medium text-slate-400 uppercase tracking-tighter"><?php echo htmlspecialchars($outlet['kecamatan'] ?? '-'); ?> | <?php echo htmlspecialchars($outlet['kabupaten'] ?? '-'); ?></p>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="py-12 text-center text-slate-400 font-bold text-sm">Tidak ada data outlet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Paginasi -->
                <?php if($total_pages > 1): ?>
                <div class="mt-10 flex justify-center pb-10">
                    <nav class="flex items-center gap-2" aria-label="Pagination">
                        <?php
                            $max_pages_to_show = 5;
                            $start_page = max(1, $page - floor($max_pages_to_show / 2));
                            $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                            $start_page = max(1, $end_page - $max_pages_to_show + 1);

                            if ($page > 1) {
                                echo '<a href="?page='.($page-1).$filter_query_string.'" class="h-10 px-4 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold"><i class="fas fa-chevron-left"></i></a>';
                            }

                            if ($start_page > 1) {
                                echo '<a href="?page=1'.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold">1</a>';
                                if ($start_page > 2) echo '<span class="h-10 w-10 flex items-center justify-center text-slate-400">...</span>';
                            }

                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $active = ($i == $page) ? 'bg-slate-800 text-white shadow-lg shadow-slate-200' : 'glass-card text-slate-600 hover:bg-slate-800 hover:text-white';
                                echo '<a href="?page='.$i.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center rounded-xl '.$active.' transition-all text-sm font-bold">'.$i.'</a>';
                            }

                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) echo '<span class="h-10 w-10 flex items-center justify-center text-slate-400">...</span>';
                                echo '<a href="?page='.$total_pages.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold">'.$total_pages.'</a>';
                            }

                            if ($page < $total_pages) {
                                echo '<a href="?page='.($page+1).$filter_query_string.'" class="h-10 px-4 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold"><i class="fas fa-chevron-right"></i></a>';
                            }
                        ?>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
