<?php
// components/sidebar_user.php
$current_page = basename($_SERVER['PHP_SELF']);
$app_name = get_setting($mysqli, 'app_name');
$app_logo = get_setting($mysqli, 'app_logo');
?>
<aside id="sidebar" class="sidebar w-64 h-screen glass-card rounded-none border-r border-white/20 fixed top-0 left-0 lg:fixed -translate-x-full lg:translate-x-0 z-50 flex flex-col p-5 overflow-y-auto">
    <style>
        .nav-link {
            display: flex;
            items-center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem !important;
            border-radius: 0.75rem;
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .nav-link i {
            width: 1.25rem;
            text-align: center;
            font-size: 1rem;
        }
        .nav-link:hover {
            background-color: #f1f5f9;
            color: #1e293b;
        }
        .nav-link.active {
            background-color: #eff6ff;
            color: #2563eb;
        }
    </style>
    <!-- Logo & Brand -->
    <div class="flex items-center gap-3 mb-6 px-2">
        <?php if ($app_logo): ?>
            <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" class="h-8 w-8 rounded-xl shadow-lg ring-2 ring-white/50">
        <?php else: ?>
            <div class="h-8 w-8 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-lg shadow-blue-200">
                <i class="fas fa-rocket text-lg"></i>
            </div>
        <?php endif; ?>
        <div>
            <h1 class="text-lg font-extrabold text-slate-800 tracking-tight leading-none"><?php echo htmlspecialchars($app_name); ?></h1>
            <p class="text-[9px] font-bold text-blue-600 uppercase tracking-widest mt-0.5">FIELD AGENT</p>
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="flex-grow space-y-2">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-4 px-4">Menu Utama</p>
        
        <a href="dashboard_user.php" class="nav-link <?php echo $current_page == 'dashboard_user.php' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>

        <a href="input_form.php" class="nav-link <?php echo $current_page == 'input_form.php' ? 'active' : ''; ?>">
            <i class="fas fa-plus-circle"></i>
            <span>Input Event</span>
        </a>

        <a href="matpro_input_form.php" class="nav-link <?php echo $current_page == 'matpro_input_form.php' ? 'active' : ''; ?>">
            <i class="fas fa-box-open"></i>
            <span>Aktivitas Matpro</span>
        </a>

        <a href="bulk_import_event.php" class="nav-link <?php echo $current_page == 'bulk_import_event.php' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-plus"></i>
            <span>Bulk Import Event</span>
        </a>

        <a href="user_import_matpro.php" class="nav-link <?php echo $current_page == 'user_import_matpro.php' ? 'active' : ''; ?>">
            <i class="fas fa-boxes"></i>
            <span>Bulk Import Matpro</span>
        </a>

        <p class="text-[9px] font-black text-slate-400 uppercase tracking-[0.2em] mt-6 mb-3 px-4">Distribusi Marpro</p>

        <a href="user_marpro_receive.php" class="nav-link <?php echo $current_page == 'user_marpro_receive.php' ? 'active' : ''; ?>">
            <i class="fas fa-download"></i>
            <span>Terima Marpro (Branch)</span>
        </a>

        <a href="user_marpro_allocate.php" class="nav-link <?php echo $current_page == 'user_marpro_allocate.php' ? 'active' : ''; ?>">
            <i class="fas fa-share-nodes"></i>
            <span>Alokasi Marpro ke MC</span>
        </a>

        <a href="admin_map_view.php" class="nav-link <?php echo $current_page == 'admin_map_view.php' ? 'active' : ''; ?>">
            <i class="fas fa-map-marked-alt"></i>
            <span>Peta Aktivitas</span>
        </a>

        <p class="text-[9px] font-black text-slate-400 uppercase tracking-[0.2em] mt-6 mb-3 px-4">Data Wilayah</p>

        <a href="user_sites.php" class="nav-link <?php echo $current_page == 'user_sites.php' ? 'active' : ''; ?>">
            <i class="fas fa-map-marker-alt"></i>
            <span>Data Site</span>
        </a>

        <a href="user_outlets.php" class="nav-link <?php echo $current_page == 'user_outlets.php' ? 'active' : ''; ?>">
            <i class="fas fa-store"></i>
            <span>Data Outlet</span>
        </a>

        <p class="text-[9px] font-black text-slate-400 uppercase tracking-[0.2em] mt-6 mb-3 px-4">Laporan & Riwayat</p>

        <a href="user_events.php" class="nav-link <?php echo $current_page == 'user_events.php' ? 'active' : ''; ?>">
            <i class="fas fa-list-alt"></i>
            <span>Riwayat Event</span>
        </a>

        <a href="user_matpro_activities.php" class="nav-link <?php echo $current_page == 'user_matpro_activities.php' ? 'active' : ''; ?>">
            <i class="fas fa-history"></i>
            <span>Riwayat Matpro</span>
        </a>
        
        <a href="user_matpro_stocks.php" class="nav-link <?php echo $current_page == 'user_matpro_stocks.php' ? 'active' : ''; ?>">
            <i class="fas fa-warehouse"></i>
            <span>Stok Matpro Anda</span>
        </a>

        <a href="leaderboard.php" class="nav-link <?php echo $current_page == 'leaderboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-trophy"></i>
            <span>Leaderboard</span>
        </a>
    </nav>

    <!-- User Profile & Footer -->
    <div class="mt-auto pt-6 border-t border-slate-100 px-2">
        <div class="flex items-center gap-3 mb-6">
            <div class="h-10 w-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 font-bold border-2 border-white shadow-sm overflow-hidden">
                <i class="fas fa-user-circle text-2xl"></i>
            </div>
            <div class="flex-grow min-w-0">
                <p class="text-sm font-bold text-slate-800 truncate"><?php echo htmlspecialchars($_SESSION["username"]); ?></p>
                <p class="text-[10px] font-medium text-slate-500 uppercase tracking-wider">Field Agent</p>
            </div>
        </div>
        
        <a href="logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 hover:bg-red-50 transition-all font-bold text-sm">
            <i class="fas fa-sign-out-alt"></i>
            <span>Keluar Sesi</span>
        </a>
    </div>
</aside>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('-translate-x-full');
    if (overlay) overlay.classList.toggle('hidden');
}
</script>
