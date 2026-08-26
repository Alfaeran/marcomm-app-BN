<?php
// dashboard_user.php
require_once 'config/database.php';

// Cek jika user tidak login atau bukan 'user'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}

$user_id = (int)$_SESSION['id'];
$app_name = get_setting($mysqli, 'app_name');
$app_logo = get_setting($mysqli, 'app_logo');

// Inisialisasi pesan
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// --- Ambil Statistik User ---
// 1. Statistik Hari Ini
$today_stats_result = $mysqli->query("SELECT COUNT(*) as total, SUM(benefit_total) as revenue FROM event_submissions WHERE user_id = $user_id AND waktu_input >= CURDATE()");
$today_stats = ($today_stats_result) ? $today_stats_result->fetch_assoc() : ['total' => 0, 'revenue' => 0];
$today_event_count = $today_stats['total'] ?? 0;
$today_revenue = $today_stats['revenue'] ?? 0;

// 2. Statistik Bulan Ini
$month_stats_result = $mysqli->query("SELECT COUNT(*) as total FROM event_submissions WHERE user_id = $user_id AND waktu_input >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$month_event_count = ($month_stats_result) ? $month_stats_result->fetch_assoc()['total'] : 0;

// 3. Peringkat Bulanan (Leaderboard-ish)
$rank_query = "
    SELECT user_id, COUNT(unique_id) as event_count,
           RANK() OVER (ORDER BY COUNT(unique_id) DESC) as user_rank
    FROM event_submissions
    WHERE MONTH(waktu_input) = MONTH(CURDATE()) AND YEAR(waktu_input) = YEAR(CURDATE())
    GROUP BY user_id
";
$rank_result = $mysqli->query($rank_query);
$user_rank = 'N/A';
if ($rank_result) {
    while($row = $rank_result->fetch_assoc()) {
        if ($row['user_id'] == $user_id) {
            $user_rank = $row['user_rank'];
            break;
        }
    }
}

// 4. Riwayat Event Terakhir (dengan Status Request)
$history_result = $mysqli->query("
    SELECT e.unique_id, e.event_name, e.waktu_input, s.site_name
    FROM event_submissions e 
    LEFT JOIN sites s ON e.site_id = s.id 
    WHERE e.user_id = $user_id 
    ORDER BY e.waktu_input DESC 
    LIMIT 10
");

$editable_columns = [
    'sp_0k' => 'SP 0K', 'sp_3gb' => 'SP 3GB', 'sp_5gb' => 'SP 5GB', 'sp_7gb' => 'SP 7GB', 'sp_100gb' => 'SP 100GB',
    'fwa' => 'FWA', 'fwa_5g' => 'FWA 5G', 'sp_existing' => 'SP Existing', 'hit_haji_umroh' => 'HIT Haji/Umroh',
    'jumlah_audience' => 'Jumlah Audience', 'reload' => 'Reload', 'mobo_paket' => 'Mobo/Paket', 'cost' => 'Cost',
    'alasan' => 'Alasan / Feedback', 'provider_digunakan' => 'Provider Digunakan', 'provider_terbaik' => 'Provider Sinyal Terbaik',
    'kenal_im3' => 'Mengenal IM3?', 'sudah_beli_im3' => 'Sudah Beli IM3?', 'lokasi_beli' => 'Lokasi Beli',
    'tertarik_beli_im3' => 'Tertarik Beli IM3?', 'foto_event_url' => 'Foto Event', 'msisdn_file' => 'File MSISDN'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Dashboard User - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
</head>
<body class="min-h-screen">
    <!-- Aurora Background Blobs -->
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>

    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php include 'components/sidebar_user.php'; ?>

        <!-- Main Content -->
        <main class="flex-grow p-4 lg:p-10 lg:ml-64 overflow-y-auto min-w-0">
            <!-- Header -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-3 text-slate-600 glass-card relative z-[60] active:scale-95 transition-transform"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Halo, <?php echo explode(' ', $_SESSION["username"])[0]; ?>! 👋</h2>
                        <p class="text-slate-500 font-medium">Berikut adalah rangkuman aktivitas lapangan Anda hari ini.</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <div class="flex flex-wrap gap-3">
                        <a href="input_form.php" class="px-5 py-2.5 bg-blue-600 text-white font-bold text-sm rounded-xl hover:bg-blue-700 shadow-lg shadow-blue-200 active:scale-95 transition-all flex items-center gap-2">
                            <i class="fas fa-plus"></i> Input Event
                        </a>
                        <a href="matpro_input_form.php" class="px-5 py-2.5 bg-slate-800 text-white font-bold text-sm rounded-xl hover:bg-slate-900 shadow-lg active:scale-95 transition-all flex items-center gap-2">
                            <i class="fas fa-box"></i> Aktivitas Matpro
                        </a>
                    </div>
                </div>
            </header>

            <?php if ($success_message): ?>
                <div class="glass-card bg-emerald-50/50 border-emerald-200 p-4 mb-8 flex items-center gap-3">
                    <div class="h-10 w-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle"></i></div>
                    <p class="text-emerald-800 font-bold"><?php echo htmlspecialchars($success_message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
                <div class="glass-card p-6 border-l-4 border-blue-500 transition-all hover:translate-y-1">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-3 bg-blue-50 text-blue-600 rounded-xl"><i class="fas fa-calendar-day text-lg"></i></div>
                        <span class="text-[10px] font-black text-blue-500 bg-blue-50 px-2 py-1 rounded-full uppercase">HARI INI</span>
                    </div>
                    <h3 class="text-sm font-bold text-slate-500 uppercase tracking-widest mb-1">Event</h3>
                    <p class="text-3xl font-black text-slate-800"><?php echo number_format($today_event_count); ?></p>
                    <p class="text-xs text-slate-400 mt-1 font-bold">Revenue: Rp <?php echo number_format($today_revenue, 0, ',', '.'); ?></p>
                </div>

                <div class="glass-card p-6 border-l-4 border-indigo-500 transition-all hover:translate-y-1">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-3 bg-indigo-50 text-indigo-600 rounded-xl"><i class="fas fa-layer-group text-lg"></i></div>
                        <span class="text-[10px] font-black text-indigo-500 bg-indigo-50 px-2 py-1 rounded-full uppercase">BULAN INI</span>
                    </div>
                    <h3 class="text-sm font-bold text-slate-500 uppercase tracking-widest mb-1">Total Event</h3>
                    <p class="text-3xl font-black text-slate-800"><?php echo number_format($month_event_count); ?></p>
                    <div class="w-full bg-slate-100 rounded-full h-1.5 mt-3">
                        <div class="bg-indigo-500 h-1.5 rounded-full" style="width: <?php echo min(100, ($month_event_count/50)*100); ?>%"></div>
                    </div>
                </div>

                <div class="glass-card p-6 border-l-4 border-amber-500 transition-all hover:translate-y-1">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-3 bg-amber-50 text-amber-600 rounded-xl"><i class="fas fa-trophy text-lg"></i></div>
                        <span class="text-[10px] font-black text-amber-500 bg-amber-50 px-2 py-1 rounded-full uppercase">PERINGKAT</span>
                    </div>
                    <h3 class="text-sm font-bold text-slate-500 uppercase tracking-widest mb-1">Rank Regional</h3>
                    <p class="text-3xl font-black text-slate-800">#<?php echo $user_rank; ?></p>
                    <p class="text-xs text-slate-400 mt-1 font-bold">Terus Berikan yang Terbaik!</p>
                </div>

                <div class="glass-card p-6 border-l-4 border-emerald-500 transition-all hover:translate-y-1">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-xl"><i class="fas fa-map-marked-alt text-lg"></i></div>
                        <span class="text-[10px] font-black text-emerald-500 bg-emerald-50 px-2 py-1 rounded-full uppercase">PETA</span>
                    </div>
                    <h3 class="text-sm font-bold text-slate-500 uppercase tracking-widest mb-1">Peta Sebaran</h3>
                    <a href="admin_map_view.php" class="mt-2 block w-full py-2 bg-emerald-600 text-white text-center rounded-xl text-xs font-bold hover:bg-emerald-700 transition-colors">Lihat Peta Saya</a>
                </div>
            </div>

            <!-- History Section -->
            <div class="glass-card p-0 overflow-hidden mb-10">
                <div class="px-8 py-6 border-b border-white/20 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h3 class="text-xl font-extrabold text-slate-800 tracking-tight">Riwayat Aktivitas Terakhir</h3>
                        <p class="text-xs text-slate-500 font-medium">Menampilkan 10 data submission event terbaru Anda</p>
                    </div>
                    <div class="relative group">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs transition-colors group-focus-within:text-blue-500"></i>
                        <input type="text" id="eventSearch" placeholder="Cari event..." class="pl-10 pr-4 py-2.5 w-full md:w-64 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all outline-none">
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left" id="eventHistoryTable">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th class="px-8 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Nama Event</th>
                                <th class="px-8 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Site Name</th>
                                <th class="px-8 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Waktu Input</th>
                                <th class="px-8 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($history_result && $history_result->num_rows > 0): ?>
                                <?php while($event = $history_result->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors group">
                                    <td class="px-8 py-4">
                                        <p class="text-sm font-bold text-slate-800 group-hover:text-blue-600 transition-colors"><?php echo htmlspecialchars($event['event_name']); ?></p>
                                    </td>
                                    <td class="px-8 py-4">
                                        <span class="px-3 py-1 bg-slate-100 text-slate-600 rounded-full text-[10px] font-bold"><?php echo htmlspecialchars($event['site_name'] ?? 'N/A'); ?></span>
                                    </td>
                                    <td class="px-8 py-4">
                                        <p class="text-[11px] font-medium text-slate-500"><?php echo date('d M Y, H:i', strtotime($event['waktu_input'])); ?></p>
                                    </td>
                                    <td class="px-8 py-4">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="user_detail_event.php?id=<?php echo $event['unique_id']; ?>" class="h-8 w-8 flex items-center justify-center bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-600 hover:text-white transition-all shadow-sm" title="Lihat Detail">
                                                <i class="fas fa-eye text-xs"></i>
                                            </a>
                                            

                                            <button type="button" class="h-8 w-8 flex items-center justify-center bg-red-50 text-red-600 rounded-lg hover:bg-red-600 hover:text-white transition-all shadow-sm request-btn <?php echo ($status === 'pending' || $status === 'approved') ? 'opacity-30 cursor-not-allowed' : ''; ?>" 
                                                    data-event-id="<?php echo $event['unique_id']; ?>"
                                                    data-event-name="<?php echo htmlspecialchars($event['event_name']); ?>"
                                                    data-request-type="delete" title="Request Hapus" <?php echo ($status === 'pending' || $status === 'approved') ? 'disabled' : ''; ?>>
                                                <i class="fas fa-trash-alt text-xs"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="px-8 py-10 text-center text-slate-400 font-medium">Belum ada riwayat aktivitas.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Modals & Scripts -->
    <?php include 'components/modals_user.php'; ?>

    <script>
        // Optimized debounced search functionality
        function debounce(func, timeout = 300) {
            let timer;
            return (...args) => {
                clearTimeout(timer);
                timer = setTimeout(() => { func.apply(this, args); }, timeout);
            };
        }

        const handleSearch = debounce((val) => {
            const rows = document.querySelectorAll('#eventHistoryTable tbody tr');
            let foundCount = 0;
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const isMatch = text.includes(val);
                row.style.display = isMatch ? '' : 'none';
                if (isMatch) foundCount++;
            });
            
            // Handle empty state
            const emptyState = document.getElementById('tableEmptyState');
            if (foundCount === 0 && val !== '') {
                if (!emptyState) {
                    const tbody = document.querySelector('#eventHistoryTable tbody');
                    const tr = document.createElement('tr');
                    tr.id = 'tableEmptyState';
                    tr.innerHTML = `<td colspan="4" class="px-8 py-10 text-center text-slate-400 font-medium">Tidak ada data yang cocok dengan pencarian "${val}".</td>`;
                    tbody.appendChild(tr);
                }
            } else if (emptyState) {
                emptyState.remove();
            }
        });

        document.getElementById('eventSearch').addEventListener('input', function() {
            handleSearch(this.value.toLowerCase());
        });
    </script>
</body>
</html>
