<?php
// bulk_import_event.php - OVERHAULED VERSION (ROBUST & CLEAN)
require_once 'config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}

// Enforce Restrictions
$restriction = is_bulk_import_allowed($mysqli);
if (!$restriction['allowed']) {
    $_SESSION['import_status'] = ['type' => 'error', 'message' => $restriction['message']];
    header("location: dashboard_user.php");
    exit;
}

// Get Message from Session
$status = $_SESSION['import_status'] ?? null;
unset($_SESSION['import_status']);

$success_message = ($status && $status['type'] === 'success') ? $status['message'] : '';
$error_message = ($status && $status['type'] === 'error') ? $status['message'] : '';
$error_details = $status['error_details'] ?? null;
$msisdn_stats = $status['msisdn_stats'] ?? null;

// Jadwal Bulky
$start_time = get_setting($mysqli, 'bulk_import_start_time') ?: '00:00';
$end_time   = get_setting($mysqli, 'bulk_import_end_time') ?: '23:59';
$start_day  = (int)(get_setting($mysqli, 'bulk_import_start_day') ?: 1);
$end_day    = (int)(get_setting($mysqli, 'bulk_import_end_day') ?: 31);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Import Event Massal - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
    <style>
        /* Simplified Design - High Contrast & No Blur for performance */
        .simple-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }
        .step-number {
            width: 2.5rem;
            height: 2.5rem;
            background: #3b82f6;
            color: white;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.875rem;
        }
        .download-btn {
            display: block;
            width: 100%;
            padding: 0.75rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            color: #1e293b;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            text-align: center;
            transition: all 0.2s;
        }
        .download-btn:hover {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        /* Custom Progress Bar */
        .progress-bar-container {
            width: 100%;
            height: 0.75rem;
            background: #f1f5f9;
            border-radius: 1rem;
            overflow: hidden;
            margin: 1rem 0;
        }
        .progress-bar-fill {
            height: 100%;
            background: #2563eb;
            width: 0%;
            transition: width 0.3s ease;
        }
        /* Layout overrides to prevent any Aurora interference */
        .bg-blob { display: none !important; }
        body { background-color: #f8fafc !important; overflow-y: auto !important; }
    </style>
</head>
<body class="antialiased">
    <div class="flex">
        <!-- Sidebar -->
        <?php include 'components/sidebar_user.php'; ?>

        <main class="flex-grow p-5 lg:p-10 lg:ml-64">
            <!-- Header Section -->
            <div class="mb-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div>
                    <h1 class="text-3xl font-black text-slate-800 tracking-tight">Bulk Event Import</h1>
                    <p class="text-slate-500 font-medium">Unggah file ZIP berisi data Excel dan Foto Event.</p>
                </div>
                <a href="dashboard_user.php" class="px-5 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold text-sm rounded-xl hover:bg-slate-50 transition-all shadow-sm">
                    <i class="fas fa-arrow-left mr-2"></i> Dashboard
                </a>
            </div>

            <!-- MESSAGES -->
            <?php if ($success_message): ?>
                <div class="mb-8 p-6 bg-emerald-50 border border-emerald-200 rounded-2xl">
                    <div class="flex items-center gap-4">
                        <div class="h-12 w-12 bg-emerald-500 text-white rounded-xl flex items-center justify-center text-xl shadow-lg shadow-emerald-200">
                            <i class="fas fa-check"></i>
                        </div>
                        <div>
                            <h3 class="text-emerald-900 font-black">Berhasil!</h3>
                            <p class="text-emerald-700 text-sm font-medium"><?php echo htmlspecialchars($success_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-8 p-6 bg-red-50 border border-red-200 rounded-2xl">
                    <div class="flex items-center gap-4">
                        <div class="h-12 w-12 bg-red-500 text-white rounded-xl flex items-center justify-center text-xl shadow-lg shadow-red-200">
                            <i class="fas fa-times"></i>
                        </div>
                        <div>
                            <h3 class="text-red-900 font-black">Gagal Import</h3>
                            <p class="text-red-700 text-sm font-medium"><?php echo htmlspecialchars($error_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
                <!-- Left: Form -->
                <div class="xl:col-span-2 space-y-6">
                    <!-- Progress Section (Hidden by default) -->
                    <div id="uploadStatus" class="hidden simple-card p-8 mb-6 border-blue-200 bg-blue-50/30">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-black text-blue-800 uppercase tracking-widest" id="statusLabel">Mengunggah File...</span>
                            <span class="text-xl font-black text-blue-600" id="progressPct">0%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div id="progressBar" class="progress-bar-fill"></div>
                        </div>
                        <p class="text-xs font-bold text-slate-500" id="statusDetail">Memulai sinkronisasi dengan server...</p>
                    </div>

                    <div class="simple-card p-10">
                        <form id="importForm" action="process/bulk_import_event_process.php" method="POST" enctype="multipart/form-data">
                            <div class="mb-8 text-center">
                                <i class="fas fa-file-archive text-5xl text-blue-500 mb-4 opacity-20"></i>
                                <h2 class="text-xl font-black text-slate-800">Pilih File ZIP</h2>
                                <p class="text-sm text-slate-500">Maksimum file 500MB.</p>
                            </div>

                            <div class="space-y-6">
                                <div class="relative">
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 px-1">File Archive (ZIP)</label>
                                    <input type="file" name="archive_file" id="archive_file" required accept=".zip" 
                                           class="w-full px-4 py-4 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all cursor-pointer">
                                </div>

                                <div class="relative">
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 px-1">Password Excel (Opsional)</label>
                                    <input type="password" name="excel_password" id="excel_password" placeholder="Kosongkan jika tidak diproteksi"
                                           class="w-full px-4 py-4 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                                </div>

                                <div class="pt-4 flex gap-4">
                                    <button type="submit" id="submitBtn" class="flex-grow py-4 bg-blue-600 text-white font-black text-xs uppercase tracking-widest rounded-2xl hover:bg-blue-700 shadow-xl shadow-blue-100 active:scale-[0.98] transition-all">
                                        <i class="fas fa-cloud-upload-alt mr-2"></i> Mulai Import Sekarang
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Right: Guide -->
                <div class="space-y-6">
                    <div class="simple-card p-8 sticky top-5">
                        <h3 class="text-lg font-extrabold text-slate-800 mb-6 flex items-center gap-2">
                            <i class="fas fa-book-open text-blue-500"></i> Panduan Singkat
                        </h3>

                        <div class="space-y-8">
                            <div class="flex gap-4">
                                <div class="step-number">01</div>
                                <div>
                                    <h4 class="text-xs font-black text-slate-800 uppercase tracking-widest mb-3">Unduh Template</h4>
                                    <div class="space-y-2">
                                        <a href="process/download_bulk_import_example.php" download class="download-btn">
                                            <i class="fas fa-download mr-1"></i> Template Lengkap
                                        </a>
                                        <a href="process/download_msisdn_template.php" download class="download-btn">
                                            <i class="fas fa-file-excel mr-1"></i> MSISDN (.xlsx)
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div class="flex gap-4 border-t border-slate-100 pt-8">
                                <div class="step-number bg-slate-800">02</div>
                                <div>
                                    <h4 class="text-xs font-black text-slate-800 uppercase tracking-widest mb-2">Persiapan</h4>
                                    <p class="text-xs font-semibold text-slate-500 leading-relaxed">
                                        Isi data di <code class="bg-slate-100 px-1 rounded">data_event.xlsx</code>. Pastikan nama file foto di Excel sama dengan file foto di ZIP.
                                    </p>
                                </div>
                            </div>

                            <div class="flex gap-4 border-t border-slate-100 pt-8">
                                <div class="step-number bg-slate-800">03</div>
                                <div>
                                    <h4 class="text-xs font-black text-slate-800 uppercase tracking-widest mb-2">Eksekusi</h4>
                                    <p class="text-xs font-semibold text-slate-500 leading-relaxed">
                                        Zip semua file (Excel + Foto) dan unggah. Tunggu hingga proses validasi server selesai.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-10 p-5 bg-slate-900 rounded-2xl text-white">
                            <p class="text-[9px] font-black text-blue-400 uppercase tracking-widest mb-2">PENTING</p>
                            <p class="text-[10px] font-bold text-slate-400 leading-relaxed">Jangan tutup tab saat proses "Menunggu Server" agar data tersimpan sempurna.</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const form = document.getElementById('importForm');
        const submitBtn = document.getElementById('submitBtn');
        const uploadStatus = document.getElementById('uploadStatus');
        const progressBar = document.getElementById('progressBar');
        const progressPct = document.getElementById('progressPct');
        const statusDetail = document.getElementById('statusDetail');

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const files = document.getElementById('archive_file').files;
            if (files.length === 0) return;

            // Show UI progress
            uploadStatus.classList.remove('hidden');
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Mengirim Data...';

            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();

            // Track Upload Progress
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    progressBar.style.width = pct + '%';
                    progressPct.textContent = pct + '%';
                    
                    if (pct < 100) {
                        statusDetail.textContent = 'Mengunggah aset ke server...';
                    } else {
                        statusDetail.innerHTML = '<i class="fas fa-cog fa-spin mr-1"></i> Berhasil diunggah. Menunggu server memproses data... <br><span class="text-blue-600 text-[10px] mt-1 block">Biasanya memakan waktu 1-3 menit tergantung ukuran.</span>';
                    }
                }
            });

            // Handle Response
            xhr.onload = function() {
                if (xhr.status === 200) {
                    // Berhasil, reload untuk lihat pesan sukses dari session
                    window.location.reload();
                } else {
                    alert('Gagal: Server merespon dengan status ' + xhr.status);
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    submitBtn.innerHTML = '<i class="fas fa-cloud-upload-alt mr-2"></i> Mulai Import Sekarang';
                    uploadStatus.classList.add('hidden');
                }
            };

            xhr.onerror = function() {
                alert('Kesalahan Jaringan. Coba cek koneksi internet Anda.');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                uploadStatus.classList.add('hidden');
            };

            xhr.open('POST', form.action, true);
            // Default timeout to 15 minutes
            xhr.timeout = 900000;
            xhr.ontimeout = () => alert('Koneksi terputus (Timeout). Coba bagi file menjadi lebih kecil.');
            
            xhr.send(formData);
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Jadwal Operasional Import',
                    html: `Fitur Bulk Import Event beroperasi pada:<br><br>
                           <div class='bg-blue-50 p-4 rounded-xl border border-blue-100 text-sm'>
                           <b>Tanggal:</b> <?php echo $start_day; ?> s/d <?php echo $end_day; ?> tiap bulan<br>
                           <b>Jam:</b> <?php echo $start_time; ?> - <?php echo $end_time; ?> WIB
                           </div><br>
                           <span class='text-xs text-slate-500 font-medium'>Pastikan Anda mengunggah data sesuai dengan jadwal. Di luar jadwal fitur ini tidak dapat diakses.</span>`,
                    icon: 'info',
                    confirmButtonColor: '#3b82f6',
                    confirmButtonText: 'Mengerti',
                    customClass: {
                        popup: 'rounded-3xl',
                        title: 'text-xl font-black text-slate-800',
                        confirmButton: 'rounded-xl font-bold px-8'
                    }
                });
            }
        });
    </script>
</body>
</html>
