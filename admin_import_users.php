<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

$app_name = get_setting($mysqli, 'app_name') ?: 'MarComm App';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Import User - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Import User</h2>
                        <p class="text-slate-500 font-medium text-sm">Kelola pendaftaran user baru secara massal melalui file Excel.</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a href="admin_manage_users.php" class="px-5 py-2.5 bg-white text-slate-600 font-bold text-xs rounded-xl border border-slate-200 hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </header>

            <div class="max-w-4xl mx-auto space-y-8">
                <!-- Instruction Card -->
                <div class="glass-card rounded-3xl p-8 border border-white/50 bg-emerald-50/30">
                    <div class="flex items-start gap-5">
                        <div class="h-12 w-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center shrink-0 shadow-sm">
                            <i class="fas fa-user-plus text-xl"></i>
                        </div>
                        <div class="flex-grow">
                            <h3 class="text-lg font-bold text-slate-800 mb-4">Petunjuk Penting!</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <ul class="space-y-3 text-sm text-slate-600 font-medium">
                                    <li class="flex items-start gap-2">
                                        <i class="fas fa-check-circle text-emerald-500 text-[10px] mt-1.5"></i>
                                        <span>Gunakan file Excel format <strong class="text-slate-800">.xlsx</strong>. Data mulai baris kedua.</span>
                                    </li>
                                    <li class="flex items-start gap-2">
                                        <i class="fas fa-check-circle text-emerald-500 text-[10px] mt-1.5"></i>
                                        <span><strong class="text-slate-800">nama_branch</strong> & <strong class="text-slate-800">nama_micro_clusters</strong> harus sudah terdaftar.</span>
                                    </li>
                                </ul>
                                <ul class="space-y-3 text-sm text-slate-600 font-medium">
                                    <li class="flex items-start gap-2">
                                        <i class="fas fa-check-circle text-emerald-500 text-[10px] mt-1.5"></i>
                                        <span>Pemisah micro cluster adalah <strong class="text-emerald-600 font-bold">koma (,)</strong>.</span>
                                    </li>
                                    <li class="flex items-start gap-2">
                                        <i class="fas fa-check-circle text-emerald-500 text-[10px] mt-1.5"></i>
                                        <span>Role yang sah: <strong class="text-slate-800">admin, user</strong>. Brand: <strong class="text-slate-800">IM3, 3ID, BOTH</strong>.</span>
                                    </li>
                                </ul>
                            </div>
                            
                            <div class="mt-8 p-6 bg-white/50 rounded-2xl border border-emerald-100">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Urutan Kolom Excel (A-G):</p>
                                <div class="flex flex-wrap gap-2">
                                    <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-[10px] font-bold ring-1 ring-emerald-200">A: nama</span>
                                    <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-[10px] font-bold ring-1 ring-emerald-200">B: username</span>
                                    <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-[10px] font-bold ring-1 ring-emerald-200">C: password</span>
                                    <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-[10px] font-bold ring-1 ring-emerald-200">D: role</span>
                                    <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-[10px] font-bold ring-1 ring-emerald-200">E: brand</span>
                                    <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-[10px] font-bold ring-1 ring-emerald-200">F: nama_branch</span>
                                    <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-[10px] font-bold ring-1 ring-emerald-200">G: nama_micro_clusters</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Card -->
                <div class="glass-card rounded-3xl p-10 border border-white/50 shadow-xl shadow-blue-900/5">
                    <form action="process/admin_import_users_process.php" method="POST" enctype="multipart/form-data" class="space-y-8">
                        <div class="space-y-4">
                            <label for="file_import" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Pilih File XLSX</label>
                            <div class="relative group">
                                <input type="file" name="file_import" id="file_import" required accept=".xlsx" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                                <div class="w-full p-12 border-2 border-dashed border-slate-200 rounded-3xl bg-slate-50/50 group-hover:bg-emerald-50/50 group-hover:border-emerald-200 transition-all flex flex-col items-center justify-center gap-4 text-center">
                                    <div class="h-20 w-20 bg-white border border-slate-100 rounded-2xl flex items-center justify-center text-slate-400 group-hover:text-emerald-500 group-hover:scale-110 group-hover:shadow-lg transition-all">
                                        <i class="fas fa-id-card-alt text-4xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-slate-700">Klik atau seret file user ke sini</p>
                                        <p class="text-xs text-slate-400 mt-1">Sistem akan segera memvalidasi & mendaftarkan user.</p>
                                    </div>
                                    <div id="file-name" class="mt-2 text-xs font-black text-emerald-600 hidden"></div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col md:flex-row items-center justify-end gap-6 pt-6 border-t border-slate-100">
                            <button type="submit" class="w-full md:w-auto px-12 py-4 bg-emerald-600 text-white font-black text-sm rounded-2xl hover:bg-emerald-700 shadow-xl shadow-emerald-200 active:scale-95 transition-all flex items-center justify-center gap-3">
                                <i class="fas fa-upload"></i> Mulai Proses Import
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.getElementById('file_import').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            const display = document.getElementById('file-name');
            if (fileName) {
                display.textContent = 'Terpilih: ' + fileName;
                display.classList.remove('hidden');
            } else {
                display.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
