<?php
require_once 'config/database.php';

// Cek jika user tidak login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Ambil pengaturan aplikasi
$app_name = get_setting($mysqli, 'app_name');
$app_logo = get_setting($mysqli, 'app_logo');
$user_role = $_SESSION['role'];

// Tentukan link kembali berdasarkan role
$dashboard_link = ($user_role == 'admin') ? 'dashboard_admin.php' : 'dashboard_user.php';

// --- Logika untuk Filter & Urutan ---
// Daftar urutan yang diizinkan untuk keamanan & mapping ke kolom asli
$sort_mapping = [
    'total_benefit' => 'benefit_total',
    'total_events' => 'unique_id',
    'total_qsc' => 'jumlah_qsc'
];

$sort_by = $_GET['sort_by'] ?? 'total_benefit'; // Capture sort key from URL
$sort_col = $sort_mapping[$sort_by] ?? 'benefit_total';
$order_by_sql = "$sort_by DESC"; // Menggunakan alias untuk order by (Boleh di MySQL/MariaDB)

// Query untuk mengambil data leaderboard bulan ini
// DENSE_RANK() OVER (ORDER BY ...) untuk menentukan peringkat
$sql = "SELECT 
            u.username,
            u.nama,
            u.brand,
            b.nama_branch,
            SUM(e.benefit_total) as total_benefit,
            COUNT(e.unique_id) as total_events,
            SUM(e.jumlah_qsc) as total_qsc,
            DENSE_RANK() OVER (ORDER BY " . ($sort_by === 'total_events' ? "COUNT(e.unique_id)" : "SUM(e.$sort_col)") . " DESC) as user_rank
        FROM 
            event_submissions e
        JOIN 
            users u ON e.user_id = u.id
        LEFT JOIN 
            branches b ON u.branch_id = b.id
        WHERE 
            MONTH(e.waktu_input) = MONTH(CURDATE()) AND YEAR(e.waktu_input) = YEAR(CURDATE())
        GROUP BY 
            u.id, u.username, u.nama, u.brand, b.nama_branch
        ORDER BY 
            $order_by_sql";

$leaderboard_result = $mysqli->query($sql);

if ($leaderboard_result === false) {
    // Debugging jika query gagal (akan tampil di error log atau layar jika display_errors on)
    error_log("Leaderboard Query Error: " . $mysqli->error);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Leaderboard - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
    <style>
        .rank-badge {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-weight: 900;
            font-size: 1.1rem;
        }
        .rank-1 { background: linear-gradient(135deg, #ffd700, #ffb700); color: #7c2d12; box-shadow: 0 4px 12px rgba(255, 183, 0, 0.3); }
        .rank-2 { background: linear-gradient(135deg, #e2e8f0, #94a3b8); color: #1e293b; box-shadow: 0 4px 12px rgba(148, 163, 184, 0.3); }
        .rank-3 { background: linear-gradient(135deg, #fbbf24, #b45309); color: #451a03; box-shadow: 0 4px 12px rgba(180, 83, 9, 0.3); }
        .rank-other { background: #f1f5f9; color: #64748b; }
    </style>
</head>
<body class="min-h-screen">
    <!-- Aurora Background Blobs -->
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>

    <div class="flex">
        <!-- Sidebar Selection based on role -->
        <?php 
        if ($user_role == 'admin') {
            include 'components/sidebar_admin.php';
        } else {
            include 'components/sidebar_user.php';
        }
        ?>

        <!-- Main Content -->
        <main class="flex-grow p-4 lg:p-10 lg:ml-64 min-w-0">
            <!-- Header -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-3 text-slate-600 glass-card"><i class="fas fa-bars"></i></button>
                    <div>
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 bg-amber-100 text-amber-600 rounded-xl flex items-center justify-center text-xl shadow-sm">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Leaderboard Regional</h2>
                        </div>
                        <p class="text-slate-500 font-medium mt-1">Peringkat performa tim bulan ini (<?php echo date('F Y'); ?>).</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <a href="<?php echo $dashboard_link; ?>" class="px-5 py-3 bg-white/50 backdrop-blur-md border border-slate-200 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </header>

            <div class="max-w-6xl">
                <!-- Filter Section -->
                <div class="glass-card p-6 mb-8">
                    <form action="leaderboard.php" method="GET" class="flex flex-col md:flex-row items-center gap-6">
                        <div class="flex items-center gap-4">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Urutkan Berdasarkan:</span>
                            <div class="flex gap-2">
                                <button type="submit" name="sort_by" value="total_benefit" class="px-4 py-2 <?php echo ($sort_by == 'total_benefit') ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?> text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">
                                    <i class="fas fa-coins mr-1"></i> Total Benefit
                                </button>
                                <button type="submit" name="sort_by" value="total_events" class="px-4 py-2 <?php echo ($sort_by == 'total_events') ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?> text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">
                                    <i class="fas fa-calendar-check mr-1"></i> Jumlah Event
                                </button>
                                <button type="submit" name="sort_by" value="total_qsc" class="px-4 py-2 <?php echo ($sort_by == 'total_qsc') ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?> text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">
                                    <i class="fas fa-users mr-1"></i> Total QSC
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Leaderboard Table -->
                <div class="glass-card overflow-hidden shadow-xl shadow-blue-900/5">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50/50">
                                <tr>
                                    <th class="py-4 px-8 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Rank</th>
                                    <th class="py-4 px-8 text-[10px] font-black text-slate-400 uppercase tracking-widest">User / Agent</th>
                                    <th class="py-4 px-8 text-[10px] font-black text-slate-400 uppercase tracking-widest">Branch</th>
                                    <th class="py-4 px-8 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Event</th>
                                    <th class="py-4 px-8 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">QSC</th>
                                    <th class="py-4 px-8 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if ($leaderboard_result && $leaderboard_result->num_rows > 0): ?>
                                    <?php while($user = $leaderboard_result->fetch_assoc()): 
                                        $rank = $user['user_rank'];
                                        $rank_class = 'rank-other';
                                        if ($rank == 1) $rank_class = 'rank-1';
                                        elseif ($rank == 2) $rank_class = 'rank-2';
                                        elseif ($rank == 3) $rank_class = 'rank-3';
                                    ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors group">
                                        <td class="py-5 px-8">
                                            <div class="flex justify-center">
                                                <div class="rank-badge <?php echo $rank_class; ?>">
                                                    <?php if ($rank <= 3): ?>
                                                        <i class="fas fa-crown text-[10px] absolute -top-1 -right-1 rotate-12"></i>
                                                    <?php endif; ?>
                                                    <?php echo $rank; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-5 px-8">
                                            <div class="flex items-center gap-3">
                                                <div class="h-10 w-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 font-bold text-xs uppercase">
                                                    <?php echo substr($user['username'], 0, 2); ?>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-black text-slate-800 tracking-tight"><?php echo htmlspecialchars($user['nama'] ?: $user['username']); ?></p>
                                                    <p class="text-[10px] font-bold text-blue-500 uppercase tracking-widest"><?php echo htmlspecialchars($user['brand']); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-5 px-8">
                                            <p class="text-xs font-bold text-slate-600"><?php echo htmlspecialchars($user['nama_branch'] ?? '-'); ?></p>
                                        </td>
                                        <td class="py-5 px-8 text-center text-xs font-extrabold text-slate-700">
                                            <?php echo number_format($user['total_events']); ?>
                                        </td>
                                        <td class="py-5 px-8 text-center text-xs font-extrabold text-slate-700">
                                            <?php echo number_format($user['total_qsc']); ?>
                                        </td>
                                        <td class="py-5 px-8 text-right">
                                            <p class="text-sm font-black text-slate-800">Rp <?php echo number_format($user['total_benefit'], 0, ',', '.'); ?></p>
                                            <div class="h-1 w-full bg-slate-100 rounded-full mt-2 overflow-hidden">
                                                <div class="h-full bg-blue-500 rounded-full" style="width: <?php echo min(100, ($user['total_benefit'] / 10000000) * 100); ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="py-20 text-center">
                                            <div class="flex flex-col items-center gap-3">
                                                <div class="h-16 w-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center text-2xl">
                                                    <i class="fas fa-ghost"></i>
                                                </div>
                                                <p class="text-slate-400 font-medium italic">Belum ada aktivitas di bulan ini.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Footer Info -->
                <div class="mt-8 p-6 bg-slate-900 rounded-3xl text-white flex flex-col md:flex-row justify-between items-center gap-6">
                    <div>
                        <h4 class="text-lg font-black tracking-tight">Semangat Terus, Champion! 🚀</h4>
                        <p class="text-slate-400 text-xs font-medium">Data diperbarui secara real-time setiap kali submission berhasil divalidasi.</p>
                    </div>
                    <?php if ($user_role !== 'admin'): ?>
                        <a href="dashboard_user.php" class="px-8 py-3 bg-blue-600 text-white font-black text-xs rounded-2xl hover:bg-blue-700 transition-all uppercase tracking-widest shadow-xl shadow-blue-900/40">
                            Ke Dashboard Saya
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
