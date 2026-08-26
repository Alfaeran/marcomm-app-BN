<?php
// user_sites.php
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
$filter_query_string = http_build_query(array_filter($_GET, fn($key) => !in_array($key, ['page']), ARRAY_FILTER_USE_KEY));
$export_query_string = http_build_query(array_filter($_GET, fn($key) => !in_array($key, ['page', 'limit']), ARRAY_FILTER_USE_KEY));

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
    $where_clauses[] = "(s.site_id LIKE ? OR s.site_name LIKE ? OR s.kecamatan LIKE ? OR s.kabupaten LIKE ?)";
    $param_types .= "ssss";
    $keyword_like = "%" . $search_keyword . "%";
    array_push($param_values, $keyword_like, $keyword_like, $keyword_like, $keyword_like);
}
if (!empty($mc_filter)) {
    // Validasi agar user tidak bisa memfilter ke MC yang bukan haknya
    if (!$has_specific_mc || in_array((int)$mc_filter, $user_mc_ids)) {
        $where_clauses[] = "s.micro_cluster_id = ?";
        $param_types .= "i";
        $param_values[] = (int)$mc_filter;
    }
}

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

// Query untuk menghitung total data
$count_sql = "SELECT COUNT(s.id) as total 
              FROM sites s 
              LEFT JOIN branches b ON s.branch_id = b.id" . $where_sql;
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

// Query untuk mengambil data site
$sql = "SELECT s.id, s.site_id, s.site_name, s.kecamatan, s.kabupaten, s.area, s.region,
               b.nama_branch, b.brand, mc.nama_micro_cluster
        FROM sites s 
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id" 
        . $where_sql . " ORDER BY s.site_name ASC LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    if (!empty($param_values_page)) {
        $stmt->bind_param($param_types_page, ...$param_values_page);
    }
    $stmt->execute();
    $sites_result = $stmt->get_result();
    $stmt->close();
} else {
    $sites_result = false;
}

// Ambil data untuk filter dropdown
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
    <title>Penelusuran Site - <?= htmlspecialchars($app_name ?? 'MarComm') ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Data Site</h2>
                        <p class="text-slate-500 font-medium">Lihat daftar site pada wilayah penugasan Anda.</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="process/export_sites_csv.php?<?php echo htmlspecialchars($export_query_string); ?>" 
                       class="px-5 py-3 bg-emerald-600 text-white font-bold text-sm rounded-2xl hover:bg-emerald-700 shadow-lg shadow-emerald-200 active:scale-95 transition-all flex items-center gap-2">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                </div>
            </header>

            <div class="max-w-7xl mx-auto">
                <!-- Advanced Multi-Tier Filter Card -->
                <div class="glass-card p-8 mb-10">
                    <div class="flex items-center gap-3 mb-8">
                        <div class="h-10 w-4 bg-blue-600 rounded-full"></div>
                        <h3 class="text-xl font-extrabold text-slate-800 tracking-tight">Penelusuran Lokasi</h3>
                    </div>

                    <form action="user_sites.php" method="GET" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label for="keyword" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Cari Site</label>
                                <div class="relative group">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                        <i class="fas fa-search"></i>
                                    </span>
                                    <input type="text" name="keyword" id="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" 
                                           placeholder="ID, Nama, atau Lokasi..."
                                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl pl-12 pr-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all placeholder:text-slate-400">
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label for="mc_filter" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Micro Cluster</label>
                                <div class="relative">
                                    <select name="mc_id" id="mc_filter" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                        <option value="">Semua Micro Cluster</option>
                                         <?php if ($initial_mcs_query): while($mc = $initial_mcs_query->fetch_assoc()): ?>
                                            <option value="<?php echo htmlspecialchars($mc['id']); ?>" <?php if ($mc_filter == $mc['id']) echo 'selected'; ?>><?php echo htmlspecialchars($mc['nama_micro_cluster']); ?></option>
                                        <?php endwhile; endif; ?>
                                    </select>
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <a href="user_sites.php" class="px-8 py-3 bg-slate-100 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-200 transition-all text-center">
                                <i class="fas fa-undo mr-2"></i> Reset
                            </a>
                            <button type="submit" class="px-10 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 active:scale-95 transition-all">
                                <i class="fas fa-filter mr-2"></i> Terapkan Filter
                            </button>
                        </div>
                    </form>
                </div>

                <div class="flex flex-col md:flex-row justify-between items-center gap-6 mb-6">
                    <div class="flex items-center gap-4 bg-white/50 backdrop-blur-md px-5 py-2 rounded-2xl border border-slate-200">
                        <label for="limit" class="text-xs font-black text-slate-400 uppercase tracking-widest">Tampilkan</label>
                        <select id="limit" onchange="window.location.href = 'user_sites.php?page=1&<?php echo $filter_query_string; ?>&limit=' + this.value" class="bg-transparent text-sm font-bold text-slate-700 outline-none cursor-pointer">
                            <option value="10" <?php if($records_per_page == 10) echo 'selected'; ?>>10 baris</option>
                            <option value="25" <?php if($records_per_page == 25) echo 'selected'; ?>>25 baris</option>
                            <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50 baris</option>
                            <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100 baris</option>
                        </select>
                        <div class="h-4 w-px bg-slate-200 mx-2"></div>
                        <span class="text-xs font-bold text-slate-500"><?php echo number_format($total_records); ?> Titik Lokasi</span>
                    </div>
                </div>

                <div class="glass-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50/50">
                                <tr>
                                    <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Site ID</th>
                                    <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Nama Site</th>
                                    <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest w-40">Hierarki Bisnis</th>
                                    <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Lokasi Geografis</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white/30">
                                <?php if ($sites_result && $sites_result->num_rows > 0): ?>
                                    <?php while($site = $sites_result->fetch_assoc()): ?>
                                    <tr class="hover:bg-blue-50/30 transition-colors">
                                        <td class="py-4 px-6">
                                            <span class="text-xs font-black text-slate-400">#<?php echo htmlspecialchars($site['site_id']); ?></span>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="text-sm font-extrabold text-slate-800 line-clamp-1"><?php echo htmlspecialchars($site['site_name']); ?></span>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="space-y-1">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="text-[9px] font-black uppercase px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded">
                                                        <?php echo htmlspecialchars($site['brand'] ?? '-'); ?>
                                                    </span>
                                                    <span class="text-[10px] font-extrabold text-slate-700 line-clamp-1"><?php echo htmlspecialchars($site['nama_branch'] ?? '-'); ?></span>
                                                </div>
                                                <p class="text-[10px] font-medium text-slate-400 italic line-clamp-1"><?php echo htmlspecialchars($site['nama_micro_cluster'] ?? '-'); ?></p>
                                            </div>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="flex items-start gap-2">
                                                <i class="fas fa-map-marker-alt text-blue-400 mt-1 text-xs"></i>
                                                <div>
                                                    <p class="text-[10px] font-black text-slate-700 uppercase leading-none"><?php echo htmlspecialchars($site['kabupaten'] ?? '-'); ?></p>
                                                    <p class="text-[10px] font-medium text-slate-400 mt-1"><?php echo htmlspecialchars($site['kecamatan'] ?? '-'); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="py-20 text-center">
                                            <div class="flex flex-col items-center gap-4">
                                                <div class="h-20 w-20 bg-slate-50 text-slate-300 rounded-3xl flex items-center justify-center text-3xl">
                                                    <i class="fas fa-map-marked-alt"></i>
                                                </div>
                                                <div>
                                                    <p class="text-slate-800 font-extrabold">Data tidak ditemukan</p>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if($total_pages > 1): ?>
                <div class="mt-10 flex flex-col md:flex-row items-center justify-between gap-6">
                    <p class="text-sm font-bold text-slate-500 order-2 md:order-1">
                        Menampilkan halaman <span class="text-slate-800"><?php echo $page; ?></span> dari <span class="text-slate-800"><?php echo $total_pages; ?></span>
                    </p>
                    
                    <nav class="flex items-center gap-2 order-1 md:order-2">
                        <?php
                        $max_pages_to_show = 5;
                        $start_page = max(1, $page - floor($max_pages_to_show / 2));
                        $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                        $start_page = max(1, $end_page - $max_pages_to_show + 1);

                        // Previous button
                        if ($page > 1): ?>
                            <a href="?page=<?php echo $page-1; ?>&<?php echo $filter_query_string; ?>" 
                               class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-all">
                                <i class="fas fa-chevron-left text-xs"></i>
                            </a>
                        <?php endif;

                        // First page and dots
                        if ($start_page > 1): ?>
                            <a href="?page=1&<?php echo $filter_query_string; ?>" 
                               class="h-10 px-4 flex items-center justify-center rounded-xl glass-card text-sm font-bold text-slate-600 hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-all">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="text-slate-400 font-bold px-1">...</span>
                            <?php endif;
                        endif;

                        // Page numbers
                        for ($i = $start_page; $i <= $end_page; $i++): 
                            $is_active = ($i == $page);
                        ?>
                            <a href="?page=<?php echo $i; ?>&<?php echo $filter_query_string; ?>" 
                               class="h-10 px-4 flex items-center justify-center rounded-xl <?php echo $is_active ? 'bg-blue-600 text-white shadow-lg shadow-blue-100 border-blue-600' : 'glass-card text-slate-600 hover:bg-blue-50 hover:text-blue-600'; ?> text-sm font-bold transition-all">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor;

                        // Last page and dots
                        if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="text-slate-400 font-bold px-1">...</span>
                            <?php endif; ?>
                            <a href="?page=<?php echo $total_pages; ?>&<?php echo $filter_query_string; ?>" 
                               class="h-10 px-4 flex items-center justify-center rounded-xl glass-card text-sm font-bold text-slate-600 hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-all"><?php echo $total_pages; ?></a>
                        <?php endif;

                        // Next button
                        if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page+1; ?>&<?php echo $filter_query_string; ?>" 
                               class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-all">
                                <i class="fas fa-chevron-right text-xs"></i>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
