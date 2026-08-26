<?php
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
$filter_query_string = http_build_query(array_filter($_GET, fn($key) => $key !== 'page', ARRAY_FILTER_USE_KEY));

$search_keyword = $_GET['keyword'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$brand_filter = $_GET['brand'] ?? '';
$branch_filter = $_GET['branch_id'] ?? '';
$mc_filter = $_GET['mc_id'] ?? '';
$project_filter = $_GET['project_name'] ?? '';
$type_filter = $_GET['type_name'] ?? '';

if (!empty($search_keyword)) {
    $where_clauses[] = "(u.username LIKE ? OR ma.project_name LIKE ? OR ma.type_name LIKE ? OR ma.outlet_snapshot_name LIKE ? OR s.site_name LIKE ?)";
    $param_types .= "sssss";
    $keyword_like = "%" . $search_keyword . "%";
    array_push($param_values, $keyword_like, $keyword_like, $keyword_like, $keyword_like, $keyword_like);
}
if (!empty($start_date) && !empty($end_date)) {
    $where_clauses[] = "ma.activity_datetime BETWEEN ? AND ?";
    $param_types .= "ss";
    $param_values[] = $start_date . " 00:00:00";
    $param_values[] = $end_date . " 23:59:59";
} elseif (!empty($start_date)) {
    $where_clauses[] = "ma.activity_datetime >= ?";
    $param_types .= "s";
    $param_values[] = $start_date . " 00:00:00";
} elseif (!empty($end_date)) {
    $where_clauses[] = "ma.activity_datetime <= ?";
    $param_types .= "s";
    $param_values[] = $end_date . " 23:59:59";
}
if (!empty($brand_filter)) { $where_clauses[] = "b.brand = ?"; $param_types .= "s"; $param_values[] = $brand_filter; }
if (!empty($branch_filter)) { $where_clauses[] = "ma.branch_id = ?"; $param_types .= "i"; $param_values[] = (int)$branch_filter; }
if (!empty($mc_filter)) { $where_clauses[] = "ma.micro_cluster_id = ?"; $param_types .= "i"; $param_values[] = (int)$mc_filter; }
if (!empty($project_filter)) { $where_clauses[] = "ma.project_name = ?"; $param_types .= "s"; $param_values[] = $project_filter; }
if (!empty($type_filter)) { $where_clauses[] = "ma.type_name = ?"; $param_types .= "s"; $param_values[] = $type_filter; }

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

$count_sql = "SELECT COUNT(ma.id) as total
              FROM matpro_activities ma
              JOIN users u ON ma.user_id = u.id
              JOIN sites s ON ma.site_id = s.id
              LEFT JOIN outlets o ON ma.outlet_id = o.id
              JOIN branches b ON ma.branch_id = b.id" . $where_sql;

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
    $error_message = "Gagal menghitung total aktivitas: " . $mysqli->error;
}
$total_pages = ceil($total_records / $records_per_page);

$sql = "SELECT ma.id, ma.unique_id, ma.activity_datetime, ma.qty_used,
               u.username, u.nama as user_nama,
               ma.project_name, b.brand, ma.type_name,
               b.nama_branch, mc.nama_micro_cluster,
               s.site_name, s.site_id as site_code,
               COALESCE(o.Id_Outlet_Nama_Outlet, ma.outlet_snapshot_name, 'Outlet Telah Dihapus') as outlet_display_name
        FROM matpro_activities ma
        JOIN users u ON ma.user_id = u.id
        JOIN branches b ON ma.branch_id = b.id
        LEFT JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
        JOIN sites s ON ma.site_id = s.id
        LEFT JOIN outlets o ON ma.outlet_id = o.id"
        . $where_sql . " ORDER BY ma.activity_datetime DESC LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    if (!empty($param_values_page)) {
        call_user_func_array([$stmt, 'bind_param'], array_merge([$param_types_page], ref_values($param_values_page)));
    }
    $stmt->execute();
    $activities_result = $stmt->get_result();
    $stmt->close();
} else {
    $activities_result = false;
    $error_message = "Gagal mengambil data laporan: " . $mysqli->error;
}

$projects_for_filter_result = $mysqli->query("SELECT DISTINCT project_name FROM matpro_activities WHERE project_name IS NOT NULL ORDER BY project_name");
$types_for_filter_result = $mysqli->query("SELECT DISTINCT type_name FROM matpro_activities WHERE type_name IS NOT NULL ORDER BY type_name");
$brands_for_filter = $mysqli->query("SELECT DISTINCT brand FROM branches WHERE brand IS NOT NULL AND brand != '' ORDER BY brand");
$branches_for_filter = $mysqli->query("SELECT id, nama_branch FROM branches ORDER BY nama_branch");
$mcs_for_filter = $mysqli->query("SELECT id, nama_micro_cluster FROM micro_clusters ORDER BY nama_micro_cluster");

$pending_matpro_requests_sql = "
    (SELECT
        dr.id as request_id, dr.activity_id, dr.reason, dr.requested_at, dr.status,
        'delete' as request_type, dr.rejection_reason, u.username,
        ma.project_name, ma.type_name, 'deletion' as request_table_type
    FROM deletion_requests dr
    JOIN matpro_activities ma ON dr.activity_id = ma.id
    JOIN users u ON dr.user_id = u.id
    WHERE dr.status = 'pending')
    UNION ALL
    (SELECT
        mer.id as request_id, mer.activity_id, mer.reason, mer.requested_at, mer.status,
        'edit' as request_type, mer.rejection_reason, u.username,
        ma.project_name, ma.type_name, 'edit' as request_table_type
    FROM matpro_edit_requests mer
    JOIN matpro_activities ma ON mer.activity_id = ma.id
    JOIN users u ON mer.user_id = u.id
    WHERE mer.status = 'pending')
    ORDER BY requested_at ASC
";
$pending_matpro_requests_result = $mysqli->query($pending_matpro_requests_sql);
$pending_matpro_requests = $pending_matpro_requests_result ? $pending_matpro_requests_result->fetch_all(MYSQLI_ASSOC) : [];
// Ambil pengaturan aplikasi
$app_name = get_setting($mysqli, 'app_name') ?: 'MarComm App';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Laporan Matpro - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Laporan Matpro</h2>
                        <p class="text-slate-500 font-medium text-sm">Monitor dan validasi penggunaan material promosi</p>
                    </div>
                </div>
            </header>

            <!-- Success/Error Messages -->
            <?php if (!empty($success_message)): ?>
            <div class="glass-card border-l-4 border-emerald-500 text-emerald-700 p-6 mb-8 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                <div class="h-10 w-10 rounded-full bg-emerald-100 flex items-center justify-center shrink-0">
                    <i class="fas fa-check"></i>
                </div>
                <p class="font-bold"><?php echo $success_message; ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="glass-card border-l-4 border-red-500 text-red-700 p-6 mb-8 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                <div class="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <p class="font-bold"><?php echo $error_message; ?></p>
            </div>
            <?php endif; ?>

            <!-- Pending Matpro Requests Section -->
            <div class="glass-card rounded-3xl p-8 mb-10 border border-white/50 bg-amber-50/30">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                    <div>
                        <h2 class="text-xl font-extrabold text-slate-800 tracking-tight flex items-center gap-3">
                            <i class="fas fa-bell text-amber-500 <?php if (!empty($pending_matpro_requests)) echo 'animate-bounce'; ?>"></i>
                            Persetujuan Permintaan Matpro
                            <?php if (!empty($pending_matpro_requests)): ?>
                                <span class="bg-red-500 text-white text-[10px] font-black rounded-full h-5 w-5 flex items-center justify-center"><?php echo count($pending_matpro_requests); ?></span>
                            <?php endif; ?>
                        </h2>
                        <p class="text-xs text-slate-500 font-medium">Validasi permintaan edit atau hapus material promosi.</p>
                    </div>
                    <?php if (!empty($pending_matpro_requests)): ?>
                    <div class="flex items-center gap-2">
                        <form action="process/admin_laporan_matpro_process.php" method="POST" onsubmit="return confirm('Setujui semua permintaan matpro?');">
                            <input type="hidden" name="action" value="approve_all_requests">
                            <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-xs font-bold rounded-xl hover:bg-emerald-700 transition-all flex items-center gap-2 shadow-lg shadow-emerald-200">
                                <i class="fas fa-check-double"></i> Setujui Semua
                            </button>
                        </form>
                        <button type="button" onclick="openRejectAllModal()" class="px-4 py-2 bg-slate-800 text-white text-xs font-bold rounded-xl hover:bg-slate-900 transition-all flex items-center gap-2 shadow-lg shadow-slate-200">
                            <i class="fas fa-times-circle"></i> Tolak Semua
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($pending_matpro_requests)): ?>
                <div class="overflow-x-auto rounded-2xl border border-slate-100 bg-white/50">
                    <table class="min-w-full text-left">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">User & Waktu</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Tipe</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Detail Activity</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Alasan</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($pending_matpro_requests as $req): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="py-4 px-6">
                                    <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($req['username']); ?></p>
                                    <p class="text-[10px] text-slate-400 font-medium mt-1"><i class="far fa-clock mr-1"></i><?php echo date('d M Y, H:i', strtotime($req['requested_at'])); ?></p>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <?php if ($req['request_type'] == 'edit'): ?>
                                        <span class="px-2 py-1 bg-blue-50 text-blue-600 rounded-lg text-[10px] font-black uppercase tracking-widest border border-blue-100 italic font-bold">EDIT</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 bg-red-50 text-red-600 rounded-lg text-[10px] font-black uppercase tracking-widest border border-red-100 italic font-bold">DELETE</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-6 text-xs font-bold text-slate-600">
                                    <div class="flex flex-col">
                                        <span><?php echo htmlspecialchars($req['project_name']); ?></span>
                                        <span class="text-[10px] font-medium text-slate-400"><?php echo htmlspecialchars($req['type_name']); ?></span>
                                    </div>
                                </td>
                                <td class="py-4 px-6 text-xs font-medium text-slate-500 italic max-w-xs"><?php echo nl2br(htmlspecialchars($req['reason'])); ?></td>
                                <td class="py-4 px-6 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <?php if ((int)$req['request_id'] > 0): ?>
                                            <form action="process/admin_laporan_matpro_process.php" method="POST" class="inline-block" onsubmit="return confirm('Setujui permintaan ini?');">
                                                <input type="hidden" name="action" value="review_request">
                                                <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($req['request_id']); ?>">
                                                <input type="hidden" name="request_table_type" value="<?php echo htmlspecialchars($req['request_table_type']); ?>">
                                                <input type="hidden" name="decision" value="approve">
                                                <button type="submit" class="h-8 w-8 flex items-center justify-center bg-emerald-50 text-emerald-600 rounded-lg hover:bg-emerald-600 hover:text-white transition-all shadow-sm">
                                                    <i class="fas fa-check text-xs"></i>
                                                </button>
                                            </form>
                                            <button type="button" onclick="openRejectModal(<?php echo htmlspecialchars($req['request_id']); ?>, '<?php echo htmlspecialchars($req['request_table_type']); ?>')" class="h-8 w-8 flex items-center justify-center bg-red-50 text-red-600 rounded-lg hover:bg-red-600 hover:text-white transition-all shadow-sm">
                                                <i class="fas fa-times text-xs"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-red-500 text-[10px] font-black">ID INVALID</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="py-12 text-center text-slate-400 font-medium border-2 border-dashed border-slate-100 rounded-3xl">
                    <i class="fas fa-check-circle text-4xl mb-4 text-emerald-100"></i>
                    <p>Belum ada permintaan tertunda untuk aktivitas matpro.</p>
                </div>
                <?php endif; ?>
            </div>

            <h2 class="text-xl font-bold text-gray-800 mb-4">Laporan Aktivitas Matpro</h2>
            <!-- Filter Panel -->
            <div class="glass-card rounded-3xl p-8 mb-10 border border-white/50">
                <div class="flex items-center gap-3 mb-8">
                    <div class="h-10 w-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-sm">
                        <i class="fas fa-filter text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-800 tracking-tight">Filter Laporan Matpro</h3>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Saring Aktivitas Matpro</p>
                    </div>
                </div>

                <form id="filterForm" action="admin_laporan_matpro.php" method="GET" class="space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="space-y-2">
                            <label for="keyword" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Pariwisata & Pencarian</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" name="keyword" id="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" 
                                       placeholder="Username, Proyek, Site..."
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
                    </div>

                    <div class="flex flex-col md:flex-row justify-end items-center gap-4 pt-6 border-t border-slate-100">
                        <a href="admin_laporan_matpro.php" class="px-8 py-3 bg-slate-100 text-slate-600 font-bold text-xs rounded-xl hover:bg-slate-200 transition-all uppercase tracking-widest">Reset</a>
                        <button type="submit" class="px-8 py-3 bg-blue-600 text-white font-bold text-xs rounded-xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all uppercase tracking-widest">Terapkan Filter</button>
                    </div>
                </form>
            </div>

            <!-- Main Table Section -->
            <div class="glass-card rounded-3xl border border-white/50 overflow-visible shadow-xl shadow-blue-900/5">
                <form id="mainForm" action="process/admin_laporan_matpro_process.php" method="POST">
                    <input type="hidden" name="action" id="main_action" value="bulk_delete">
                    
                    <div class="p-8 border-b border-slate-100 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                        <div>
                            <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Daftar Aktivitas Matpro</h2>
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
                                <select id="limit" onchange="window.location.href = 'admin_laporan_matpro.php?page=1&<?php echo $filter_query_string; ?>&limit=' + this.value" class="bg-transparent border-none p-0 text-[10px] font-black text-blue-600 focus:ring-0 cursor-pointer">
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
                                    <th class="py-4 px-6 text-center"><input type="checkbox" id="selectAllActivities" class="rounded border-slate-300 text-blue-600 focus:ring-blue-100"></th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Waktu & User</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Proyek (Brand)</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Jenis & QTY</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Branch / MC</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Site / Outlet</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if ($activities_result && $activities_result->num_rows > 0): ?>
                                    <?php while($activity = $activities_result->fetch_assoc()): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors group">
                                        <td class="py-4 px-6 text-center">
                                            <input type="checkbox" name="activity_ids[]" value="<?php echo $activity['id']; ?>" class="activity-checkbox rounded border-slate-300 text-blue-600 focus:ring-blue-100">
                                        </td>
                                        <td class="py-4 px-6">
                                            <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($activity['user_nama']); ?></p>
                                            <p class="text-[10px] text-slate-400 font-medium mt-0.5"><?php echo date('d M Y, H:i', strtotime($activity['activity_datetime'])); ?></p>
                                        </td>
                                        <td class="py-4 px-6">
                                            <p class="text-sm font-bold text-slate-800 group-hover:text-blue-600 transition-colors"><?php echo htmlspecialchars($activity['project_name']); ?></p>
                                            <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest mt-0.5"><?php echo htmlspecialchars($activity['brand']); ?></p>
                                        </td>
                                        <td class="py-4 px-6">
                                            <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($activity['type_name']); ?></p>
                                            <p class="text-[10px] font-black text-indigo-600 uppercase tracking-tighter mt-0.5">Used: <?php echo number_format($activity['qty_used']); ?></p>
                                        </td>
                                        <td class="py-4 px-6">
                                            <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($activity['nama_branch']); ?></p>
                                            <p class="text-[10px] font-medium text-slate-400"><?php echo htmlspecialchars($activity['nama_micro_cluster'] ?? '-'); ?></p>
                                        </td>
                                        <td class="py-4 px-6">
                                            <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($activity['site_name']); ?></p>
                                            <p class="text-[10px] font-medium text-slate-400"><?php echo htmlspecialchars($activity['outlet_display_name']); ?></p>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="flex items-center justify-center gap-2">
                                                <a href="admin_matpro_activity_detail.php?id=<?php echo $activity['id']; ?>" class="h-8 w-8 flex items-center justify-center bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-600 hover:text-white transition-all shadow-sm" title="Detail">
                                                    <i class="fas fa-eye text-xs"></i>
                                                </a>
                                                <button type="button" onclick="openMatproEditDateModal('<?php echo $activity['id']; ?>', '<?php echo date('Y-m-d', strtotime($activity['activity_datetime'])); ?>', '<?php echo date('H:i', strtotime($activity['activity_datetime'])); ?>')" class="h-8 w-8 flex items-center justify-center bg-purple-50 text-purple-600 rounded-lg hover:bg-purple-600 hover:text-white transition-all shadow-sm" title="Edit Tanggal">
                                                    <i class="fas fa-calendar-alt text-xs"></i>
                                                </button>
                                                <button type="submit" name="delete_single" value="<?php echo $activity['id']; ?>" onclick="document.getElementById('main_action').value='delete'; return confirm('Hapus aktivitas ini? Stok akan dikembalikan.');" class="h-8 w-8 flex items-center justify-center bg-red-50 text-red-600 rounded-lg hover:bg-red-600 hover:text-white transition-all shadow-sm" title="Hapus">
                                                    <i class="fas fa-trash-alt text-xs"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="py-20 text-center text-slate-400 font-medium">Tidak ada data aktivitas matpro yang ditemukan.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-8 bg-slate-50/50 border-t border-slate-100 flex flex-col md:flex-row justify-between items-center gap-6">
                        <div class="flex items-center gap-3">
                            <button type="submit" id="bulkDeleteBtn" class="px-6 py-3 bg-red-600 text-white font-black text-[10px] uppercase tracking-widest rounded-xl hover:bg-red-700 shadow-lg shadow-red-200 disabled:opacity-30 disabled:shadow-none transition-all flex items-center gap-2" disabled onclick="document.getElementById('main_action').value='bulk_delete'; return confirm('Hapus aktivitas terpilih? Stok akan dikembalikan.');">
                                <i class="fas fa-trash-alt"></i> Hapus Terpilih
                            </button>
                            <button type="button" id="bulkEditDateMatproBtn" class="px-6 py-3 bg-purple-600 text-white font-black text-[10px] uppercase tracking-widest rounded-xl hover:bg-purple-700 shadow-lg shadow-purple-200 disabled:opacity-30 disabled:shadow-none transition-all flex items-center gap-2" disabled onclick="openMatproBulkEditDateModal()">
                                <i class="fas fa-calendar-alt"></i> Edit Tanggal Terpilih
                            </button>
                        </div>

                        <div class="flex items-center gap-2">
                           <!-- Pagination -->
                        </div>
                    </div>
                </form>
            </div>

            <div class="mt-10 mb-20 flex justify-center">
                <nav class="flex items-center gap-2" aria-label="Pagination">
                    <?php
                    if($total_pages > 1) {
                        $filter_query_string_pagination = http_build_query(array_filter($_GET, fn($key) => !in_array($key, ['page', 'limit']), ARRAY_FILTER_USE_KEY));
                        $base_url = "?page=";
                        if ($filter_query_string_pagination) {
                           $base_url = "?" . $filter_query_string_pagination . "&page=";
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
        </main>
    </div>
</div>

<!-- Modal: Single Edit Tanggal Matpro -->
<div id="editDateMatproModal" class="modal-overlay hidden">
    <div class="glass-card p-10 rounded-3xl w-full max-w-md border border-white/50">
        <h2 class="text-2xl font-extrabold text-slate-800 mb-1">Edit Tanggal Aktivitas</h2>
        <p class="text-xs text-slate-500 mb-8 font-medium">Ubah tanggal dan waktu aktivitas matpro ini.</p>
        <form id="editDateMatproForm" action="process/admin_laporan_matpro_process.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="edit_date_activity">
            <input type="hidden" name="activity_id" id="editDateMatproId">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Tanggal Baru</label>
                <input type="date" name="new_date" id="editDateMatproDate" required
                       class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Jam (opsional)</label>
                <input type="time" name="new_time" id="editDateMatproTime"
                       class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all">
            </div>
            <div class="flex gap-4 pt-4">
                <button type="button" onclick="document.getElementById('editDateMatproModal').classList.add('hidden')" class="w-1/2 px-6 py-3 bg-slate-100 text-slate-600 font-bold text-xs rounded-xl hover:bg-slate-200 transition-all uppercase tracking-widest">Batal</button>
                <button type="submit" class="w-1/2 px-6 py-3 bg-purple-600 text-white font-bold text-xs rounded-xl hover:bg-purple-700 shadow-lg shadow-purple-200 transition-all uppercase tracking-widest">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Bulk Edit Tanggal Matpro -->
<div id="bulkEditDateMatproModal" class="modal-overlay hidden">
    <div class="glass-card p-10 rounded-3xl w-full max-w-md border border-white/50">
        <h2 class="text-2xl font-extrabold text-slate-800 mb-1">Edit Tanggal Massal</h2>
        <p class="text-xs text-slate-500 mb-1 font-medium">Ubah tanggal untuk semua aktivitas yang dipilih.</p>
        <p id="bulkEditMatproCount" class="text-sm font-black text-purple-600 mb-8"></p>
        <form id="bulkEditDateMatproForm" action="process/admin_laporan_matpro_process.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="bulk_edit_date_activities">
            <div id="bulkEditMatproIdsContainer"></div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Tanggal Baru</label>
                <input type="date" name="new_date" id="bulkEditDateMatproDate" required
                       class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Jam (opsional, default 00:00)</label>
                <input type="time" name="new_time" id="bulkEditDateMatproTime"
                       class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all">
            </div>
            <div class="flex gap-4 pt-4">
                <button type="button" onclick="document.getElementById('bulkEditDateMatproModal').classList.add('hidden')" class="w-1/2 px-6 py-3 bg-slate-100 text-slate-600 font-bold text-xs rounded-xl hover:bg-slate-200 transition-all uppercase tracking-widest">Batal</button>
                <button type="submit" class="w-1/2 px-6 py-3 bg-purple-600 text-white font-bold text-xs rounded-xl hover:bg-purple-700 shadow-lg shadow-purple-200 transition-all uppercase tracking-widest">Simpan Semua</button>
            </div>
        </form>
    </div>
</div>

    <div id="rejectModal" class="modal-overlay hidden">
        <div class="glass-card p-10 rounded-3xl w-full max-w-lg border border-white/50 relative overflow-hidden transition-all duration-500">
            <h2 class="text-2xl font-extrabold text-slate-800 mb-2">Tolak Permintaan</h2>
            <p class="text-xs text-slate-500 mb-8 font-medium">Berikan alasan mengapa permintaan ini ditolak.</p>
            
            <form id="rejectForm" action="process/admin_laporan_matpro_process.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="review_request">
                <input type="hidden" name="request_id" id="reject_request_id_input">
                <input type="hidden" name="request_table_type" id="reject_request_table_type_input">
                <input type="hidden" name="decision" value="reject">
                
                <div class="space-y-2">
                    <label for="reason" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Alasan Penolakan</label>
                    <textarea name="reason" id="reason" rows="4" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-red-100 transition-all" required placeholder="Contoh: Data tidak sesuai dengan lampiran."></textarea>
                </div>

                <div class="flex gap-4 pt-4">
                    <button type="button" onclick="closeRejectModal()" class="w-1/2 px-6 py-3 bg-slate-100 text-slate-600 font-bold text-xs rounded-xl hover:bg-slate-200 transition-all uppercase tracking-widest">Batal</button>
                    <button type="submit" class="w-1/2 px-6 py-3 bg-red-600 text-white font-bold text-xs rounded-xl hover:bg-red-700 shadow-lg shadow-red-200 transition-all uppercase tracking-widest">Tolak</button>
                </div>
            </form>
        </div>
    </div>

    <div id="rejectAllModal" class="modal-overlay hidden">
        <div class="glass-card p-10 rounded-3xl w-full max-w-lg border border-white/50 relative overflow-hidden transition-all duration-500">
            <h2 class="text-2xl font-extrabold text-slate-800 mb-2">Tolak Semua</h2>
            <p class="text-xs text-slate-500 mb-8 font-medium italic">Anda akan menolak SEMUA permintaan matpro yang tertunda.</p>
            
            <form id="rejectAllForm" action="process/admin_laporan_matpro_process.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="reject_all_requests">
                
                <div class="space-y-2">
                    <label for="reject_all_reason" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Alasan Penolakan Massal</label>
                    <textarea name="reason" id="reject_all_reason" rows="4" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-red-100 transition-all" required placeholder="Contoh: Peninjauan ditunda hingga periode berikutnya."></textarea>
                </div>

                <div class="flex gap-4 pt-4">
                    <button type="button" onclick="closeRejectAllModal()" class="w-1/2 px-6 py-3 bg-slate-100 text-slate-600 font-bold text-xs rounded-xl hover:bg-slate-200 transition-all uppercase tracking-widest">Batal</button>
                    <button type="submit" class="w-1/2 px-6 py-3 bg-red-600 text-white font-bold text-xs rounded-xl hover:bg-red-700 shadow-lg shadow-red-200 transition-all uppercase tracking-widest">Tolak Semua</button>
                </div>
            </form>
        </div>
    </div>

    <form id="exportForm" action="process/admin_export_matpro_activities_process.php" method="POST" class="hidden">
        <input type="hidden" name="action" value="export_filtered">
        <input type="hidden" name="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>">
        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
        <input type="hidden" name="brand" value="<?php echo htmlspecialchars($brand_filter); ?>">
        <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($branch_filter); ?>">
        <input type="hidden" name="mc_id" value="<?php echo htmlspecialchars($mc_filter); ?>">
        <input type="hidden" name="project_name" value="<?php echo htmlspecialchars($project_filter); ?>">
        <input type="hidden" name="type_name" value="<?php echo htmlspecialchars($type_filter); ?>">
    </form>

    <script>
        // === Matpro Date Edit Helpers ===
        function openMatproEditDateModal(id, date, time) {
            document.getElementById('editDateMatproId').value = id;
            document.getElementById('editDateMatproDate').value = date;
            document.getElementById('editDateMatproTime').value = time;
            document.getElementById('editDateMatproModal').classList.remove('hidden');
        }

        function openMatproBulkEditDateModal() {
            const checked = document.querySelectorAll('.activity-checkbox:checked');
            if (checked.length === 0) return;
            const container = document.getElementById('bulkEditMatproIdsContainer');
            container.innerHTML = '';
            checked.forEach(cb => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'activity_ids[]';
                inp.value = cb.value;
                container.appendChild(inp);
            });
            document.getElementById('bulkEditMatproCount').textContent = checked.length + ' aktivitas dipilih';
            document.getElementById('bulkEditDateMatproModal').classList.remove('hidden');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('selectAllActivities');
            const activityCheckboxes = document.querySelectorAll('.activity-checkbox');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const bulkEditDateBtn = document.getElementById('bulkEditDateMatproBtn');

            function updateBulkButtons() {
                const checkedCount = document.querySelectorAll('.activity-checkbox:checked').length;
                bulkDeleteBtn.disabled = checkedCount === 0;
                if (bulkEditDateBtn) bulkEditDateBtn.disabled = checkedCount === 0;
            }

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    activityCheckboxes.forEach(checkbox => { checkbox.checked = this.checked; });
                    updateBulkButtons();
                });
            }

            activityCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    if (selectAllCheckbox) {
                        selectAllCheckbox.checked = document.querySelectorAll('.activity-checkbox:checked').length === activityCheckboxes.length;
                    }
                    updateBulkButtons();
                });
            });

            updateBulkButtons();

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
            if(!exportForm) return;

            // Reset/Set default values from filter form if needed
            const filterForm = document.getElementById('filterForm');
            
            if (type === 'mtd') {
                const now = new Date();
                const formatDate = (date) => {
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    return `${year}-${month}-${day}`;
                };
                const firstDay = formatDate(new Date(now.getFullYear(), now.getMonth(), 1));
                const lastDay = formatDate(new Date(now.getFullYear(), now.getMonth() + 1, 0));
                
                exportForm.querySelector('[name="action"]').value = 'export_mtd';
                exportForm.querySelector('[name="start_date"]').value = firstDay;
                exportForm.querySelector('[name="end_date"]').value = lastDay;
                exportForm.querySelector('[name="keyword"]').value = '';
                exportForm.querySelector('[name="brand"]').value = '';
                exportForm.querySelector('[name="branch_id"]').value = '';
                exportForm.querySelector('[name="project_name"]').value = '';
                exportForm.querySelector('[name="type_name"]').value = '';
            } else if (type === 'all') {
                exportForm.querySelector('[name="action"]').value = 'export_all';
                exportForm.querySelector('[name="start_date"]').value = '';
                exportForm.querySelector('[name="end_date"]').value = '';
                exportForm.querySelector('[name="keyword"]').value = '';
                exportForm.querySelector('[name="brand"]').value = '';
                exportForm.querySelector('[name="branch_id"]').value = '';
                exportForm.querySelector('[name="project_name"]').value = '';
                exportForm.querySelector('[name="type_name"]').value = '';
            } else {
                // Filtered
                exportForm.querySelector('[name="action"]').value = 'export_filtered';
                exportForm.querySelector('[name="keyword"]').value = document.getElementById('keyword').value;
                exportForm.querySelector('[name="start_date"]').value = document.getElementById('start_date').value;
                exportForm.querySelector('[name="end_date"]').value = document.getElementById('end_date').value;
                exportForm.querySelector('[name="project_name"]').value = document.getElementById('project_name').value;
                exportForm.querySelector('[name="type_name"]').value = document.getElementById('type_name').value;
                exportForm.querySelector('[name="branch_id"]').value = document.getElementById('branch_id').value;
                // Note: brand filter handled by hidden input from PHP if needed, 
                // but let's stick to the current UI inputs for consistency.
            }

            exportForm.submit();
        }

        function openRejectModal(requestId, requestTableType) {
            document.getElementById('reject_request_id_input').value = requestId;
            document.getElementById('reject_request_table_type_input').value = requestTableType;
            document.getElementById('rejectModal').classList.remove('hidden');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.add('hidden');
        }

        function openRejectAllModal() {
            document.getElementById('rejectAllModal').classList.remove('hidden');
        }

        function closeRejectAllModal() {
            document.getElementById('rejectAllModal').classList.add('hidden');
        }
    </script>
        </main>
    </div>
</body>
</html>
```
