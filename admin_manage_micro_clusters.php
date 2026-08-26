<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

$app_name = get_setting($mysqli, 'app_name');

// Query untuk mengambil semua data micro cluster beserta nama branch
$sql = "SELECT mc.id, mc.nama_micro_cluster, mc.brand, b.nama_branch 
        FROM micro_clusters mc
        JOIN branches b ON mc.branch_id = b.id
        ORDER BY b.nama_branch, mc.nama_micro_cluster";
$mc_result = $mysqli->query($sql);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Manajemen Micro Cluster - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Manajemen Micro Cluster</h2>
                        <p class="text-slate-500 font-medium text-sm">Kelola pembagian wilayah micro cluster berdasarkan branch.</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <a href="admin_import_micro_clusters.php" class="px-5 py-3 bg-white/50 backdrop-blur-md border border-slate-200 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-file-excel"></i> Import Bulk
                    </a>
                    <a href="admin_micro_cluster_form.php" class="px-5 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all flex items-center gap-2">
                        <i class="fas fa-plus"></i> Tambah Cluster
                    </a>
                </div>
            </header>

            <div class="max-w-5xl mx-auto">
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

                <div class="glass-card rounded-3xl border border-white/50 overflow-hidden shadow-xl shadow-blue-900/5">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50/50 border-b border-slate-100">
                                    <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Nama Micro Cluster</th>
                                    <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Parent Branch</th>
                                    <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Brand</th>
                                    <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if ($mc_result->num_rows > 0): ?>
                                    <?php while($mc = $mc_result->fetch_assoc()): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors group">
                                        <td class="py-4 px-6">
                                            <p class="font-bold text-slate-800"><?php echo htmlspecialchars($mc['nama_micro_cluster']); ?></p>
                                        </td>
                                        <td class="py-4 px-6 text-sm font-medium text-slate-600"><?php echo htmlspecialchars($mc['nama_branch']); ?></td>
                                        <td class="py-4 px-6 text-center">
                                            <span class="inline-block px-3 py-1 bg-blue-50 text-blue-600 rounded-full text-[10px] font-black tracking-widest"><?php echo htmlspecialchars($mc['brand']); ?></span>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="flex items-center justify-center gap-2">
                                                <a href="admin_micro_cluster_form.php?id=<?php echo $mc['id']; ?>" 
                                                   class="h-9 w-9 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 border border-amber-100 hover:bg-amber-600 hover:text-white transition-all"
                                                   title="Edit">
                                                    <i class="fas fa-pencil-alt text-sm"></i>
                                                </a>
                                                <form action="process/admin_micro_cluster_process.php" method="POST" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus micro cluster ini?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo $mc['id']; ?>">
                                                    <button type="submit" class="h-9 w-9 flex items-center justify-center rounded-xl bg-red-50 text-red-600 border border-red-100 hover:bg-red-600 hover:text-white transition-all">
                                                        <i class="fas fa-trash-alt text-sm"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="py-12 text-center text-slate-400 font-bold text-sm">Tidak ada data micro cluster.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
