<?php
ob_start();

require_once 'config/database.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}
$app_name = get_setting($mysqli, 'app_name');

$records_per_page = isset($_GET['limit']) && in_array($_GET['limit'], [10, 25, 50, 100]) ? (int)$_GET['limit'] : 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$where_clauses = [];
$param_types = "";
$param_values = [];
$filter_query_string = "";

$filters = [
    'keyword' => $_GET['keyword'] ?? '',
    'start_date' => $_GET['start_date'] ?? date('Y-m-01'),
    'end_date' => $_GET['end_date'] ?? date('Y-m-t'),
    'brand' => $_GET['brand'] ?? '',
    'branch_id' => $_GET['branch_id'] ?? '',
    'mc_id' => $_GET['mc_id'] ?? '',
    'area' => $_GET['area'] ?? ''
];

foreach ($filters as $key => $value) {
    if (!empty($value)) {
        $filter_query_string .= "&$key=" . urlencode($value);
    }
}
$filter_query_string .= "&limit=" . $records_per_page;


if (!empty($filters['keyword'])) {
    $where_clauses[] = "(md.msisdn LIKE ? OR e.event_name LIKE ? OR u.username LIKE ? OR b.nama_branch LIKE ? OR mc.nama_micro_cluster LIKE ?)";
    $param_types .= "sssss";
    $param_values[] = "%" . $filters['keyword'] . "%";
    $param_values[] = "%" . $filters['keyword'] . "%";
    $param_values[] = "%" . $filters['keyword'] . "%";
    $param_values[] = "%" . $filters['keyword'] . "%";
    $param_values[] = "%" . $filters['keyword'] . "%";
}
if (!empty($filters['start_date'])) { $where_clauses[] = "e.waktu_input >= ?"; $param_types .= "s"; $param_values[] = $filters['start_date'] . " 00:00:00"; }
if (!empty($filters['end_date'])) { $where_clauses[] = "e.waktu_input <= ?"; $param_types .= "s"; $param_values[] = $filters['end_date'] . " 23:59:59"; }
if (!empty($filters['brand'])) { $where_clauses[] = "u.brand = ?"; $param_types .= "s"; $param_values[] = $filters['brand']; }
if (!empty($filters['branch_id'])) { $where_clauses[] = "s.branch_id = ?"; $param_types .= "i"; $param_values[] = $filters['branch_id']; }
if (!empty($filters['mc_id'])) { $where_clauses[] = "s.micro_cluster_id = ?"; $param_types .= "i"; $param_values[] = $filters['mc_id']; }
if (!empty($filters['area'])) { $where_clauses[] = "s.area LIKE ?"; $param_types .= "s"; $param_values[] = "%" . $filters['area'] . "%"; }

$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
}

$count_sql = "SELECT COUNT(md.id) as total 
              FROM msisdn_data md
              JOIN event_submissions e ON md.submission_id = e.unique_id
              JOIN users u ON e.user_id = u.id
              LEFT JOIN sites s ON e.site_id = s.id
              LEFT JOIN branches b ON s.branch_id = b.id
              LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id
              $where_sql";
$stmt_count = $mysqli->prepare($count_sql);
if (!empty($param_values)) {
    $stmt_count->bind_param($param_types, ...$param_values);
}
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

$sql = "SELECT 
            md.msisdn, md.type, e.unique_id, e.event_name, e.waktu_input,
            u.username AS user_input, b.nama_branch, mc.nama_micro_cluster
        FROM msisdn_data md
        JOIN event_submissions e ON md.submission_id = e.unique_id
        JOIN users u ON e.user_id = u.id
        LEFT JOIN sites s ON e.site_id = s.id
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id
        $where_sql
        ORDER BY e.waktu_input DESC
        LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($param_types_page, ...$param_values_page);
$stmt->execute();
$search_results = $stmt->get_result();

$brands_for_filter = $mysqli->query("SELECT DISTINCT brand FROM branches WHERE brand IS NOT NULL ORDER BY brand");
$initial_branches_query = null;
if (!empty($filters['brand'])) {
    $stmt_initial_branches = $mysqli->prepare("SELECT id, nama_branch FROM branches WHERE brand = ? ORDER BY nama_branch");
    $stmt_initial_branches->bind_param("s", $filters['brand']);
    $stmt_initial_branches->execute();
    $initial_branches_query = $stmt_initial_branches->get_result();
}

$initial_mcs_query = null;
if (!empty($filters['branch_id'])) {
    $stmt_initial_mcs = $mysqli->prepare("SELECT id, nama_micro_cluster FROM micro_clusters WHERE branch_id = ? ORDER BY nama_micro_cluster");
    $stmt_initial_mcs->bind_param("i", (int)$filters['branch_id']);
    $stmt_initial_mcs->execute();
    $initial_mcs_query = $stmt_initial_mcs->get_result();
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Cek & Ekspor MSISDN - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Cek & Ekspor MSISDN</h2>
                        <p class="text-slate-500 font-medium text-sm">Validasi data MSISDN dari seluruh aktivitas event</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Export button moved to table header -->
                </div>
            </header>

            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert"><p><?php echo $_SESSION['success_message']; ?></p></div>
            <?php unset($_SESSION['success_message']); endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert"><p><?php echo $_SESSION['error_message']; ?></p></div>
            <?php unset($_SESSION['error_message']); endif; ?>

            <!-- Filter Panel -->
            <div class="glass-card rounded-3xl p-8 mb-10 border border-white/50">
                <div class="flex items-center gap-3 mb-8">
                    <div class="h-10 w-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-sm">
                        <i class="fas fa-filter text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-800 tracking-tight">Filter MSISDN</h3>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Temukan Nomor Spesifik</p>
                    </div>
                </div>

                <form action="admin_laporan_msisdn.php" method="GET" class="space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="md:col-span-2 space-y-2">
                            <label for="keyword" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Cari Spesifik</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" name="keyword" id="keyword" value="<?php echo htmlspecialchars($filters['keyword']); ?>" 
                                       placeholder="MSISDN, Event, User, Branch..."
                                       class="w-full pl-10 pr-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label for="brand_filter" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Brand</label>
                            <select name="brand" id="brand_filter" class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all appearance-none cursor-pointer">
                                <option value="">Semua Brand</option>
                                <?php mysqli_data_seek($brands_for_filter, 0); while($brand = $brands_for_filter->fetch_assoc()): ?>
                                <option value="<?php echo $brand['brand']; ?>" <?php if ($filters['brand'] == $brand['brand']) echo 'selected'; ?>><?php echo $brand['brand']; ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="branch_id" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Branch</label>
                            <select name="branch_id" id="branch_id" class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all appearance-none cursor-pointer disabled:opacity-50" <?php if(empty($filters['brand'])) echo 'disabled'; ?>>
                                <option value="">Pilih Brand</option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="mc_id" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Cluster</label>
                            <select name="mc_id" id="mc_id" class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all appearance-none cursor-pointer disabled:opacity-50" <?php if(empty($filters['branch_id'])) echo 'disabled'; ?>>
                                <option value="">Pilih Branch</option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="area" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Area</label>
                            <input type="text" name="area" id="area" value="<?php echo htmlspecialchars($filters['area']); ?>" 
                                   placeholder="Denpasar"
                                   class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>

                        <div class="space-y-2">
                            <label for="start_date" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Mulai</label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($filters['start_date']); ?>" 
                                   class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>

                        <div class="space-y-2">
                            <label for="end_date" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Hingga</label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($filters['end_date']); ?>" 
                                   class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                    </div>

                    <div class="flex flex-col md:flex-row justify-between items-center gap-6 pt-6 border-t border-slate-100">
                        <div class="flex items-center gap-2">
                            <!-- Existing Hidden Form for Export -->

                        </div>
                        <div class="flex gap-2">
                            <a href="admin_laporan_msisdn.php" class="px-8 py-2.5 bg-slate-100 text-slate-600 font-bold text-xs rounded-xl hover:bg-slate-200 transition-all uppercase tracking-widest">Reset</a>
                            <button type="submit" class="px-8 py-2.5 bg-blue-600 text-white font-bold text-xs rounded-xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all uppercase tracking-widest">Cari MSISDN</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Main Data Table -->
            <div class="glass-card rounded-3xl border border-white/50 overflow-visible shadow-xl shadow-blue-900/5">
                <div class="p-8 border-b border-slate-100 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                    <div>
                        <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Data MSISDN Terdaftar</h2>
                        <div class="flex items-center gap-3 mt-2">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Tampilkan:</span>
                            <select name="limit" id="limit" onchange="window.location.href = 'admin_laporan_msisdn.php?page=1<?php echo str_replace(['&limit='.$records_per_page, '&page='.$page], '', $filter_query_string); ?>&limit=' + this.value" class="bg-slate-50 border-none rounded-lg text-[10px] font-black text-blue-600 focus:ring-0 cursor-pointer">
                                <option value="10" <?php if($records_per_page == 10) echo 'selected'; ?>>10 baris</option>
                                <option value="25" <?php if($records_per_page == 25) echo 'selected'; ?>>25 baris</option>
                                <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50 baris</option>
                                <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100 baris</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="relative inline-block text-left" id="exportDropdownContainer_msisdn">
                            <button type="button" id="exportDropdownBtn_msisdn" class="px-3 py-2 bg-slate-800 text-white font-bold text-[10px] rounded-lg hover:bg-slate-900 shadow-md transition-all flex items-center gap-2">
                                <i class="fas fa-file-excel text-xs"></i> Export Excel <i class="fas fa-chevron-down text-[8px]"></i>
                            </button>
                            <div id="exportDropdownMenu_msisdn" class="hidden absolute right-0 mt-2 w-48 glass-card rounded-xl shadow-xl border border-white/50 z-50 overflow-hidden divide-y divide-slate-100">
                                 <button type="button" onclick="submitMsisdnExport('filtered')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-blue-50 hover:text-blue-600 transition-colors flex items-center gap-2">
                                    <i class="fas fa-filter text-blue-500"></i> Terfilter
                                </button>
                                <button type="button" onclick="submitMsisdnExport('mtd')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-blue-50 hover:text-blue-600 transition-colors flex items-center gap-2">
                                    <i class="fas fa-calendar-alt text-blue-500"></i> MTD (Bulan Ini)
                                </button>
                                <button type="button" onclick="submitMsisdnExport('all')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-blue-50 hover:text-blue-600 transition-colors flex items-center gap-2">
                                    <i class="fas fa-database text-blue-500"></i> Semua Data
                                </button>
                            </div>
                        </div>

                        <div class="px-4 py-2 bg-slate-50 rounded-2xl border border-slate-100">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total MSISDN: <span class="text-blue-600"><?php echo number_format($total_records, 0, ',', '.'); ?></span></span>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">MSISDN & Tipe</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Activity Event</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">User / Agent</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Branch / Cluster</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Waktu Input</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($search_results && $search_results->num_rows > 0): ?>
                                <?php while($row = $search_results->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors group">
                                    <td class="py-4 px-6">
                                        <p class="text-sm font-black text-slate-800 tracking-tighter"><?php echo htmlspecialchars($row['msisdn']); ?></p>
                                        <span class="<?php echo ($row['type'] == 'existing') ? 'text-amber-600 bg-amber-50 border-amber-100' : 'text-emerald-600 bg-emerald-50 border-emerald-100'; ?> text-[9px] font-black uppercase px-2 py-0.5 rounded-full border mt-1 inline-block italic">
                                            <?php echo htmlspecialchars(ucfirst($row['type'] ?? 'new')); ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6">
                                        <a href="admin_detail_event.php?id=<?php echo $row['unique_id']; ?>" class="text-xs font-bold text-slate-600 hover:text-blue-600 transition-colors flex items-center gap-1">
                                            <i class="fas fa-external-link-alt text-[10px] opacity-0 group-hover:opacity-100 transition-opacity"></i>
                                            <?php echo htmlspecialchars($row['event_name']); ?>
                                        </a>
                                    </td>
                                    <td class="py-4 px-6 text-xs font-bold text-slate-600"><?php echo htmlspecialchars($row['user_input']); ?></td>
                                    <td class="py-4 px-6">
                                        <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($row['nama_branch']); ?></p>
                                        <p class="text-[10px] font-medium text-slate-400"><?php echo htmlspecialchars($row['nama_micro_cluster']); ?></p>
                                    </td>
                                    <td class="py-4 px-6">
                                        <p class="text-[11px] font-medium text-slate-500"><?php echo date('d M Y', strtotime($row['waktu_input'])); ?></p>
                                        <p class="text-[9px] font-black text-slate-300 uppercase tracking-tighter"><?php echo date('H:i:s', strtotime($row['waktu_input'])); ?></p>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="py-20 text-center text-slate-400 font-medium italic">Tidak ada data MSISDN yang ditemukan.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-8 bg-slate-50/50 border-t border-slate-100 flex flex-col md:flex-row justify-between items-center gap-6">
                     <!-- Pagination Section -->
                </div>
            </div>

            <div class="mt-10 mb-20 flex justify-center">
                <nav class="flex items-center gap-2" aria-label="Pagination">
                    <?php
                    if($total_pages > 1) {
                        $max_pages_to_show = 5;
                        $start_page = max(1, $page - floor($max_pages_to_show / 2));
                        $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                        $start_page = max(1, $end_page - $max_pages_to_show + 1);

                        if ($page > 1) echo '<a href="?page='.($page-1).$filter_query_string.'" class="h-10 px-4 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all shadow-sm">Sebelumnya</a>';
                        
                        if ($start_page > 1) { 
                            echo '<a href="?page=1'.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all shadow-sm">1</a>'; 
                            if ($start_page > 2) echo '<span class="text-slate-300 text-xs px-1">...</span>'; 
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++) { 
                            $active_class = ($i == $page) ? 'bg-blue-600 border-blue-600 text-white shadow-lg shadow-blue-200' : 'bg-white border-slate-200 text-slate-500 hover:border-blue-500 hover:text-blue-600 shadow-sm'; 
                            echo '<a href="?page='.$i.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center border rounded-xl text-xs font-bold transition-all '.$active_class.'">'.$i.'</a>'; 
                        }
                        
                        if ($end_page < $total_pages) { 
                            if ($end_page < $total_pages - 1) echo '<span class="text-slate-300 text-xs px-1">...</span>'; 
                            echo '<a href="?page='.$total_pages.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all shadow-sm">'.$total_pages.'</a>'; 
                        }
                        
                        if ($page < $total_pages) echo '<a href="?page='.($page+1).$filter_query_string.'" class="h-10 px-4 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all shadow-sm">Selanjutnya</a>';
                    }
                    ?>
                </nav>
            </div>
        </main>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const brandSelect = document.getElementById('brand_filter');
    const branchSelect = document.getElementById('branch_id');
    const mcSelect = document.getElementById('mc_id');

    function loadBranches(selectedBrand, selectedBranchId = null) {
        if (!selectedBrand) {
            branchSelect.innerHTML = '<option value="">Pilih Brand</option>';
            branchSelect.disabled = true;
            return;
        }
        fetch(`api_helper.php?action=get_branches_by_brand&brand=${selectedBrand}`)
            .then(response => response.json())
            .then(data => {
                branchSelect.innerHTML = '<option value="">Semua Branch</option>';
                data.forEach(branch => {
                    const option = new Option(branch.nama_branch, branch.id);
                    branchSelect.add(option);
                });
                if (selectedBranchId) {
                    branchSelect.value = selectedBranchId;
                }
                branchSelect.disabled = false;
            })
            .catch(error => console.error('Error fetching branches:', error));
    }

    function loadMicroClusters(selectedBranch, selectedMcId = null) {
        if (!selectedBranch) {
            mcSelect.innerHTML = '<option value="">Pilih Branch</option>';
            mcSelect.disabled = true;
            return;
        }
        fetch(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${selectedBranch}`)
            .then(response => response.json())
            .then(data => {
                mcSelect.innerHTML = '<option value="">Semua Cluster</option>';
                data.forEach(mc => {
                    const option = new Option(mc.nama_micro_cluster, mc.id);
                    mcSelect.add(option);
                });
                if (selectedMcId) {
                    mcSelect.value = selectedMcId;
                }
                mcSelect.disabled = false;
            })
            .catch(error => console.error('Error fetching micro clusters:', error));
    }

    brandSelect.addEventListener('change', () => {
        mcSelect.innerHTML = '<option value="">Pilih Branch</option>';
        mcSelect.disabled = true;
        loadBranches(brandSelect.value);
    });
    branchSelect.addEventListener('change', () => loadMicroClusters(branchSelect.value));
    
    const initialBrand = <?php echo json_encode($filters['brand']); ?>;
    const initialBranch = <?php echo json_encode($filters['branch_id']); ?>;
    const initialMc = <?php echo json_encode($filters['mc_id']); ?>;

    if (initialBrand) loadBranches(initialBrand, initialBranch);
    if (initialBranch) loadMicroClusters(initialBranch, initialMc);

    // Export Dropdown Logic
    const exportDropdownBtn = document.getElementById('exportDropdownBtn_msisdn');
    const exportDropdownMenu = document.getElementById('exportDropdownMenu_msisdn');
    
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

function submitMsisdnExport(type) {
    const params = new URLSearchParams();
    
    // Default empty values for filters
    let keyword = '';
    let start_date = '';
    let end_date = '';
    let brand = '';
    let branch_id = '';
    let mc_id = '';
    let area = '';

    if (type === 'mtd') {
        const now = new Date();
        const formatDate = (date) => {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };
        start_date = formatDate(new Date(now.getFullYear(), now.getMonth(), 1));
        end_date = formatDate(new Date(now.getFullYear(), now.getMonth() + 1, 0));
    } else if (type === 'filtered') {
        keyword = document.getElementById('keyword').value;
        start_date = document.getElementById('start_date').value;
        end_date = document.getElementById('end_date').value;
        brand = document.getElementById('brand_filter').value;
        branch_id = document.getElementById('branch_id').value;
        mc_id = document.getElementById('mc_id').value;
        area = document.getElementById('area').value;
    }
    // 'all' uses default empty values (fetch everything)

    if(keyword) params.append('keyword', keyword);
    if(start_date) params.append('start_date', start_date);
    if(end_date) params.append('end_date', end_date);
    if(brand) params.append('brand', brand);
    if(branch_id) params.append('branch_id', branch_id);
    if(mc_id) params.append('mc_id', mc_id);
    if(area) params.append('area', area);

    const exportUrl = 'process/export_msisdn_csv.php?' + params.toString();
    
    // Use an iframe to trigger download silently, or just window.location
    // window.location.href = exportUrl;
    
    // Create a temporary link to trigger download
    const link = document.createElement('a');
    link.href = exportUrl;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>
<?php
ob_end_flush();
?>
