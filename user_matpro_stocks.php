<?php
// user_matpro_stocks.php
require_once 'config/database.php';

// Cek jika user tidak login atau bukan 'user'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}

// Helper function for bind_param with dynamic arguments
if (!function_exists('ref_values')) {
    function ref_values(&$arr){
        $refs = [];
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
}

// Mengambil data user dari session
$user_id = (int)$_SESSION['id'];

// Inisialisasi pesan
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// --- Logika Paginasi & Filter ---
$records_per_page = isset($_GET['limit']) && in_array($_GET['limit'], [10, 25, 50, 100]) ? (int)$_GET['limit'] : 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// REVISED: Query sekarang selalu memfilter berdasarkan user_id yang login
$where_clauses = ["ms.user_id = ?"];
$param_types = "i";
$param_values = [$user_id];
$filter_query_string = "";

$search_keyword = $_GET['keyword'] ?? '';
$project_filter = $_GET['project_name'] ?? '';
$type_filter = $_GET['type_name'] ?? '';
$stock_status_filter = $_GET['stock_status'] ?? 'all';

if (!empty($search_keyword)) {
    $where_clauses[] = "(ms.project_name LIKE ? OR ms.type_name LIKE ?)";
    $param_types .= "ss";
    $keyword_like = "%" . $search_keyword . "%";
    $param_values[] = $keyword_like;
    $param_values[] = $keyword_like;
    $filter_query_string .= "&keyword=" . urlencode($search_keyword);
}
if (!empty($project_filter)) {
    $where_clauses[] = "ms.project_name = ?";
    $param_types .= "s";
    $param_values[] = $project_filter;
    $filter_query_string .= "&project_name=" . urlencode($project_filter);
}
if (!empty($type_filter)) {
    $where_clauses[] = "ms.type_name = ?";
    $param_types .= "s";
    $param_values[] = $type_filter;
    $filter_query_string .= "&type_name=" . urlencode($type_filter);
}
if ($stock_status_filter === 'available') {
    $where_clauses[] = "ms.stock_quantity > 0";
    $filter_query_string .= "&stock_status=available";
} elseif ($stock_status_filter === 'empty') {
    $where_clauses[] = "ms.stock_quantity <= 0";
    $filter_query_string .= "&stock_status=empty";
} else {
    $filter_query_string .= "&stock_status=all";
}


$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

$count_sql = "SELECT COUNT(ms.id) as total 
              FROM matpro_stocks ms
              LEFT JOIN branches b ON ms.branch_id = b.id
              LEFT JOIN micro_clusters mc ON ms.micro_cluster_id = mc.id" . $where_sql;
$stmt_count = $mysqli->prepare($count_sql);
if ($stmt_count) {
    if (!empty($param_values)) {
        call_user_func_array([$stmt_count, 'bind_param'], array_merge([$param_types], ref_values($param_values)));
    }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
} else {
    $total_records = 0;
    $error_message = "Gagal menghitung total stok Matpro: " . $mysqli->error;
}
$total_pages = ceil($total_records / $records_per_page);

$sql = "SELECT ms.id, ms.stock_quantity, ms.project_name, b.brand, ms.type_name, 
               b.nama_branch, mc.nama_micro_cluster
        FROM matpro_stocks ms
        LEFT JOIN branches b ON ms.branch_id = b.id
        LEFT JOIN micro_clusters mc ON ms.micro_cluster_id = mc.id" 
        . $where_sql . " ORDER BY ms.project_name ASC, ms.type_name ASC, b.nama_branch ASC LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    if (!empty($param_values_page)) {
        call_user_func_array([$stmt, 'bind_param'], array_merge([$param_types_page], ref_values($param_values_page)));
    }
    $stmt->execute();
    $stocks_result = $stmt->get_result();
    $stmt->close();
} else {
    $stocks_result = false;
    $error_message = "Gagal mengambil data stok Matpro: " . $mysqli->error;
}

// REVISED: Query untuk filter dropdown sekarang juga berdasarkan user yang login
$projects_for_filter_result = null;
$sql_projects_filter = "SELECT DISTINCT ms.project_name FROM matpro_stocks ms WHERE ms.user_id = ? ORDER BY ms.project_name";
$stmt_projects_filter = $mysqli->prepare($sql_projects_filter);
if ($stmt_projects_filter) {
    $stmt_projects_filter->bind_param("i", $user_id);
    $stmt_projects_filter->execute();
    $projects_for_filter_result = $stmt_projects_filter->get_result();
    $stmt_projects_filter->close();
} else {
    $error_message .= " Gagal menyiapkan query proyek untuk filter: " . $mysqli->error;
}

$types_for_filter_result = null;
$sql_types_filter = "SELECT DISTINCT ms.type_name FROM matpro_stocks ms WHERE ms.user_id = ? ORDER BY ms.type_name";
$stmt_types_filter = $mysqli->prepare($sql_types_filter);
if ($stmt_types_filter) {
    $stmt_types_filter->bind_param("i", $user_id);
    $stmt_types_filter->execute();
    $types_for_filter_result = $stmt_types_filter->get_result();
    $stmt_types_filter->close();
} else {
    $error_message .= " Gagal menyiapkan query jenis untuk filter: " . $mysqli->error;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Stok Matpro - <?php echo strip_tags($app_name); ?></title>
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
                    <button onclick="toggleSidebar()" class="lg:hidden p-3 text-slate-600 glass-card"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Sisa Stok Matpro</h2>
                        <p class="text-slate-500 font-medium">Pantau alokasi sisa material promosi Anda secara real-time.</p>
                    </div>
                </div>
            </header>

            <div class="max-w-7xl mx-auto">
                <?php if ($success_message): ?>
                    <div class="glass-card bg-emerald-50/50 border-emerald-200 p-4 mb-8 flex items-center gap-3">
                        <div class="h-10 w-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle"></i></div>
                        <p class="text-emerald-800 font-bold"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="glass-card bg-red-50/50 border-red-200 p-4 mb-8 flex items-center gap-3">
                        <div class="h-10 w-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center"><i class="fas fa-exclamation-triangle"></i></div>
                        <p class="text-red-800 font-bold"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Advanced Filter Card -->
                <div class="glass-card p-8 mb-10">
                    <div class="flex items-center gap-3 mb-8">
                        <div class="h-10 w-4 bg-blue-600 rounded-full"></div>
                        <h3 class="text-xl font-extrabold text-slate-800 tracking-tight">Filter Stok</h3>
                    </div>

                    <form action="user_matpro_stocks.php" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 items-end">
                        <div class="space-y-2">
                            <label for="keyword" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Cari Proyek/Jenis</label>
                            <div class="relative group">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                    <i class="fas fa-search"></i>
                                </span>
                                <input type="text" name="keyword" id="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" 
                                       placeholder="Ketik proyek..."
                                       class="w-full bg-slate-50 border border-slate-200 rounded-2xl pl-12 pr-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all placeholder:text-slate-400">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label for="project_name" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Proyek</label>
                            <select name="project_name" id="project_name" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                <option value="">Semua Proyek</option>
                                <?php if ($projects_for_filter_result): mysqli_data_seek($projects_for_filter_result, 0); while($project = $projects_for_filter_result->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($project['project_name']); ?>" <?php if ($project_filter == $project['project_name']) echo 'selected'; ?>><?php echo htmlspecialchars($project['project_name']); ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="type_name" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Jenis Matpro</label>
                            <select name="type_name" id="type_name" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                <option value="">Semua Jenis</option>
                                <?php if ($types_for_filter_result): mysqli_data_seek($types_for_filter_result, 0); while($type = $types_for_filter_result->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($type['type_name']); ?>" <?php if ($type_filter == $type['type_name']) echo 'selected'; ?>><?php echo htmlspecialchars($type['type_name']); ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="stock_status" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Status Stok</label>
                            <select name="stock_status" id="stock_status" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                <option value="all" <?php if($stock_status_filter == 'all') echo 'selected'; ?>>Semua Status</option>
                                <option value="available" <?php if($stock_status_filter == 'available') echo 'selected'; ?>>Tersedia (>0)</option>
                                <option value="empty" <?php if($stock_status_filter == 'empty') echo 'selected'; ?>>Kosong (≤0)</option>
                            </select>
                        </div>

                        <div class="lg:col-span-4 flex justify-end gap-3 mt-4">
                            <a href="user_matpro_stocks.php" class="px-8 py-3 bg-slate-100 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-200 transition-all">
                                <i class="fas fa-undo mr-2"></i> Reset
                            </a>
                            <button type="submit" class="px-8 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 active:scale-95 transition-all">
                                <i class="fas fa-filter mr-2"></i> Terapkan Filter
                            </button>
                        </div>
                    </form>
                </div>

                <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-6">
                    <div class="flex items-center gap-3 bg-white/50 backdrop-blur-md px-4 py-2 rounded-2xl border border-white/20 shadow-sm">
                        <label for="limit" class="text-xs font-black text-slate-400 uppercase tracking-widest">Tampilkan:</label>
                        <select id="limit" onchange="window.location.href = 'user_matpro_stocks.php?page=1<?php echo str_replace(['&limit='.$records_per_page, '&page='.$page], '', $filter_query_string); ?>&limit=' + this.value" class="bg-transparent border-none text-sm font-bold text-slate-700 focus:ring-0 cursor-pointer">
                            <option value="10" <?php if($records_per_page == 10) echo 'selected'; ?>>10 Data</option>
                            <option value="25" <?php if($records_per_page == 25) echo 'selected'; ?>>25 Data</option>
                            <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50 Data</option>
                            <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100 Data</option>
                        </select>
                    </div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest bg-slate-100 px-4 py-2 rounded-full border border-slate-200">
                        Total: <span class="text-slate-800"><?php echo number_format($total_records); ?></span> Alokasi Stok
                    </p>
                </div>

                <div class="glass-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50/50">
                                <tr>
                                    <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Proyek</th>
                                    <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Jenis</th>
                                    <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Brand</th>
                                    <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Branch</th>
                                    <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Micro Cluster</th>
                                    <th class="text-center py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Sisa Stok</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white/30">
                                <?php if ($stocks_result && $stocks_result->num_rows > 0): ?>
                                    <?php while($stock = $stocks_result->fetch_assoc()): ?>
                                    <tr class="hover:bg-blue-50/30 transition-colors">
                                        <td class="py-4 px-6">
                                            <span class="text-sm font-extrabold text-slate-800"><?php echo htmlspecialchars($stock['project_name']); ?></span>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($stock['type_name']); ?></span>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 bg-slate-100 text-slate-600 rounded-lg"><?php echo htmlspecialchars($stock['brand']); ?></span>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="text-sm font-medium text-slate-600"><?php echo htmlspecialchars($stock['nama_branch']); ?></span>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="text-sm font-medium text-slate-500"><?php echo htmlspecialchars($stock['nama_micro_cluster'] ?? '-'); ?></span>
                                        </td>
                                        <td class="py-4 px-6 text-center">
                                            <?php 
                                            $stock_qty = (int)$stock['stock_quantity'];
                                            $stock_color = ($stock_qty > 10) ? 'bg-emerald-50 text-emerald-600 border-emerald-100' : ($stock_qty > 0 ? 'bg-amber-50 text-amber-600 border-amber-100' : 'bg-red-50 text-red-600 border-red-100');
                                            ?>
                                            <span class="inline-flex items-center justify-center min-w-[64px] px-3 py-1.5 <?php echo $stock_color; ?> rounded-xl text-sm font-black border shadow-sm">
                                                <?php echo number_format($stock_qty); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="py-12 text-center">
                                            <div class="flex flex-col items-center gap-4">
                                                <div class="h-16 w-16 bg-slate-50 text-slate-300 rounded-2xl flex items-center justify-center text-2xl">
                                                    <i class="fas fa-boxes"></i>
                                                </div>
                                                <p class="text-slate-400 font-bold text-sm">Tidak ada data stok Matpro yang dialokasikan untuk Anda.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-10 flex justify-center">
                    <nav class="flex items-center gap-2" aria-label="Pagination">
                        <a href="?page=<?php echo max(1, $page-1); ?>&<?php echo $filter_query_string; ?>&limit=<?php echo $records_per_page; ?>" 
                           class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-500 hover:text-blue-600 hover:border-blue-200 transition-all <?php echo $page <= 1 ? 'pointer-events-none opacity-50' : ''; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>

                        <div class="flex items-center gap-1.5 px-2 bg-white/50 backdrop-blur-md rounded-2xl border border-white/20 p-1 shadow-sm">
                            <?php
                            $max_pages_to_show = 5;
                            $start_page = max(1, $page - floor($max_pages_to_show / 2));
                            $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                            $start_page = max(1, $end_page - $max_pages_to_show + 1);

                            if ($start_page > 1) {
                                echo '<a href="?page=1&'.$filter_query_string.'&limit='.$records_per_page.'" class="h-9 min-w-[36px] px-2 flex items-center justify-center rounded-xl text-xs font-black text-slate-600 hover:bg-white transition-all">1</a>';
                                if ($start_page > 2) echo '<span class="px-1 text-slate-400">...</span>';
                            }

                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $activeClass = ($i == $page) ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'text-slate-600 hover:bg-white hover:text-blue-600';
                                echo '<a href="?page='.$i.'&'.$filter_query_string.'&limit='.$records_per_page.'" class="h-9 min-w-[36px] px-2 flex items-center justify-center rounded-xl text-xs font-black transition-all '.$activeClass.'">'.$i.'</a>';
                            }

                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) echo '<span class="px-1 text-slate-400">...</span>';
                                echo '<a href="?page='.$total_pages.'&'.$filter_query_string.'&limit='.$records_per_page.'" class="h-9 min-w-[36px] px-2 flex items-center justify-center rounded-xl text-xs font-black text-slate-600 hover:bg-white transition-all">'.$total_pages.'</a>';
                            }
                            ?>
                        </div>

                        <a href="?page=<?php echo min($total_pages, $page+1); ?>&<?php echo $filter_query_string; ?>&limit=<?php echo $records_per_page; ?>" 
                           class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-500 hover:text-blue-600 hover:border-blue-200 transition-all <?php echo $page >= $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
        </div>
    </div>
</body>
</html>
