<?php
// File: dashboard_admin.php
require_once 'config/database.php';

// Cek jika user tidak login atau bukan 'admin'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

// Ambil pengaturan aplikasi
$app_name = get_setting($mysqli, 'app_name');
$app_logo = get_setting($mysqli, 'app_logo');

// --- PERBAIKAN: Ambil jumlah notifikasi ---
// 1. Notifikasi untuk permintaan Matpro (Hapus dan Edit)
$pending_requests_count_result = $mysqli->query("
    SELECT COUNT(id) as total FROM deletion_requests WHERE status = 'pending'
    UNION ALL
    SELECT COUNT(id) as total FROM matpro_edit_requests WHERE status = 'pending'
");
$pending_requests_count = 0;
if ($pending_requests_count_result) {
    while ($row = $pending_requests_count_result->fetch_assoc()) {
        $pending_requests_count += $row['total'];
    }
}


// 2. Notifikasi untuk event baru yang masuk hari ini
$new_events_today_count_result = $mysqli->query("SELECT COUNT(unique_id) as total FROM event_submissions WHERE DATE(waktu_input) = CURDATE()");
$new_events_today_count = ($new_events_today_count_result && $new_events_today_count_result->num_rows > 0) ? $new_events_today_count_result->fetch_assoc()['total'] : 0;


// --- Logika Statistik Event ---
$total_users_result = $mysqli->query("SELECT COUNT(id) as total FROM users");
$total_users = ($total_users_result && $total_users_result->num_rows > 0) ? $total_users_result->fetch_assoc()['total'] : 0;


// Statistik Event Hari Ini
$today_event_stats_result = $mysqli->query("SELECT COUNT(unique_id) as total, SUM(benefit_total) as revenue FROM event_submissions WHERE DATE(waktu_input) = CURDATE()");
$today_event_stats = ($today_event_stats_result && $today_event_stats_result->num_rows > 0) ? $today_event_stats_result->fetch_assoc() : ['total' => 0, 'revenue' => 0];

// Statistik Event Bulan Ini
$current_month_event_stats_result = $mysqli->query("SELECT COUNT(unique_id) as total, SUM(benefit_total) as revenue FROM event_submissions WHERE MONTH(waktu_input) = MONTH(CURDATE()) AND YEAR(waktu_input) = YEAR(CURDATE())");
$current_month_event_stats = ($current_month_event_stats_result && $current_month_event_stats_result->num_rows > 0) ? $current_month_event_stats_result->fetch_assoc() : ['total' => 0, 'revenue' => 0];

// Statistik Event Kuartal Ini
$current_quarter_event_stats_result = $mysqli->query("SELECT COUNT(unique_id) as total, SUM(benefit_total) as revenue FROM event_submissions WHERE QUARTER(waktu_input) = QUARTER(CURDATE()) AND YEAR(waktu_input) = YEAR(CURDATE())");
$current_quarter_event_stats = ($current_quarter_event_stats_result && $current_quarter_event_stats_result->num_rows > 0) ? $current_quarter_event_stats_result->fetch_assoc() : ['total' => 0, 'revenue' => 0];

// Statistik Event Tahun Ini
$current_year_event_stats_result = $mysqli->query("SELECT COUNT(unique_id) as total, SUM(benefit_total) as revenue FROM event_submissions WHERE YEAR(waktu_input) = YEAR(CURDATE())");
$current_year_event_stats = ($current_year_event_stats_result && $current_year_event_stats_result->num_rows > 0) ? $current_year_event_stats_result->fetch_assoc() : ['total' => 0, 'revenue' => 0];

// --- Logika Statistik Matpro ---
// Menggunakan 'activity_datetime' dan 'qty_used' sesuai struktur database yang dikonfirmasi
// Statistik Matpro Hari Ini
$today_matpro_stats_result = $mysqli->query("SELECT COUNT(id) as total_activities, SUM(qty_used) as total_qty FROM matpro_activities WHERE DATE(activity_datetime) = CURDATE()");
$today_matpro_stats = ($today_matpro_stats_result && $today_matpro_stats_result->num_rows > 0) ? $today_matpro_stats_result->fetch_assoc() : ['total_activities' => 0, 'total_qty' => 0];

// Statistik Matpro Bulan Ini
$current_month_matpro_stats_result = $mysqli->query("SELECT COUNT(id) as total_activities, SUM(qty_used) as total_qty FROM matpro_activities WHERE MONTH(activity_datetime) = MONTH(CURDATE()) AND YEAR(activity_datetime) = YEAR(CURDATE())");
$current_month_matpro_stats = ($current_month_matpro_stats_result && $current_month_matpro_stats_result->num_rows > 0) ? $current_month_matpro_stats_result->fetch_assoc() : ['total_activities' => 0, 'total_qty' => 0];

// Statistik Matpro Kuartal Ini
$current_quarter_matpro_stats_result = $mysqli->query("SELECT COUNT(id) as total_activities, SUM(qty_used) as total_qty FROM matpro_activities WHERE QUARTER(activity_datetime) = QUARTER(CURDATE()) AND YEAR(activity_datetime) = YEAR(CURDATE())");
$current_quarter_matpro_stats = ($current_quarter_matpro_stats_result && $current_quarter_matpro_stats_result->num_rows > 0) ? $current_quarter_matpro_stats_result->fetch_assoc() : ['total_activities' => 0, 'total_qty' => 0];

// Statistik Matpro Tahun Ini
$current_year_matpro_stats_result = $mysqli->query("SELECT COUNT(id) as total_activities, SUM(qty_used) as total_qty FROM matpro_activities WHERE YEAR(activity_datetime) = YEAR(CURDATE())");
$current_year_matpro_stats = ($current_year_matpro_stats_result && $current_year_matpro_stats_result->num_rows > 0) ? $current_year_matpro_stats_result->fetch_assoc() : ['total_activities' => 0, 'total_qty' => 0];


?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Admin Dashboard - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="min-h-screen">
    <!-- Aurora Background Blobs -->
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>
    <!-- Overlay for mobile sidebar -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>

    <div class="flex">
        <!-- Sidebar -->
        <?php include 'components/sidebar_admin.php'; ?>

        <!-- Main Content -->
        <main class="flex-grow p-4 lg:p-10 lg:ml-72 min-w-0">
            <!-- Header -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 text-slate-600 bg-white shadow-sm border rounded-lg relative z-[60] active:scale-95 transition-transform"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Dashboard Overview</h2>
                        <p class="text-slate-500 font-medium">Selamat datang kembali, <span class="text-slate-900"><?php echo htmlspecialchars($_SESSION["username"]); ?></span></p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <div class="flex -space-x-2">
                         <div class="h-10 w-10 rounded-full border-2 border-white bg-blue-500 flex items-center justify-center text-white font-bold text-sm shadow-sm ring-blue-100 ring-2">AD</div>
                    </div>
                    <?php if ($pending_requests_count > 0): ?>
                        <div class="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center text-red-600 shadow-sm border border-red-200 cursor-pointer relative pulse-red mr-2" onclick="location.href='admin_laporan_matpro.php#matpro-requests'">
                            <i class="fas fa-bell"></i>
                            <span class="absolute -top-1 -right-1 h-4 w-4 bg-red-600 text-[10px] text-white flex items-center justify-center rounded-full font-bold shadow-md ring-2 ring-white"><?php echo $pending_requests_count; ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </header>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-10">
                <!-- Today's Events -->
                <div class="stat-card bg-indigo-600 text-white">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-3 bg-white/20 rounded-xl"><i class="fas fa-calendar-day text-xl"></i></div>
                        <span class="text-xs font-bold uppercase tracking-widest text-indigo-100">TODAY</span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-4xl font-extrabold mb-1"><?php echo number_format($today_event_stats['total']); ?></span>
                        <span class="text-indigo-100 font-medium">Event Baru</span>
                        <div class="mt-4 pt-4 border-t border-white/10 flex items-center gap-2 text-sm text-indigo-50">
                            <span class="font-bold">Rp <?php echo number_format($today_event_stats['revenue'] / 1000, 0); ?>k</span>
                            <span class="opacity-70">Revenue Est.</span>
                        </div>
                    </div>
                </div>

                <!-- Monthly Activity -->
                <div class="stat-card bg-white border border-slate-200">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-xl"><i class="fas fa-chart-line text-xl"></i></div>
                        <span class="text-xs font-bold uppercase tracking-widest text-slate-400">THIS MONTH</span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-4xl font-extrabold mb-1 text-slate-800"><?php echo number_format($current_month_event_stats['total']); ?></span>
                        <span class="text-slate-500 font-medium">Submissions</span>
                        <div class="mt-4 pt-4 border-t border-slate-100 flex items-center gap-2 text-sm text-slate-600">
                            <span class="text-emerald-500 font-bold "><i class="fas fa-arrow-up text-[10px]"></i> Active</span>
                        </div>
                    </div>
                </div>

                <!-- Matpro Activities -->
                <div class="stat-card bg-white border border-slate-200">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-3 bg-amber-50 text-amber-600 rounded-xl"><i class="fas fa-shipping-fast text-xl"></i></div>
                        <span class="text-xs font-bold uppercase tracking-widest text-slate-400">MATPRO</span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-4xl font-extrabold mb-1 text-slate-800"><?php echo number_format($today_matpro_stats['total_activities']); ?></span>
                        <span class="text-slate-500 font-medium">Hari Ini</span>
                        <div class="mt-4 pt-4 border-t border-slate-100 flex items-center gap-2 text-sm text-slate-600">
                            <span class="font-bold text-amber-600"><?php echo number_format($today_matpro_stats['total_qty']); ?></span>
                            <span class="opacity-70">Item Keluar</span>
                        </div>
                    </div>
                </div>

                <!-- Users -->
                <div class="stat-card bg-white border border-slate-200">
                    <div class="flex justify-between items-start mb-4">
                        <div class="p-3 bg-slate-50 text-slate-600 rounded-xl"><i class="fas fa-users text-xl"></i></div>
                        <span class="text-xs font-bold uppercase tracking-widest text-slate-400">TOTAL USERS</span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-4xl font-extrabold mb-1 text-slate-800"><?php echo number_format($total_users); ?></span>
                        <span class="text-slate-500 font-medium">Terdaftar</span>
                        <div class="mt-4 pt-4 border-t border-slate-100 flex items-center gap-2 text-sm text-slate-400">
                            <span><i class="fas fa-circle text-[8px] text-green-500 mr-1"></i> System Online</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
                <!-- Trend Chart -->
                <div class="lg:col-span-2 glass-card rounded-3xl p-8">
                    <div class="flex items-center justify-between mb-10">
                        <div>
                            <h3 class="text-xl font-bold text-slate-800">Event Productivity Trend</h3>
                            <p class="text-sm text-slate-500 font-medium">Jumlah submission selama 14 hari terakhir</p>
                        </div>
                        <div class="flex gap-2">
                            <button class="p-2 text-slate-400 hover:text-slate-600 transition-colors"><i class="fas fa-ellipsis-h"></i></button>
                        </div>
                    </div>
                    <div class="h-80">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>

                <!-- Top Categories -->
                <div class="glass-card rounded-3xl p-8">
                    <h3 class="text-xl font-bold text-slate-800 mb-2 text-center">Event Distribution</h3>
                    <p class="text-sm text-slate-500 font-medium mb-8 text-center text-center">Berdasarkan kategori bulan ini</p>
                    <div class="h-64 relative">
                        <canvas id="categoryChart"></canvas>
                    </div>
                    <div id="categoryLegend" class="mt-6 space-y-2 max-h-40 overflow-y-auto pr-2">
                        <!-- Filled by JS -->
                    </div>
                </div>
            </div>

            <!-- Bottom Section Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Recent Activities Feed -->
                <div class="lg:col-span-2 glass-card rounded-3xl p-8">
                    <div class="flex items-center justify-between mb-8">
                        <h3 class="text-xl font-bold text-slate-800">Log Aktivitas Terbaru</h3>
                        <a href="admin_activity_log.php" class="text-blue-600 text-sm font-bold hover:underline">Lihat Semua</a>
                    </div>
                    <div class="space-y-6" id="activityFeed">
                        <!-- Loading placeholder -->
                        <div class="flex gap-4 animate-pulse">
                            <div class="h-10 w-10 bg-slate-100 rounded-full"></div>
                            <div class="flex-grow space-y-2">
                                <div class="h-4 bg-slate-100 rounded w-1/4"></div>
                                <div class="h-3 bg-slate-50 rounded w-full"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions / Quick Management -->
                <div class="glass-card rounded-3xl p-8 border-l-4 border-amber-500">
                    <h3 class="text-xl font-bold text-slate-800 mb-6">Aksi Cepat</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <a href="admin_import_users.php" class="p-4 rounded-2xl bg-slate-50 border border-slate-100 hover:bg-white hover:shadow-md transition-all text-center">
                            <i class="fas fa-user-plus text-slate-600 block mb-2 text-lg"></i>
                            <span class="text-xs font-bold text-slate-700">Import User</span>
                        </a>
                         <a href="admin_laporan_matpro.php" class="p-4 rounded-2xl bg-slate-50 border border-slate-100 hover:bg-white hover:shadow-md transition-all text-center">
                            <i class="fas fa-box-open text-slate-600 block mb-2 text-lg"></i>
                            <span class="text-xs font-bold text-slate-700">Request Stock</span>
                        </a>
                         <a href="admin_laporan_msisdn.php" class="p-4 rounded-2xl bg-indigo-50 border border-indigo-100 hover:bg-white hover:shadow-md transition-all text-center">
                            <i class="fas fa-search-plus text-indigo-600 block mb-2 text-lg"></i>
                            <span class="text-xs font-bold text-indigo-700">Cek MSISDN</span>
                        </a>
                         <button onclick="location.href='process/backup_database.php'" class="p-4 rounded-2xl bg-amber-50 border border-amber-100 hover:bg-white hover:shadow-md transition-all text-center">
                            <i class="fas fa-database text-amber-600 block mb-2 text-lg"></i>
                            <span class="text-xs font-bold text-amber-700">Backup DB</span>
                        </button>
                         <a href="admin_settings.php" class="p-4 rounded-2xl bg-slate-50 border border-slate-100 hover:bg-white hover:shadow-md transition-all text-center">
                            <i class="fas fa-tools text-slate-600 block mb-2 text-lg"></i>
                            <span class="text-xs font-bold text-slate-700">Settings</span>
                        </a>
                    </div>
                    
                    <div class="mt-8 pt-8 border-t border-slate-100">
                        <div class="flex items-center gap-4 p-4 rounded-2xl bg-blue-600 text-white shadow-xl shadow-blue-200">
                             <div class="h-12 w-12 rounded-xl bg-white/20 flex items-center justify-center text-xl">
                                 <i class="fas fa-external-link-alt"></i>
                             </div>
                             <div class="flex-grow">
                                 <p class="text-[10px] font-bold uppercase tracking-widest opacity-70">External Link</p>
                                 <h4 class="font-bold">PO Matpro System</h4>
                                 <a href="https://po_matpro.seratusfm.xyz/login" target="_blank" class="text-xs font-medium hover:underline flex items-center gap-1 mt-1">Visit External Site <i class="fas fa-chevron-right text-[8px]"></i></a>
                             </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>

        document.addEventListener('DOMContentLoaded', function() {
            // Trend Chart Initialization
            fetch('api_helper.php?action=get_daily_trend')
                .then(r => r.json())
                .then(data => {
                    const ctx = document.getElementById('trendChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.labels,
                            datasets: [{
                                label: 'Submissions',
                                data: data.counts,
                                fill: true,
                                backgroundColor: 'rgba(54, 162, 235, 0.05)',
                                borderColor: 'rgb(54, 162, 235)',
                                borderWidth: 3,
                                tension: 0.4,
                                pointBackgroundColor: 'rgb(255, 255, 255)',
                                pointBorderWidth: 2,
                                pointRadius: 4,
                                pointHoverRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: { beginAtZero: true, grid: { display: true, borderDash: [5, 5] }, ticks: { stepSize: 1 } },
                                x: { grid: { display: false } }
                            }
                        }
                    });
                });

            // Category Chart Initialization
            fetch('api_helper.php?action=get_category_distribution')
                .then(r => r.json())
                .then(data => {
                    const ctx = document.getElementById('categoryChart').getContext('2d');
                    const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#64748b'];
                    
                    new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: data.labels,
                            datasets: [{
                                data: data.values,
                                backgroundColor: colors,
                                borderRadius: 10,
                                borderOffset: 10,
                                spacing: 4,
                                hoverOffset: 15
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            cutout: '75%'
                        }
                    });

                    // Build Custom Legend
                    const legend = document.getElementById('categoryLegend');
                    legend.innerHTML = '';
                    data.labels.forEach((label, i) => {
                        const count = data.values[i];
                        const color = colors[i % colors.length];
                        const item = document.createElement('div');
                        item.className = 'flex items-center justify-between p-2 rounded-xl hover:bg-slate-50 transition-colors cursor-pointer';
                        item.innerHTML = `
                            <div class="flex items-center gap-3">
                                <div class="h-2 w-2 rounded-full" style="background-color: ${color}"></div>
                                <span class="text-sm font-semibold text-slate-700">${label}</span>
                            </div>
                            <span class="text-xs font-bold text-slate-500">${count}</span>
                        `;
                        legend.appendChild(item);
                    });
                });

            // Activity Feed Initialization
            fetch('api_helper.php?action=get_recent_activities')
                .then(r => r.json())
                .then(data => {
                    const feed = document.getElementById('activityFeed');
                    feed.innerHTML = '';
                    
                    if (data.length === 0) {
                        feed.innerHTML = '<p class="text-slate-400 text-sm text-center py-10 font-medium">Belum ada aktivitas baru.</p>';
                        return;
                    }

                    data.forEach(activity => {
                        const date = new Date(activity.timestamp);
                        const timeStr = date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
                        const dateStr = date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
                        
                        // Icon mapping
                        let icon = 'fa-dot-circle';
                        let color = 'bg-slate-100 text-slate-600';
                        if (activity.action.toLowerCase().includes('login')) { icon = 'fa-sign-in-alt'; color = 'bg-blue-100 text-blue-600'; }
                        if (activity.action.toLowerCase().includes('delete') || activity.action.toLowerCase().includes('hapus')) { icon = 'fa-trash'; color = 'bg-red-100 text-red-600'; }
                        if (activity.action.toLowerCase().includes('edit') || activity.action.toLowerCase().includes('update')) { icon = 'fa-pencil-alt'; color = 'bg-amber-100 text-amber-600'; }
                        if (activity.action.toLowerCase().includes('add') || activity.action.toLowerCase().includes('tambah')) { icon = 'fa-plus'; color = 'bg-emerald-100 text-emerald-600'; }

                        const item = document.createElement('div');
                        item.className = 'flex gap-4 group';
                        item.innerHTML = `
                            <div class="h-10 w-10 flex-shrink-0 rounded-full ${color} flex items-center justify-center shadow-inner group-hover:scale-110 transition-transform">
                                <i class="fas ${icon} text-sm"></i>
                            </div>
                            <div class="flex-grow pt-1">
                                <div class="flex justify-between items-start">
                                    <p class="text-sm font-bold text-slate-800">
                                        ${activity.username} <span class="font-medium text-slate-400 mx-1">•</span> <span class="text-xs uppercase tracking-wider opacity-70">${activity.action}</span>
                                    </p>
                                    <span class="text-[10px] font-bold text-slate-400 whitespace-nowrap">${dateStr}, ${timeStr}</span>
                                </div>
                                <p class="text-xs text-slate-500 mt-0.5 leading-relaxed">${activity.description}</p>
                            </div>
                        `;
                        feed.appendChild(item);
                    });
                });
        });
    </script>
</body>
</html>
