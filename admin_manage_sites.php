<?php
// admin_manage_sites.php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

// Inisialisasi pesan
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// --- Logika Paginasi & Filter ---
$records_per_page = isset($_GET['limit']) && in_array($_GET['limit'], [10, 25, 50, 100]) ? (int)$_GET['limit'] : 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$where_clauses = [];
$param_types = "";
$param_values = [];
$filter_query_string = http_build_query(array_filter($_GET, fn($key) => $key !== 'page', ARRAY_FILTER_USE_KEY));
$export_query_string  = http_build_query(array_filter($_GET, fn($key) => !in_array($key, ['page', 'limit']), ARRAY_FILTER_USE_KEY));

$search_keyword = $_GET['keyword'] ?? '';
$brand_filter = $_GET['brand'] ?? '';
$branch_filter = $_GET['branch_id'] ?? '';
$mc_filter = $_GET['mc_id'] ?? '';

// Filter pencarian
if (!empty($search_keyword)) {
    $where_clauses[] = "(s.site_id LIKE ? OR s.site_name LIKE ? OR s.kecamatan LIKE ? OR s.kabupaten LIKE ?)";
    $param_types .= "ssss";
    $keyword_like = "%" . $search_keyword . "%";
    array_push($param_values, $keyword_like, $keyword_like, $keyword_like, $keyword_like);
}
if (!empty($brand_filter)) {
    $where_clauses[] = "b.brand = ?";
    $param_types .= "s";
    $param_values[] = $brand_filter;
}
if (!empty($branch_filter)) {
    $where_clauses[] = "s.branch_id = ?";
    $param_types .= "i";
    $param_values[] = (int)$branch_filter;
}
if (!empty($mc_filter)) {
    $where_clauses[] = "s.micro_cluster_id = ?";
    $param_types .= "i";
    $param_values[] = (int)$mc_filter;
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
    $error_message = "Gagal menghitung total site: " . $mysqli->error;
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
    $error_message = "Gagal mengambil data site: " . $mysqli->error;
}

// Ambil data untuk filter dropdown
// PERBAIKAN: Hanya tampilkan brand '3ID' dan 'IM3' pada filter
$brands_for_filter = ['3ID', 'IM3'];

// PERBAIKAN: Hanya ambil data branch/mc jika filternya aktif, untuk di-render di HTML
$initial_branches_query = null;
if (!empty($brand_filter)) {
    $stmt_branches = $mysqli->prepare("SELECT id, nama_branch FROM branches WHERE brand = ? ORDER BY nama_branch");
    if($stmt_branches) {
        $stmt_branches->bind_param("s", $brand_filter);
        $stmt_branches->execute();
        $initial_branches_query = $stmt_branches->get_result();
        $stmt_branches->close();
    }
}

$initial_mcs_query = null;
if (!empty($branch_filter)) {
    $stmt_mcs = $mysqli->prepare("SELECT id, nama_micro_cluster FROM micro_clusters WHERE branch_id = ? ORDER BY nama_micro_cluster");
    if($stmt_mcs) {
        $stmt_mcs->bind_param("i", $branch_filter);
        $stmt_mcs->execute();
        $initial_mcs_query = $stmt_mcs->get_result();
        $stmt_mcs->close();
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Manajemen Site - <?php echo strip_tags($app_name); ?></title>
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
        <?php include 'components/sidebar_admin.php'; ?>

        <!-- Main Content -->
        <main class="flex-grow p-4 lg:p-10 lg:ml-72 min-w-0">
            <!-- Header -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-3 text-slate-600 glass-card relative z-[60] active:scale-95 transition-transform"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Manajemen Site</h2>
                        <p class="text-slate-500 font-medium">Kelola titik lokasi site, alokasi wilayah cluster, dan data geografis sistem.</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <a href="process/export_sites_csv.php?<?php echo htmlspecialchars($export_query_string); ?>" 
                       class="px-5 py-3 bg-emerald-600 text-white font-bold text-sm rounded-2xl hover:bg-emerald-700 shadow-lg shadow-emerald-200 active:scale-95 transition-all flex items-center gap-2">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                    <a href="admin_import_sites.php" class="px-5 py-3 bg-white/50 backdrop-blur-md border border-slate-200 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-file-import"></i> Import Site
                    </a>
                    <a href="admin_site_form.php" class="px-5 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all flex items-center gap-2">
                        <i class="fas fa-plus"></i> Tambah Site
                    </a>
                </div>
            </header>

            <div class="max-w-7xl mx-auto">
                <!-- Advanced Multi-Tier Filter Card -->
                <div class="glass-card p-8 mb-10">
                    <div class="flex items-center gap-3 mb-8">
                        <div class="h-10 w-4 bg-blue-600 rounded-full"></div>
                        <h3 class="text-xl font-extrabold text-slate-800 tracking-tight">Filter Site & Lokasi</h3>
                    </div>

                    <form action="admin_manage_sites.php" method="GET" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
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
                                <label for="brand_filter" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Brand</label>
                                <div class="relative">
                                    <select name="brand" id="brand_filter" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                        <option value="">Semua Brand</option>
                                        <?php if (!empty($brands_for_filter)): foreach($brands_for_filter as $brand_option): ?>
                                            <option value="<?php echo htmlspecialchars($brand_option); ?>" <?php if ($brand_filter == $brand_option) echo 'selected'; ?>><?php echo htmlspecialchars($brand_option); ?></option>
                                        <?php endforeach; endif; ?>
                                    </select>
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label for="branch_filter" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Branch</label>
                                <div class="relative">
                                    <select name="branch_id" id="branch_filter" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed" <?php if(empty($brand_filter)) echo 'disabled'; ?>>
                                        <option value="">Pilih Brand dulu</option>
                                        <?php if ($initial_branches_query): mysqli_data_seek($initial_branches_query, 0); while($branch = $initial_branches_query->fetch_assoc()): ?>
                                            <option value="<?php echo htmlspecialchars($branch['id']); ?>" <?php if ($branch_filter == $branch['id']) echo 'selected'; ?>><?php echo htmlspecialchars($branch['nama_branch']); ?></option>
                                        <?php endwhile; endif; ?>
                                    </select>
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label for="mc_filter" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Micro Cluster</label>
                                <div class="relative">
                                    <select name="mc_id" id="mc_filter" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed" <?php if(empty($branch_filter)) echo 'disabled'; ?>>
                                        <option value="">Pilih Branch dulu</option>
                                         <?php if ($initial_mcs_query): mysqli_data_seek($initial_mcs_query, 0); while($mc = $initial_mcs_query->fetch_assoc()): ?>
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
                            <a href="admin_manage_sites.php" class="px-8 py-3 bg-slate-100 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-200 transition-all text-center">
                                <i class="fas fa-undo mr-2"></i> Reset
                            </a>
                            <button type="submit" class="px-10 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 active:scale-95 transition-all">
                                <i class="fas fa-filter mr-2"></i> Terapkan Filter
                            </button>
                        </div>
                    </form>
                </div>

                <?php if (!empty($success_message)): ?>
                    <div class="glass-card bg-emerald-50/50 border-emerald-200 p-4 mb-8 flex items-center gap-3">
                        <div class="h-10 w-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle"></i></div>
                        <p class="text-emerald-800 font-bold"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="glass-card bg-red-50/50 border-red-200 p-4 mb-8 flex items-center gap-3">
                        <div class="h-10 w-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center"><i class="fas fa-exclamation-triangle"></i></div>
                        <p class="text-red-800 font-bold"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>

                <form id="mainForm" action="process/admin_site_process.php" method="POST" class="space-y-8">
                    <input type="hidden" name="action" id="main_action" value="">
                    
                    <div class="flex flex-col md:flex-row justify-between items-center gap-6">
                        <div class="flex items-center gap-4 bg-white/50 backdrop-blur-md px-5 py-2 rounded-2xl border border-slate-200">
                            <label for="limit" class="text-xs font-black text-slate-400 uppercase tracking-widest">Tampilkan</label>
                            <select id="limit" onchange="window.location.href = 'admin_manage_sites.php?page=1&<?php echo $filter_query_string; ?>&limit=' + this.value" class="bg-transparent text-sm font-bold text-slate-700 outline-none cursor-pointer">
                                <option value="10" <?php if($records_per_page == 10) echo 'selected'; ?>>10 baris</option>
                                <option value="25" <?php if($records_per_page == 25) echo 'selected'; ?>>25 baris</option>
                                <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50 baris</option>
                                <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100 baris</option>
                            </select>
                            <div class="h-4 w-px bg-slate-200 mx-2"></div>
                            <span class="text-xs font-bold text-slate-500"><?php echo number_format($total_records); ?> Titik Lokasi</span>
                        </div>

                        <div class="flex gap-3">
                            <button type="button" id="bulkDeleteBtn" class="px-5 py-3 bg-red-50 text-red-600 border border-red-100 font-bold text-sm rounded-2xl hover:bg-red-600 hover:text-white disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center gap-2" disabled>
                                <i class="fas fa-trash-alt"></i> Hapus Terpilih
                            </button>
                            <button type="button" onclick="deleteAllSites()" class="px-5 py-3 bg-slate-900 text-white font-bold text-sm rounded-2xl hover:bg-black shadow-lg shadow-slate-200 transition-all flex items-center gap-2">
                                <i class="fas fa-dumpster"></i> Kosongkan Database
                            </button>
                        </div>
                    </div>

                    <div class="glass-card overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200">
                                <thead class="bg-slate-50/50">
                                    <tr>
                                        <th class="py-4 px-6 text-center w-12">
                                            <input type="checkbox" id="selectAllSites" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                        </th>
                                        <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Site ID</th>
                                        <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Nama Site</th>
                                        <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest w-40">Hierarki Bisnis</th>
                                        <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Lokasi Geografis</th>
                                        <th class="py-4 px-6 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white/30">
                                    <?php if ($sites_result && $sites_result->num_rows > 0): ?>
                                        <?php while($site = $sites_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-blue-50/30 transition-colors">
                                            <td class="py-4 px-6 text-center">
                                                <input type="checkbox" name="site_ids[]" value="<?php echo $site['id']; ?>" class="site-checkbox rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                            </td>
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
                                            <td class="py-4 px-6">
                                                <div class="flex justify-center items-center gap-3">
                                                    <a href="admin_site_form.php?id=<?php echo $site['id']; ?>" 
                                                       class="h-9 w-9 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 border border-amber-100 hover:bg-amber-100 transition-all" 
                                                       title="Edit">
                                                        <i class="fas fa-pencil-alt text-sm"></i>
                                                    </a>
                                                    <button type="button" onclick="deleteSingle(<?php echo $site['id']; ?>)" 
                                                            class="h-9 w-9 flex items-center justify-center rounded-xl bg-red-50 text-red-600 border border-red-100 hover:bg-red-600 hover:text-white transition-all" 
                                                            title="Hapus">
                                                        <i class="fas fa-trash-alt text-sm"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="py-20 text-center">
                                                <div class="flex flex-col items-center gap-4">
                                                    <div class="h-20 w-20 bg-slate-50 text-slate-300 rounded-3xl flex items-center justify-center text-3xl">
                                                        <i class="fas fa-map-marked-alt"></i>
                                                    </div>
                                                    <div>
                                                        <p class="text-slate-800 font-extrabold">Data tidak ditemukan</p>
                                                        <p class="text-slate-400 text-sm font-medium mt-1">Coba sesuaikan filter atau kata kunci pencarian Anda.</p>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </form>

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

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const mainForm = document.getElementById('mainForm');
        const mainActionInput = document.getElementById('main_action');
        const selectAllSitesCheckbox = document.getElementById('selectAllSites');
        const siteCheckboxes = document.querySelectorAll('.site-checkbox');
        const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

        function updateBulkDeleteButton() {
            bulkDeleteBtn.disabled = document.querySelectorAll('.site-checkbox:checked').length === 0;
        }

        selectAllSitesCheckbox.addEventListener('change', function() {
            siteCheckboxes.forEach(checkbox => { checkbox.checked = this.checked; });
            updateBulkDeleteButton();
        });

        siteCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                selectAllSitesCheckbox.checked = document.querySelectorAll('.site-checkbox:checked').length === siteCheckboxes.length;
                updateBulkDeleteButton();
            });
        });

        bulkDeleteBtn.addEventListener('click', function() {
            if (confirm('Konfirmasi Hapus Terpilih: Site yang dipilih akan dihapus permanen jika tidak memiliki outlet atau riwayat event terkait.')) {
                mainActionInput.value = 'bulk_delete';
                mainForm.submit();
            }
        });

        updateBulkDeleteButton();
        
        // Tiered Filter Logic
        const brandFilter = document.getElementById('brand_filter');
        const branchFilter = document.getElementById('branch_filter');
        const mcFilter = document.getElementById('mc_filter');

        function loadOptions(url, selectElement, prompt, selectedValue = null) {
            selectElement.innerHTML = `<option value="">Memuat...</option>`;
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    selectElement.innerHTML = `<option value="">${prompt}</option>`;
                    data.forEach(item => {
                        const optionText = item.nama_branch || item.nama_micro_cluster;
                        const option = new Option(optionText, item.id);
                        selectElement.add(option);
                    });
                    if (selectedValue) selectElement.value = selectedValue;
                    selectElement.disabled = false;
                })
                .catch(error => {
                    console.error('Error fetching options:', error);
                    selectElement.innerHTML = `<option value="">Gagal memuat</option>`;
                    selectElement.disabled = true;
                });
        }

        brandFilter.addEventListener('change', function() {
            const brand = this.value;
            branchFilter.innerHTML = '<option value="">Pilih Brand dulu</option>';
            branchFilter.disabled = true;
            mcFilter.innerHTML = '<option value="">Pilih Branch dulu</option>';
            mcFilter.disabled = true;

            if (brand) {
                loadOptions(`api_helper.php?action=get_branches_by_brand&brand=${brand}`, branchFilter, 'Semua Branch');
            }
        });

        branchFilter.addEventListener('change', function() {
            const branchId = this.value;
            mcFilter.innerHTML = '<option value="">Pilih Micro Cluster</option>';
            mcFilter.disabled = true;

            if (branchId) {
                loadOptions(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${branchId}`, mcFilter, 'Semua Micro Cluster');
            }
        });
    });

    function deleteSingle(id) {
        if (confirm('Konfirmasi Hapus: Site ini akan dihapus permanen jika tidak memiliki outlet atau riwayat event terkait.')) {
            const form = document.getElementById('mainForm');
            const mainActionInput = document.getElementById('main_action');
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = id;
            form.appendChild(idInput);

            mainActionInput.value = 'delete';
            form.submit();
        }
    }

    function deleteAllSites() {
        if (confirm('PERINGATAN KRITIKAL: Aksi ini tidak dapat dibatalkan. SEMUA site akan dihapus dari database jika tidak ada riwayat data terkait. Lanjutkan?')) {
            const form = document.getElementById('mainForm');
            const mainActionInput = document.getElementById('main_action');
            mainActionInput.value = 'delete_all_sites';
            form.submit();
        }
    }
    
    </script>
</body>
</html>
```
