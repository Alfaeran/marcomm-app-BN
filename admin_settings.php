<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

// Ambil pengaturan saat ini
$app_name = get_setting($mysqli, 'app_name');
$app_logo = get_setting($mysqli, 'app_logo');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Pengaturan Aplikasi - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Pengaturan Sistem</h2>
                        <p class="text-slate-500 font-medium">Konfigurasi identitas aplikasi, branding logo, dan preferensi global.</p>
                    </div>
                </div>
            </header>

            <div class="max-w-4xl mx-auto">
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

                <div class="glass-card p-8 lg:p-12 mb-10">
                    <div class="flex items-center gap-3 mb-10">
                        <div class="h-10 w-4 bg-blue-600 rounded-full"></div>
                        <h3 class="text-xl font-extrabold text-slate-800 tracking-tight">Identitas & Branding</h3>
                    </div>

                    <form action="process/admin_settings_process.php" method="POST" enctype="multipart/form-data" class="space-y-10">
                        <div class="space-y-10">
                            <div class="space-y-4">
                                <label for="app_name" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Nama Aplikasi</label>
                                <div class="relative group">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                        <i class="fas fa-desktop"></i>
                                    </span>
                                    <input type="text" name="app_name" id="app_name" value="<?php echo htmlspecialchars($app_name); ?>" required 
                                           placeholder="Input nama aplikasi..."
                                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl pl-12 pr-4 py-4 text-lg font-bold text-slate-800 focus:ring-4 focus:ring-blue-100 outline-none transition-all placeholder:text-slate-400">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-10 items-start">
                                <div class="space-y-4">
                                    <label class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Logo Saat Ini</label>
                                    <div class="glass-card border-dashed bg-slate-50 p-8 flex items-center justify-center min-h-[160px] group overflow-hidden">
                                         <div class="absolute inset-0 bg-blue-500/5 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none"></div>
                                         <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo Aplikasi" class="max-h-24 relative z-10 transition-transform duration-500 group-hover:scale-110 drop-shadow-md">
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <label for="app_logo" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Ganti Logo Baru</label>
                                    <div class="relative">
                                        <input type="file" name="app_logo" id="app_logo" accept="image/png, image/jpeg, image/gif" 
                                               class="w-full text-sm text-slate-500 bg-slate-50 rounded-2xl border border-slate-200 cursor-pointer focus:outline-none file:mr-4 file:py-4 file:px-6 file:rounded-r-none file:rounded-l-2xl file:border-0 file:text-xs file:font-black file:uppercase file:tracking-widest file:bg-blue-600 file:text-white hover:file:bg-blue-700 transition-all">
                                    </div>
                                    <div class="flex items-start gap-2.5 p-4 bg-blue-50/50 rounded-2xl border border-blue-100 text-blue-700 text-xs font-medium">
                                        <i class="fas fa-info-circle mt-0.5 opacity-60"></i>
                                        <p>Format yang disarankan: <b>PNG Transparan</b>. Dimensi proporsional akan secara otomatis menyesuaikan tampilan header.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-8 border-t border-slate-100 mb-10">
                            <div class="flex items-center gap-3 mb-10">
                                <div class="h-10 w-4 bg-amber-500 rounded-full"></div>
                                <h3 class="text-xl font-extrabold text-slate-800 tracking-tight">Pembatasan Import Bulky (Event & Matpro)</h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-10 items-start">
                                <div class="space-y-4">
                                    <label for="bulk_import_status" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Status Fitur Bulky</label>
                                    <div class="flex items-center gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="bulk_import_status" value="1" <?php echo get_setting($mysqli, 'bulk_import_status') == '1' ? 'checked' : ''; ?> class="sr-only peer">
                                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-100 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                        <span class="text-sm font-bold text-slate-700">Aktifkan Fitur Import Massal</span>
                                    </div>
                                    <p class="text-[10px] text-slate-400 font-medium px-1">Jika dimatikan, user tidak bisa mengakses halaman import bulky sama sekali.</p>
                                </div>

                                <div class="space-y-4">
                                    <label class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Jadwal Operasional</label>
                                    <div class="flex items-center gap-3">
                                        <div class="flex-grow">
                                            <input type="time" name="bulk_import_start_time" value="<?php echo get_setting($mysqli, 'bulk_import_start_time') ?: '08:00'; ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold">
                                        </div>
                                        <span class="text-slate-400 font-black">S/D</span>
                                        <div class="flex-grow">
                                            <input type="time" name="bulk_import_end_time" value="<?php echo get_setting($mysqli, 'bulk_import_end_time') ?: '17:00'; ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold">
                                        </div>
                                    </div>
                                    <p class="text-[10px] text-slate-400 font-medium px-1">Tentukan jendela waktu di mana proses import diperbolehkan.</p>
                                </div>

                                <div class="md:col-span-2 space-y-4">
                                    <label class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Rentang Tanggal Diperbolehkan (Tiap Bulan)</label>
                                    <div class="flex items-center gap-4 bg-slate-50 p-6 rounded-2xl border border-slate-200">
                                        <div class="flex-grow space-y-2">
                                            <label class="text-[10px] font-black text-slate-400 uppercase px-1">Dari Tanggal</label>
                                            <input type="number" name="bulk_import_start_day" min="1" max="31" value="<?php echo get_setting($mysqli, 'bulk_import_start_day') ?: '1'; ?>" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 outline-none">
                                        </div>
                                        <div class="flex items-center justify-center pt-6">
                                            <div class="h-0.5 w-6 bg-slate-200 rounded-full"></div>
                                        </div>
                                        <div class="flex-grow space-y-2">
                                            <label class="text-[10px] font-black text-slate-400 uppercase px-1">Sampai Tanggal</label>
                                            <input type="number" name="bulk_import_end_day" min="1" max="31" value="<?php echo get_setting($mysqli, 'bulk_import_end_day') ?: '31'; ?>" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 outline-none">
                                        </div>
                                    </div>
                                    <p class="text-[10px] text-slate-400 font-medium px-1">Contoh: Isi <b>1</b> s/d <b>20</b> untuk mengizinkan import hanya dari tanggal 1 sampai 20 tiap bulannya.</p>
                                </div>
                            </div>
                        </div>

                        <div class="pt-8 border-t border-slate-100 flex justify-end">
                            <button type="submit" class="px-10 py-4 bg-blue-600 text-white font-black text-sm rounded-2xl hover:bg-blue-700 shadow-xl shadow-blue-200 active:scale-95 transition-all flex items-center gap-3">
                                <i class="fas fa-save shadow-sm"></i> Simpan Pengaturan Sistem
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script></script>
</body>
</html>
