<?php
// admin_laporan_marpro_receive.php
require_once 'config/database.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

if (!function_exists('ref_values')) {
    function ref_values(&$arr){
        $refs = [];
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
}

$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

$records_per_page = isset($_GET['limit']) && in_array($_GET['limit'], [10, 25, 50, 100]) ? (int)$_GET['limit'] : 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$where_clauses = [];
$param_types = "";
$param_values = [];
$filter_query_string = http_build_query(array_filter($_GET, fn($key) => !in_array($key, ['page', 'limit']), ARRAY_FILTER_USE_KEY));

$search_keyword = $_GET['keyword'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$brand_filter = $_GET['brand'] ?? '';
$branch_filter = $_GET['branch_id'] ?? '';
$project_filter = $_GET['project_name'] ?? '';
$type_filter = $_GET['type_name'] ?? '';

if (!empty($search_keyword)) {
    $where_clauses[] = "(u.username LIKE ? OR mr.project_name LIKE ? OR mr.type_name LIKE ? OR b.nama_branch LIKE ? OR mr.diterima_siapa LIKE ?)";
    $param_types .= "sssss";
    $keyword_like = "%" . $search_keyword . "%";
    array_push($param_values, $keyword_like, $keyword_like, $keyword_like, $keyword_like, $keyword_like);
}
if (!empty($start_date) && !empty($end_date)) {
    $where_clauses[] = "mr.tanggal_terima BETWEEN ? AND ?";
    $param_types .= "ss";
    $param_values[] = $start_date;
    $param_values[] = $end_date;
} elseif (!empty($start_date)) {
    $where_clauses[] = "mr.tanggal_terima >= ?";
    $param_types .= "s";
    $param_values[] = $start_date;
} elseif (!empty($end_date)) {
    $where_clauses[] = "mr.tanggal_terima <= ?";
    $param_types .= "s";
    $param_values[] = $end_date;
}
if (!empty($brand_filter)) { $where_clauses[] = "b.brand = ?"; $param_types .= "s"; $param_values[] = $brand_filter; }
if (!empty($branch_filter)) { $where_clauses[] = "mr.branch_id = ?"; $param_types .= "i"; $param_values[] = (int)$branch_filter; }
if (!empty($project_filter)) { $where_clauses[] = "mr.project_name = ?"; $param_types .= "s"; $param_values[] = $project_filter; }
if (!empty($type_filter)) { $where_clauses[] = "mr.type_name = ?"; $param_types .= "s"; $param_values[] = $type_filter; }

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

$count_sql = "SELECT COUNT(mr.id) as total
              FROM marpro_receives mr
              JOIN users u ON mr.user_id = u.id
              JOIN branches b ON mr.branch_id = b.id" . $where_sql;

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
    $error_message = "Gagal menghitung total data: " . $mysqli->error;
}
$total_pages = ceil($total_records / $records_per_page);

$sql = "SELECT mr.*, u.username, u.nama as user_nama, b.nama_branch, b.brand
        FROM marpro_receives mr
        JOIN users u ON mr.user_id = u.id
        JOIN branches b ON mr.branch_id = b.id"
        . $where_sql . " ORDER BY mr.tanggal_terima DESC, mr.id DESC LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    if (!empty($param_values_page)) {
        call_user_func_array([$stmt, 'bind_param'], array_merge([$param_types_page], ref_values($param_values_page)));
    }
    $stmt->execute();
    $receives_result = $stmt->get_result();
    $stmt->close();
} else {
    $receives_result = false;
    $error_message = "Gagal mengambil data laporan: " . $mysqli->error;
}

$projects_for_filter_result = $mysqli->query("SELECT DISTINCT project_name FROM matpro_stocks WHERE is_active = 1 ORDER BY project_name");
$types_for_filter_result = $mysqli->query("SELECT DISTINCT type_name FROM matpro_stocks WHERE is_active = 1 ORDER BY type_name");
$brands_for_filter = $mysqli->query("SELECT DISTINCT brand FROM branches WHERE brand IS NOT NULL AND brand != '' ORDER BY brand");
$branches_for_filter = $mysqli->query("SELECT id, nama_branch FROM branches ORDER BY nama_branch");

$app_name = get_setting($mysqli, 'app_name') ?: 'MarComm App';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Laporan Penerimaan Marpro - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
</head>
<body class="min-h-screen">
    <!-- Aurora Background Blobs -->
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>

    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar Overlay -->
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>

        <!-- Sidebar -->
        <?php include 'components/sidebar_admin.php'; ?>

        <!-- Main Content -->
        <main class="flex-grow p-4 lg:p-10 lg:ml-72 overflow-y-auto min-w-0">
            <!-- Header -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 text-slate-600 bg-white shadow-sm border rounded-lg relative z-[60] active:scale-95 transition-transform"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Laporan Penerimaan Marpro</h2>
                        <p class="text-slate-500 font-medium text-sm">Monitor barang masuk di tingkat Branch dari gudang utama/mitra.</p>
                    </div>
                </div>
            </header>

            <!-- Success/Error Messages -->
            <?php if (!empty($success_message)): ?>
            <div class="glass-card border-l-4 border-emerald-500 text-emerald-700 p-6 mb-8 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                <div class="h-10 w-10 rounded-full bg-emerald-100 flex items-center justify-center shrink-0"><i class="fas fa-check"></i></div>
                <p class="font-bold"><?php echo $success_message; ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="glass-card border-l-4 border-red-500 text-red-700 p-6 mb-8 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                <div class="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center shrink-0"><i class="fas fa-exclamation-triangle"></i></div>
                <p class="font-bold"><?php echo $error_message; ?></p>
            </div>
            <?php endif; ?>

            <!-- Filter Panel -->
            <div class="glass-card rounded-3xl p-8 mb-10 border border-white/50">
                <div class="flex items-center gap-3 mb-8">
                    <div class="h-10 w-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-sm">
                        <i class="fas fa-filter text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-800 tracking-tight">Filter Penerimaan</h3>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Saring Laporan Penerimaan Marpro</p>
                    </div>
                </div>

                <form id="filterForm" action="admin_laporan_marpro_receive.php" method="GET" class="space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="space-y-2">
                            <label for="keyword" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Pencarian</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" name="keyword" id="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" 
                                       placeholder="Proyek, Jenis, User, Branch..."
                                       class="w-full pl-10 pr-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label for="start_date" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Mulai Tanggal</label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                                   class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>

                        <div class="space-y-2">
                            <label for="end_date" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Hingga Tanggal</label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                                   class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>

                        <div class="space-y-2">
                            <label for="project_name" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Filter Proyek</label>
                            <select name="project_name" id="project_name" class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all appearance-none cursor-pointer">
                                <option value="">Semua Proyek</option>
                                <?php if ($projects_for_filter_result) { mysqli_data_seek($projects_for_filter_result, 0); while($project = $projects_for_filter_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($project['project_name']); ?>" <?php if ($project_filter == $project['project_name']) echo 'selected'; ?>><?php echo htmlspecialchars($project['project_name']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="type_name" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Filter Jenis</label>
                            <select name="type_name" id="type_name" class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all appearance-none cursor-pointer">
                                <option value="">Semua Jenis</option>
                                <?php if ($types_for_filter_result) { mysqli_data_seek($types_for_filter_result, 0); while($type = $types_for_filter_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($type['type_name']); ?>" <?php if ($type_filter == $type['type_name']) echo 'selected'; ?>><?php echo htmlspecialchars($type['type_name']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="branch_id" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Filter Branch</label>
                            <select name="branch_id" id="branch_id" class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all appearance-none cursor-pointer">
                                <option value="">Semua Branch</option>
                                <?php if ($branches_for_filter) { mysqli_data_seek($branches_for_filter, 0); while($branch = $branches_for_filter->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($branch['id']); ?>" <?php if ($branch_filter == $branch['id']) echo 'selected'; ?>><?php echo htmlspecialchars($branch['nama_branch']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="brand" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Filter Brand</label>
                            <select name="brand" id="brand" class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all appearance-none cursor-pointer">
                                <option value="">Semua Brand</option>
                                <?php if ($brands_for_filter) { mysqli_data_seek($brands_for_filter, 0); while($br = $brands_for_filter->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($br['brand']); ?>" <?php if ($brand_filter == $br['brand']) echo 'selected'; ?>><?php echo htmlspecialchars($br['brand']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-col md:flex-row justify-end items-center gap-4 pt-6 border-t border-slate-100">
                        <a href="admin_laporan_marpro_receive.php" class="px-8 py-3 bg-slate-100 text-slate-600 font-bold text-xs rounded-xl hover:bg-slate-200 transition-all uppercase tracking-widest">Reset</a>
                        <button type="submit" class="px-8 py-3 bg-blue-600 text-white font-bold text-xs rounded-xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all uppercase tracking-widest">Terapkan Filter</button>
                    </div>
                </form>
            </div>

            <!-- Main Table Section -->
            <div class="glass-card rounded-3xl border border-white/50 overflow-visible shadow-xl shadow-blue-900/5">
                <div class="p-8 border-b border-slate-100 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                    <div>
                        <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Daftar Penerimaan Marpro</h2>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="relative inline-block text-left" id="exportDropdownContainer">
                            <button type="button" id="exportDropdownBtn" class="px-3 py-2 bg-emerald-600 text-white font-bold text-[10px] rounded-lg hover:bg-emerald-700 shadow-md shadow-emerald-100 transition-all flex items-center gap-2">
                                <i class="fas fa-file-excel text-xs"></i> Export <i class="fas fa-chevron-down text-[8px]"></i>
                            </button>
                            <div id="exportDropdownMenu" class="hidden absolute right-0 mt-2 w-48 glass-card rounded-xl shadow-xl border border-white/50 z-50 overflow-hidden divide-y divide-slate-100">
                                <button type="button" onclick="submitExport('filtered')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600 transition-colors flex items-center gap-2">
                                    <i class="fas fa-filter text-emerald-500"></i> Terfilter
                                </button>
                                <button type="button" onclick="submitExport('mtd')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600 transition-colors flex items-center gap-2">
                                    <i class="fas fa-calendar-alt text-emerald-500"></i> MTD (Bulan Ini)
                                </button>
                                <button type="button" onclick="submitExport('all')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600 transition-colors flex items-center gap-2">
                                    <i class="fas fa-database text-emerald-500"></i> Semua Data
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 bg-slate-50 px-3 py-1.5 rounded-xl border border-slate-100">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Tampilkan:</span>
                            <select id="limit" onchange="window.location.href = 'admin_laporan_marpro_receive.php?page=1&<?php echo $filter_query_string; ?>&limit=' + this.value" class="bg-transparent border-none p-0 text-[10px] font-black text-blue-600 focus:ring-0 cursor-pointer">
                                <option value="10" <?php if($records_per_page == 10) echo 'selected'; ?>>10</option>
                                <option value="25" <?php if($records_per_page == 25) echo 'selected'; ?>>25</option>
                                <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50</option>
                                <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Waktu Kirim / Terima</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Proyek & Jenis</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">QTY Kirim</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">QTY Terima</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Selisih (Gap)</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Branch & Penerima</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Foto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($receives_result && $receives_result->num_rows > 0): ?>
                                <?php while($row = $receives_result->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors group">
                                    <td class="py-4 px-6">
                                        <p class="text-xs font-bold text-slate-700">Kirim: <?php echo $row['tanggal_kirim'] ? date('d M Y', strtotime($row['tanggal_kirim'])) : '-'; ?></p>
                                        <p class="text-xs font-bold text-slate-700 mt-1">Terima: <?php echo $row['tanggal_terima'] ? date('d M Y', strtotime($row['tanggal_terima'])) : '-'; ?></p>
                                        <p class="text-[10px] text-slate-400 font-medium mt-1">Oleh: <?php echo htmlspecialchars($row['username']); ?></p>
                                    </td>
                                    <td class="py-4 px-6">
                                        <p class="text-sm font-bold text-slate-800 group-hover:text-blue-600 transition-colors"><?php echo htmlspecialchars($row['project_name']); ?></p>
                                        <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest mt-0.5"><?php echo htmlspecialchars($row['brand']); ?></p>
                                        <p class="text-xs font-semibold text-slate-500 mt-1"><?php echo htmlspecialchars($row['type_name']); ?></p>
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <span class="text-xs font-bold text-slate-700"><?php echo $row['qty_admin'] !== null ? number_format($row['qty_admin']) : '-'; ?></span>
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <span class="text-xs font-bold <?php echo $row['status_receive'] === 'Pending' ? 'text-slate-400 italic' : 'text-blue-600 font-extrabold'; ?>">
                                            <?php echo $row['status_receive'] === 'Pending' ? 'Belum diterima' : number_format($row['qty_branch']); ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <?php if($row['status_receive'] === 'Pending'): ?>
                                            <span class="text-slate-300">-</span>
                                        <?php else: ?>
                                            <?php 
                                            $gap = (int)$row['qty_gap'];
                                            if ($gap > 0) {
                                                echo "<span class='px-2.5 py-1 rounded-lg text-xs font-black bg-red-50 text-red-600 border border-red-100'><i class='fas fa-arrow-down mr-1'></i> " . number_format($gap) . "</span>";
                                            } else if ($gap < 0) {
                                                echo "<span class='px-2.5 py-1 rounded-lg text-xs font-black bg-emerald-50 text-emerald-600 border border-emerald-100'><i class='fas fa-arrow-up mr-1'></i> " . number_format(abs($gap)) . "</span>";
                                            } else {
                                                echo "<span class='px-2.5 py-1 rounded-lg text-xs font-black bg-slate-100 text-slate-500 border border-slate-200'>Pas</span>";
                                            }
                                            ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <span class="px-2.5 py-1 rounded-lg text-xs font-black <?php echo $row['status_receive'] === 'Pending' ? 'bg-amber-50 text-amber-600 border border-amber-100' : 'bg-emerald-50 text-emerald-600 border border-emerald-100'; ?>">
                                            <?php echo htmlspecialchars($row['status_receive']); ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6">
                                        <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($row['nama_branch']); ?></p>
                                        <p class="text-xs text-slate-500 mt-1">Oleh: <?php echo htmlspecialchars($row['diterima_siapa'] ?: '-'); ?></p>
                                        <p class="text-[9px] text-slate-400 mt-1">GPS: <?php echo $row['status_receive'] === 'Pending' ? '-' : htmlspecialchars($row['latitude'] . ', ' . $row['longitude']); ?></p>
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <?php if ($row['status_receive'] !== 'Pending' && !empty($row['photo_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($row['photo_url']); ?>" target="_blank" class="inline-flex h-8 px-3 items-center justify-center gap-1.5 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-600 hover:text-white transition-all shadow-sm text-xs font-bold">
                                                <i class="fas fa-image"></i> Lihat
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-300 text-xs italic">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="py-20 text-center text-slate-400 font-medium">Tidak ada data penerimaan marpro yang ditemukan.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-8 bg-slate-50/50 border-t border-slate-100 flex flex-col md:flex-row justify-between items-center gap-6">
                    <span class="text-xs font-bold text-slate-500">Total <span class="text-slate-800"><?php echo number_format($total_records); ?></span> entri penerimaan</span>
                    
                    <!-- Pagination -->
                    <nav class="flex items-center gap-2" aria-label="Pagination">
                        <?php
                        if($total_pages > 1) {
                            $base_url = "?page=";
                            if ($filter_query_string) {
                               $base_url = "?" . $filter_query_string . "&page=";
                            }

                            $max_pages_to_show = 5;
                            $start_page = max(1, $page - floor($max_pages_to_show / 2));
                            $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                            $start_page = max(1, $end_page - $max_pages_to_show + 1);

                            if ($page > 1) echo '<a href="'.$base_url.($page-1).'&limit='.$records_per_page.'" class="h-10 px-4 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all">Sebelumnya</a>';
                            
                            if ($start_page > 1) { 
                                echo '<a href="'.$base_url.'1&limit='.$records_per_page.'" class="h-10 w-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all">1</a>'; 
                                if ($start_page > 2) echo '<span class="text-slate-300 text-xs">...</span>'; 
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++) { 
                                $active_class = ($i == $page) ? 'bg-blue-600 border-blue-600 text-white shadow-lg shadow-blue-200' : 'bg-white border-slate-200 text-slate-500 hover:border-blue-500 hover:text-blue-600'; 
                                echo '<a href="'.$base_url.$i.'&limit='.$records_per_page.'" class="h-10 w-10 flex items-center justify-center border rounded-xl text-xs font-bold transition-all '.$active_class.'">'.$i.'</a>'; 
                            }
                            
                            if ($end_page < $total_pages) { 
                                if ($end_page < $total_pages - 1) echo '<span class="text-slate-300 text-xs">...</span>'; 
                                echo '<a href="'.$base_url.$total_pages.'&limit='.$records_per_page.'" class="h-10 w-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all">'.$total_pages.'</a>'; 
                            }
                            
                            if ($page < $total_pages) echo '<a href="'.$base_url.($page+1).'&limit='.$records_per_page.'" class="h-10 px-4 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all">Selanjutnya</a>';
                        }
                        ?>
                    </nav>
                </div>
            </div>
        </main>
    </div>

    <!-- Hidden Export Form -->
    <form id="exportForm" action="process/admin_export_marpro_receive_process.php" method="POST" class="hidden">
        <input type="hidden" name="action" id="exportAction" value="export_filtered">
        <input type="hidden" name="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>">
        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
        <input type="hidden" name="brand" value="<?php echo htmlspecialchars($brand_filter); ?>">
        <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($branch_filter); ?>">
        <input type="hidden" name="project_name" value="<?php echo htmlspecialchars($project_filter); ?>">
        <input type="hidden" name="type_name" value="<?php echo htmlspecialchars($type_filter); ?>">
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const exportDropdownBtn = document.getElementById('exportDropdownBtn');
            const exportDropdownMenu = document.getElementById('exportDropdownMenu');
            
            if(exportDropdownBtn && exportDropdownMenu) {
                exportDropdownBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    exportDropdownMenu.classList.toggle('hidden');
                });
                
                document.addEventListener('click', function() {
                    exportDropdownMenu.classList.add('hidden');
                });
            }
        });

        function submitExport(type) {
            const exportForm = document.getElementById('exportForm');
            const exportAction = document.getElementById('exportAction');
            if (exportForm && exportAction) {
                if (type === 'filtered') {
                    exportAction.value = 'export_filtered';
                } else if (type === 'mtd') {
                    exportAction.value = 'export_mtd';
                } else if (type === 'all') {
                    exportAction.value = 'export_all';
                }
                exportForm.submit();
            }
        }
    </script>
</body>
</html>
