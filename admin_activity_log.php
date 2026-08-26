<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}
$app_name = get_setting($mysqli, 'app_name');

// --- Logika Paginasi & Filter ---
$records_per_page = 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$where_clauses = [];
$param_types = "";
$param_values = [];
$filter_query_string = "";

// Ambil filter dari URL
$filters = [
    'start_date' => $_GET['start_date'] ?? date('Y-m-01'),
    'end_date' => $_GET['end_date'] ?? date('Y-m-t'),
    'username_filter' => $_GET['username_filter'] ?? '', // Filter baru
    'action_type_filter' => $_GET['action_type_filter'] ?? '' // Filter baru
];

// Bangun klausa WHERE secara dinamis
if (!empty($filters['start_date'])) {
    $where_clauses[] = "timestamp >= ?";
    $param_types .= "s";
    $param_values[] = $filters['start_date'] . " 00:00:00";
    $filter_query_string .= "&start_date=" . urlencode($filters['start_date']);
}
if (!empty($filters['end_date'])) {
    $where_clauses[] = "timestamp <= ?";
    $param_types .= "s";
    $param_values[] = $filters['end_date'] . " 23:59:59";
    $filter_query_string .= "&end_date=" . urlencode($filters['end_date']);
}
if (!empty($filters['username_filter'])) { // Filter berdasarkan username
    $where_clauses[] = "username LIKE ?";
    $param_types .= "s";
    $param_values[] = "%" . $filters['username_filter'] . "%";
    $filter_query_string .= "&username_filter=" . urlencode($filters['username_filter']);
}
if (!empty($filters['action_type_filter'])) { // Filter berdasarkan action type
    $where_clauses[] = "action = ?";
    $param_types .= "s";
    $param_values[] = $filters['action_type_filter'];
    $filter_query_string .= "&action_type_filter=" . urlencode($filters['action_type_filter']);
}

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

// Query untuk menghitung total data
$count_sql = "SELECT COUNT(id) as total FROM activity_logs" . $where_sql;
$stmt_count = $mysqli->prepare($count_sql);
if (!empty($param_values)) {
    $stmt_count->bind_param($param_types, ...$param_values);
}
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Query untuk mengambil log
$sql = "SELECT username, action, description, timestamp, ip_address, user_agent FROM activity_logs" . $where_sql . " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($param_types_page, ...$param_values_page);
$stmt->execute();
$logs_result = $stmt->get_result();

// Ambil daftar unik action types untuk filter dropdown
$action_types_query = $mysqli->query("SELECT DISTINCT action FROM activity_logs ORDER BY action");

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Log Aktivitas - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Log Aktivitas</h2>
                        <p class="text-slate-500 font-medium">Rekaman jejak digital aksi pengguna dan perubahan sistem secara real-time.</p>
                    </div>
                </div>
            </header>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="glass-card bg-emerald-50/50 border-emerald-200 p-4 mb-8 flex items-center gap-3">
                    <div class="h-10 w-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle"></i></div>
                    <p class="text-emerald-800 font-bold"><?php echo htmlspecialchars($_SESSION['success_message']); ?></p>
                </div>
            <?php unset($_SESSION['success_message']); endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="glass-card bg-red-50/50 border-red-200 p-4 mb-8 flex items-center gap-3">
                    <div class="h-10 w-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center"><i class="fas fa-exclamation-triangle"></i></div>
                    <p class="text-red-800 font-bold"><?php echo htmlspecialchars($_SESSION['error_message']); ?></p>
                </div>
            <?php unset($_SESSION['error_message']); endif; ?>

            <!-- Advanced Filter Card -->
            <div class="glass-card p-8 mb-10">
                <div class="flex items-center gap-3 mb-8">
                    <div class="h-10 w-4 bg-blue-600 rounded-full"></div>
                    <h3 class="text-xl font-extrabold text-slate-800 tracking-tight">Filter Audit Trail</h3>
                </div>

                <form action="admin_activity_log.php" method="GET" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="space-y-2">
                            <label for="start_date" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Dari Tanggal</label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($filters['start_date']); ?>" 
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all">
                        </div>
                        <div class="space-y-2">
                            <label for="end_date" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Sampai Tanggal</label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($filters['end_date']); ?>" 
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all">
                        </div>
                        <div class="space-y-2">
                            <label for="username_filter" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Username / User</label>
                            <input type="text" name="username_filter" id="username_filter" value="<?php echo htmlspecialchars($filters['username_filter']); ?>" 
                                   placeholder="Cari user..."
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all">
                        </div>
                        <div class="space-y-2">
                            <label for="action_type_filter" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Jenis Aksi</label>
                            <div class="relative">
                                <select name="action_type_filter" id="action_type_filter" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                    <option value="">Semua Aksi</option>
                                    <?php mysqli_data_seek($action_types_query, 0); while($action_type = $action_types_query->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($action_type['action']); ?>" <?php if ($filters['action_type_filter'] == $action_type['action']) echo 'selected'; ?>><?php echo htmlspecialchars($action_type['action']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <a href="admin_activity_log.php" class="px-8 py-3 bg-slate-100 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-200 transition-all text-center">
                            <i class="fas fa-undo mr-2"></i> Reset
                        </a>
                        <button type="submit" class="px-10 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 active:scale-95 transition-all">
                            <i class="fas fa-filter mr-2"></i> Terapkan Filter
                        </button>
                    </div>
                </form>
            </div>

            <!-- Log Table -->
            <div class="glass-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Waktu Kejadian</th>
                                <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Aktor</th>
                                <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Aksi</th>
                                <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Deskripsi Operasi</th>
                                <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Metadata</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white/30 text-slate-700">
                            <?php if ($logs_result->num_rows > 0): ?>
                                <?php while($log = $logs_result->fetch_assoc()): ?>
                                <tr class="hover:bg-blue-50/30 transition-colors">
                                    <td class="py-4 px-6">
                                        <div class="flex flex-col">
                                            <span class="text-xs font-extrabold text-slate-800"><?php echo date('d M Y', strtotime($log['timestamp'])); ?></span>
                                            <span class="text-[10px] font-bold text-blue-500"><?php echo date('H:i:s', strtotime($log['timestamp'])); ?> WIB</span>
                                        </div>
                                    </td>
                                    <td class="py-4 px-6">
                                        <div class="flex items-center gap-2">
                                            <div class="h-7 w-7 bg-slate-100 text-slate-500 rounded-lg flex items-center justify-center text-[10px] font-black">
                                                <?php echo strtoupper(substr($log['username'], 0, 1)); ?>
                                            </div>
                                            <span class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($log['username']); ?></span>
                                        </div>
                                    </td>
                                    <td class="py-4 px-6">
                                        <span class="inline-flex px-2.5 py-1 text-[10px] font-black uppercase tracking-tighter rounded-lg bg-blue-100 text-blue-700 border border-blue-200/50">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 max-w-md">
                                        <p class="text-xs font-medium text-slate-600 leading-relaxed"><?php echo htmlspecialchars($log['description']); ?></p>
                                    </td>
                                    <td class="py-4 px-6">
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] font-black text-slate-400 flex items-center gap-1.5"><i class="fas fa-network-wired text-[8px]"></i> <?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></span>
                                            <span class="text-[9px] font-medium text-slate-400 flex items-center gap-1.5 max-w-[150px] truncate" title="<?php echo htmlspecialchars($log['user_agent'] ?? 'N/A'); ?>"><i class="fas fa-laptop text-[8px]"></i> <?php echo htmlspecialchars($log['user_agent'] ?? 'N/A'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="py-24 text-center">
                                        <div class="flex flex-col items-center gap-4">
                                            <div class="h-20 w-20 bg-slate-50 text-slate-300 rounded-3xl flex items-center justify-center text-3xl">
                                                <i class="fas fa-history"></i>
                                            </div>
                                            <div>
                                                <p class="text-slate-800 font-extrabold">Log tidak ditemukan</p>
                                                <p class="text-slate-400 text-sm font-medium mt-1">Belum ada rekaman aktivitas untuk kriteria filter ini.</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <div class="mt-10 flex flex-col md:flex-row items-center justify-between gap-6 pb-12">
                <p class="text-sm font-bold text-slate-500 order-2 md:order-1">
                    Menampilkan <span class="text-slate-800"><?php echo $logs_result->num_rows; ?></span> dari <span class="text-slate-800"><?php echo $total_records; ?></span> log
                </p>
                
                <?php if ($total_pages > 1): ?>
                <nav class="flex items-center gap-2 order-1 md:order-2">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $filter_query_string; ?>" 
                           class="h-10 px-4 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-all text-xs font-bold">
                            Sebelumnya
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_p = max(1, $page - 2);
                    $end_p = min($total_pages, $page + 2);
                    for ($i = $start_p; $i <= $end_p; $i++): 
                        $is_active = ($i == $page);
                    ?>
                        <a href="?page=<?php echo $i; ?><?php echo $filter_query_string; ?>" 
                           class="h-10 w-10 flex items-center justify-center rounded-xl <?php echo $is_active ? 'bg-blue-600 text-white shadow-lg shadow-blue-100 border-blue-600' : 'glass-card text-slate-600 hover:bg-blue-50 hover:text-blue-600'; ?> text-sm font-bold transition-all">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $filter_query_string; ?>" 
                           class="h-10 px-4 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-all text-xs font-bold">
                            Selanjutnya
                        </a>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
            </div>

            <!-- Danger Zone -->
            <div class="mt-10 pt-10 border-t border-slate-200">
                <div class="glass-card border-red-100 bg-red-50/20 p-8 flex flex-col md:flex-row items-center justify-between gap-6">
                    <div class="flex items-center gap-4">
                        <div class="h-14 w-14 bg-red-100 text-red-600 rounded-2xl flex items-center justify-center text-xl shadow-inner"><i class="fas fa-trash-alt"></i></div>
                        <div>
                            <h4 class="text-lg font-extrabold text-slate-800 tracking-tight">Maintenance Log</h4>
                            <p class="text-slate-500 text-sm font-medium">Hapus seluruh rekaman log untuk mengosongkan ruang database. <b>Tidakan ini permanen.</b></p>
                        </div>
                    </div>
                    <form action="process/admin_log_process.php" method="POST" onsubmit="return confirm('PERINGATAN! Aksi ini tidak dapat dibatalkan. Apakah Anda benar-benar yakin ingin menghapus SEMUA log aktivitas?');">
                        <input type="hidden" name="action" value="delete_all">
                        <button type="submit" class="px-8 py-4 bg-red-600 text-white font-black text-sm rounded-2xl hover:bg-red-700 shadow-xl shadow-red-100 transition-all flex items-center gap-2">
                             Kosongkan Riwayat Log
                        </button>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script></script>
</body>
</html>
