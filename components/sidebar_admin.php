<?php
// components/sidebar_admin.php
$current_page = basename($_SERVER['PHP_SELF']);
$app_name = get_setting($mysqli, 'app_name') ?: 'MarComm App';
$app_logo = get_setting($mysqli, 'app_logo');
?>
<style>
    #adminSidebar .nav-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 0.75rem 1rem;
        border-radius: 12px;
        color: #64748b;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        font-weight: 600;
        font-size: 0.875rem;
        width: 100%;
        margin-bottom: 2px;
    }
    #adminSidebar .nav-link i {
        width: 20px;
        text-align: center;
        font-size: 1rem;
    }
    #adminSidebar .nav-link:hover {
        background-color: #f1f5f9;
        color: #0f172a;
        transform: translateX(4px);
    }
    #adminSidebar .nav-link.active {
        background-color: #4f46e5;
        color: white;
        box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3);
    }
    #adminSidebar .nav-link.active i {
        color: white;
    }
    .sidebar-section-title {
        text-transform: uppercase;
        letter-spacing: 0.1em;
        font-weight: 900;
        font-size: 10px;
        color: #94a3b8;
        padding: 1.5rem 1rem 0.5rem 1rem;
    }
</style>

<aside id="adminSidebar" class="sidebar w-72 h-screen glass-card rounded-none border-r border-slate-200 fixed top-0 left-0 lg:fixed -translate-x-full lg:translate-x-0 z-50 flex flex-col overflow-hidden transition-transform duration-300">
    <!-- Logo & Brand -->
    <div class="p-6 pb-2">
        <div class="flex items-center gap-3 mb-6">
            <?php if ($app_logo): ?>
                <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" class="h-10 w-10 rounded-xl shadow-lg ring-2 ring-white/50">
            <?php else: ?>
                <div class="h-10 w-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white shadow-lg shadow-indigo-200">
                    <i class="fas fa-layer-group"></i>
                </div>
            <?php endif; ?>
            <div>
                <h1 class="text-xl font-black text-slate-800 tracking-tight leading-none"><?php echo str_replace('Apps', '<span class="text-indigo-600">Apps</span>', $app_name); ?></h1>
                <div class="flex items-center gap-1.5 mt-1">
                    <span class="h-1 w-1 rounded-full bg-emerald-500 animate-pulse"></span>
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Admin Control</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Menu -->
    <div class="flex-grow overflow-y-auto custom-scrollbar px-4 pb-10">
        <nav class="flex flex-col space-y-1">
            <h3 class="sidebar-section-title pt-2">Core Overview</h3>
            <a href="dashboard_admin.php" class="nav-link <?php echo $current_page == 'dashboard_admin.php' ? 'active' : ''; ?>">
                <i class="fas fa-grid-2"></i> <span>Main Dashboard</span>
            </a>
            
            <h3 class="sidebar-section-title">Analysis & Insight</h3>
            <a href="admin_laporan_event.php" class="nav-link <?php echo $current_page == 'admin_laporan_event.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i> <span>Event Reports</span>
            </a>
            <a href="admin_laporan_matpro.php" class="nav-link <?php echo $current_page == 'admin_laporan_matpro.php' ? 'active' : ''; ?>">
                <i class="fas fa-box-archive"></i> <span>Matpro Analysis</span>
            </a>
            <a href="admin_laporan_marpro_receive.php" class="nav-link <?php echo $current_page == 'admin_laporan_marpro_receive.php' ? 'active' : ''; ?>">
                <i class="fas fa-download"></i> <span>Penerimaan Marpro</span>
            </a>
            <a href="admin_laporan_marpro_allocate.php" class="nav-link <?php echo $current_page == 'admin_laporan_marpro_allocate.php' ? 'active' : ''; ?>">
                <i class="fas fa-share-nodes"></i> <span>Alokasi Marpro</span>
            </a>
            <a href="admin_laporan_msisdn.php" class="nav-link <?php echo $current_page == 'admin_laporan_msisdn.php' ? 'active' : ''; ?>">
                <i class="fas fa-magnifying-glass"></i> <span>Cek MSISDN</span>
            </a>
            <a href="admin_visual_dashboard.php" class="nav-link <?php echo $current_page == 'admin_visual_dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-pie-chart"></i> <span>Visual Data</span>
            </a>
            <a href="admin_map_view.php" class="nav-link <?php echo $current_page == 'admin_map_view.php' ? 'active' : ''; ?>">
                <i class="fas fa-map-location-dot"></i> <span>Spatial Distribution</span>
            </a>
            
            <h3 class="sidebar-section-title">Asset Control</h3>
            <a href="admin_manage_branches.php" class="nav-link <?php echo $current_page == 'admin_manage_branches.php' ? 'active' : ''; ?>">
                <i class="fas fa-building-circle-check"></i> <span>Branch Control</span>
            </a>
            <a href="admin_manage_micro_clusters.php" class="nav-link <?php echo $current_page == 'admin_manage_micro_clusters.php' ? 'active' : ''; ?>">
                <i class="fas fa-network-wired"></i> <span>Micro Clusters</span>
            </a>
            <a href="admin_manage_sites.php" class="nav-link <?php echo $current_page == 'admin_manage_sites.php' ? 'active' : ''; ?>">
                <i class="fas fa-tower-broadcast"></i> <span>Site Management</span>
            </a>
            <a href="admin_manage_outlets.php" class="nav-link <?php echo $current_page == 'admin_manage_outlets.php' ? 'active' : ''; ?>">
                <i class="fas fa-shop"></i> <span>Outlet Registry</span>
            </a>
            <a href="admin_manage_matpro.php" class="nav-link <?php echo $current_page == 'admin_manage_matpro.php' ? 'active' : ''; ?>">
                <i class="fas fa-warehouse"></i> <span>Inventory Base</span>
            </a>

            <h3 class="sidebar-section-title">System & Security</h3>
            <a href="admin_manage_users.php" class="nav-link <?php echo $current_page == 'admin_manage_users.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-shield"></i> <span>User Access</span>
            </a>
            <a href="admin_manage_event_categories.php" class="nav-link <?php echo $current_page == 'admin_manage_event_categories.php' ? 'active' : ''; ?>">
                <i class="fas fa-tags"></i> <span>Categorization</span>
            </a>
            <a href="admin_settings.php" class="nav-link <?php echo $current_page == 'admin_settings.php' ? 'active' : ''; ?>">
                <i class="fas fa-gears"></i> <span>Platform Config</span>
            </a>
            <a href="admin_activity_log.php" class="nav-link <?php echo $current_page == 'admin_activity_log.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-shield"></i> <span>Activity Logs</span>
            </a>
        </nav>
    </div>

    <!-- Auth Footer -->
    <div class="p-4 border-t border-slate-100 bg-slate-50/50">
        <a href="logout.php" class="flex items-center justify-center gap-3 w-full py-3 rounded-xl text-slate-600 hover:text-red-600 hover:bg-red-50 transition-all font-bold text-xs uppercase tracking-widest group">
            <i class="fas fa-power-off group-hover:rotate-90 transition-transform"></i>
            <span>Terminate Session</span>
        </a>
    </div>
</aside>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.toggle('-translate-x-full');
    if (overlay) overlay.classList.toggle('hidden');
}
</script>
