<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: login.php");
    exit;
}

$app_name = get_setting($mysqli, 'app_name') ?: 'MarComm App';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Import Branch - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Import Branch</h2>
                        <p class="text-slate-500 font-medium text-sm">Unggah data branch marcomm secara massal melalui file Excel.</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a href="admin_manage_branches.php" class="px-5 py-2.5 bg-white text-slate-600 font-bold text-xs rounded-xl border border-slate-200 hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </header>

            <div class="max-w-3xl mx-auto">
                <!-- Info Section -->
                <div class="glass-card rounded-3xl p-8 mb-8 border border-white/50 bg-blue-50/30">
                    <div class="flex items-start gap-4">
                        <div class="h-10 w-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center shrink-0">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-slate-800 mb-2">Petunjuk Import</h3>
                            <ul class="space-y-2 text-sm text-slate-600 font-medium">
                                <li class="flex items-center gap-2"><i class="fas fa-check-circle text-blue-500 text-[10px]"></i> Gunakan file Excel dengan format <strong class="text-slate-800">.xlsx</strong>.</li>
                                <li class="flex items-center gap-2"><i class="fas fa-check-circle text-blue-500 text-[10px]"></i> Data harus dimulai dari <strong class="text-slate-800">baris kedua</strong> (baris pertama adalah header).</li>
                                <li class="flex items-center gap-2"><i class="fas fa-check-circle text-blue-500 text-[10px]"></i> Pastikan kolom <strong class="text-slate-800">nama_branch</strong> dan <strong class="text-slate-800">brand</strong> sudah terisi.</li>
                            </ul>
                            
                            <div class="mt-6 p-4 bg-white/50 rounded-2xl border border-blue-100">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Format Kolom Excel:</p>
                                <div class="flex flex-wrap gap-2">
                                    <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-lg text-xs font-bold">A: nama_branch</span>
                                    <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-lg text-xs font-bold">B: brand</span>
                                </div>
                                <p class="text-[10px] text-slate-400 mt-2 italic">* Contoh: BALI BARAT, IM3</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Section -->
                <div class="glass-card rounded-3xl p-10 border border-white/50 shadow-xl shadow-blue-900/5">
                    <form action="process/admin_import_branches_process.php" method="POST" enctype="multipart/form-data" class="space-y-8">
                        <div class="space-y-4">
                            <label for="file_import" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Pilih File XLSX</label>
                            <div class="relative group">
                                <input type="file" name="file_import" id="file_import" required accept=".xlsx" 
                                       class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                                <div class="w-full p-10 border-2 border-dashed border-slate-200 rounded-3xl bg-slate-50/50 group-hover:bg-blue-50/50 group-hover:border-blue-200 transition-all flex flex-col items-center justify-center gap-4 text-center">
                                    <div class="h-16 w-16 bg-white border border-slate-100 rounded-2xl flex items-center justify-center text-slate-400 group-hover:text-blue-500 group-hover:scale-110 group-hover:shadow-lg transition-all">
                                        <i class="fas fa-file-excel text-3xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-slate-700">Klik atau seret file ke sini</p>
                                        <p class="text-xs text-slate-400 mt-1">Hanya mendukung format Excel (.xlsx)</p>
                                    </div>
                                    <div id="file-name" class="mt-2 text-xs font-black text-blue-600 hidden"></div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col md:flex-row items-center justify-between gap-6 pt-6 border-t border-slate-100">
                             <a href="process/download_branch_template.php" class="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-2 group">
                                <i class="fas fa-download p-2 bg-blue-50 rounded-lg group-hover:bg-blue-100 transition-all"></i>
                                Unduh Template Excel
                            </a>
                            <button type="submit" class="w-full md:w-auto px-10 py-4 bg-blue-600 text-white font-black text-sm rounded-2xl hover:bg-blue-700 shadow-xl shadow-blue-200 active:scale-95 transition-all flex items-center justify-center gap-3">
                                <i class="fas fa-upload"></i> Mulai Import
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Update file name display
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
