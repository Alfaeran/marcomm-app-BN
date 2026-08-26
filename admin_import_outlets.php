<?php
// admin_import_outlets.php
// Menggabungkan form import dan proses import ke dalam satu file.

// Mengaktifkan output buffering untuk mencegah header error jika ada output sebelum header()
ob_start();

require_once 'config/database.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

// Inisialisasi pesan dan hasil proses
$success_message = '';
$error_message = '';
$failed_rows = [];
$skipped_existing_rows = []; // PERBAIKAN: Array baru untuk data yang sudah ada

// --- LOGIKA PEMROSESAN FORM SUBMISSION (POST REQUEST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['file_import'])) {
    $file = $_FILES['file_import']['tmp_name'];
    $imported_count = 0;
    $skipped_error_count = 0;
    $skipped_existing_count = 0;

    try {
        // Validasi file yang diupload
        if ($_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Kesalahan upload file: " . $_FILES['file_import']['error']);
        }
        if (!file_exists($file)) {
            throw new Exception("File tidak ditemukan di server sementara.");
        }

        // --- OPTIMISASI: Pre-fetch data untuk mengurangi query di dalam loop ---
        // 1. Ambil semua site ID yang ada dan map kan: 'SITE-CODE' => numeric_id
        $all_sites = [];
        $result_sites = $mysqli->query("SELECT id, site_id FROM sites WHERE site_id IS NOT NULL");
        if ($result_sites) {
            while ($row = $result_sites->fetch_assoc()) {
                $all_sites[$row['site_id']] = $row['id'];
            }
            $result_sites->free();
        }

        // 2. Ambil semua ID outlet yang sudah ada
        $existing_outlets = [];
        $result_outlets = $mysqli->query("SELECT id_outlet FROM outlets WHERE id_outlet IS NOT NULL");
        if ($result_outlets) {
            while ($row = $result_outlets->fetch_assoc()) {
                $existing_outlets[$row['id_outlet']] = true;
            }
            $result_outlets->free();
        }
        // --- AKHIR OPTIMISASI ---

        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        
        // Mulai transaksi untuk import massal
        $mysqli->begin_transaction();

        // Siapkan statement insert di luar loop untuk efisiensi
        $sql = "INSERT INTO outlets (id_outlet, nama_outlet, Id_Outlet_Nama_Outlet, site_id, brand) VALUES (?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("Gagal menyiapkan statement insert: " . $mysqli->error);
        }

        // Iterasi mulai dari baris kedua (baris pertama adalah header)
        foreach ($sheet->getRowIterator(2) as $row) {
            $rowIndex = $row->getRowIndex();
            
            $row_data = [
                'A' => trim($sheet->getCell('A' . $rowIndex)->getValue()), // id_outlet
                'B' => trim($sheet->getCell('B' . $rowIndex)->getValue()), // nama_outlet
                'C' => trim($sheet->getCell('C' . $rowIndex)->getValue()), // site_id_code
                'D' => trim($sheet->getCell('D' . $rowIndex)->getValue())  // brand
            ];

            try {
                $id_outlet = $row_data['A'];
                $nama_outlet = $row_data['B'];
                $site_id_code_excel = $row_data['C'];
                $brand = $row_data['D'];

                if (empty($id_outlet) && empty($nama_outlet) && empty($site_id_code_excel) && empty($brand)) {
                    continue; // Lewati baris kosong
                }

                // PERBAIKAN: Cek apakah outlet sudah ada, jika ya, lewati (skip)
                if (isset($existing_outlets[$id_outlet])) {
                    $skipped_existing_count++;
                    $skipped_existing_rows[] = [
                        'row_index' => $rowIndex,
                        'data' => $row_data,
                        'reason' => 'ID Outlet sudah ada di database.'
                    ];
                    continue;
                }

                if (empty($id_outlet) || empty($nama_outlet) || empty($site_id_code_excel) || empty($brand)) {
                    throw new Exception("Kolom A, B, C, dan D wajib diisi.");
                }
                if (!in_array($brand, ['IM3', '3ID', 'BOTH'])) {
                    throw new Exception("Brand tidak valid. Harus 'IM3', '3ID', atau 'BOTH'.");
                }
                if (!isset($all_sites[$site_id_code_excel])) {
                    throw new Exception("Site ID '" . htmlspecialchars($site_id_code_excel) . "' tidak ditemukan.");
                }

                $site_id_numeric = $all_sites[$site_id_code_excel];
                $id_outlet_nama_outlet_concat = $id_outlet . ' | ' . $nama_outlet;

                $stmt->bind_param("sssis", $id_outlet, $nama_outlet, $id_outlet_nama_outlet_concat, $site_id_numeric, $brand);

                if (!$stmt->execute()) {
                    throw new Exception("Gagal eksekusi: " . $stmt->error);
                }
                
                $imported_count++;

            } catch (Exception $e) {
                $skipped_error_count++;
                $failed_rows[] = [
                    'row_index' => $rowIndex,
                    'data' => $row_data,
                    'reason' => $e->getMessage()
                ];
                continue;
            }
        }
        
        $stmt->close();
        $mysqli->commit(); // Commit semua data yang berhasil jika tidak ada error fatal

        // Atur pesan hasil proses
        if ($imported_count > 0) $success_message = "Proses selesai. " . $imported_count . " data outlet baru berhasil diimpor.";
        if ($skipped_existing_count > 0) $success_message .= " " . $skipped_existing_count . " data dilewati karena sudah ada.";
        if ($skipped_error_count > 0) $error_message = $skipped_error_count . " data gagal diimpor karena error. Lihat detail di bawah.";
        if ($imported_count == 0 && $skipped_error_count == 0 && $skipped_existing_count == 0) $error_message = "Tidak ada data baru yang ditemukan atau diimpor dari file.";


    } catch (Exception $e) {
        $mysqli->rollback(); // Batalkan semua jika ada error fatal
        $error_message = "Error Fatal: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Import Outlet - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Import Outlet</h2>
                        <p class="text-slate-500 font-medium text-sm">Kelola import data outlet baru secara massal.</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a href="admin_manage_outlets.php" class="px-5 py-2.5 bg-white text-slate-600 font-bold text-xs rounded-xl border border-slate-200 hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </header>

            <div class="max-w-5xl mx-auto space-y-8">
                <!-- Status Messages -->
                <?php if (!empty($success_message)): ?>
                <div class="glass-card border-l-4 border-emerald-500 text-emerald-700 p-6 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                    <div class="h-10 w-10 rounded-full bg-emerald-100 flex items-center justify-center shrink-0">
                        <i class="fas fa-check"></i>
                    </div>
                    <div>
                        <p class="font-bold">Prses Berhasil</p>
                        <p class="text-xs font-medium opacity-80"><?php echo $success_message; ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                <div class="glass-card border-l-4 border-red-500 text-red-700 p-6 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                    <div class="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <p class="font-bold">Perhatian</p>
                        <p class="text-xs font-medium opacity-80"><?php echo $error_message; ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($_SERVER["REQUEST_METHOD"] != "POST"): ?>
                    <!-- Instruction Card -->
                    <div class="glass-card rounded-3xl p-8 border border-white/50 bg-blue-50/30">
                        <div class="flex items-start gap-4">
                            <div class="h-10 w-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center shrink-0">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <div class="flex-grow">
                                <h3 class="text-lg font-bold text-slate-800 mb-4">Petunjuk Penting!</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <ul class="space-y-2 text-sm text-slate-600 font-medium">
                                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-blue-500 text-[10px] mt-1"></i> Gunakan file Excel format <strong class="text-slate-800">.xlsx</strong>.</li>
                                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-blue-500 text-[10px] mt-1"></i> Site ID Code harus sudah terdaftar.</li>
                                    </ul>
                                    <ul class="space-y-2 text-sm text-slate-600 font-medium">
                                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-blue-500 text-[10px] mt-1"></i> Brand harus `IM3`, `3ID`, atau `BOTH`.</li>
                                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-blue-500 text-[10px] mt-1"></i> ID Outlet ganda akan dilewati (skip).</li>
                                    </ul>
                                </div>
                                
                                <div class="mt-8 p-6 bg-white/50 rounded-2xl border border-blue-100">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Format Kolom Excel:</p>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-xs font-bold ring-1 ring-blue-200">A: ID Outlet</span>
                                        <span class="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-xs font-bold ring-1 ring-blue-200">B: Nama Outlet</span>
                                        <span class="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-xs font-bold ring-1 ring-blue-200">C: Site ID Code</span>
                                        <span class="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-xs font-bold ring-1 ring-blue-200">D: Brand</span>
                                    </div>
                                    <p class="text-[10px] text-slate-400 mt-3 italic">* Contoh: O001, Outlet ABC, SITE-001, IM3</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Upload Card -->
                    <div class="glass-card rounded-3xl p-10 border border-white/50 shadow-xl shadow-blue-900/5">
                        <form action="admin_import_outlets.php" method="POST" enctype="multipart/form-data" class="space-y-8">
                            <div class="space-y-4">
                                <label for="file_import" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Pilih File Excel Baru</label>
                                <div class="relative group">
                                    <input type="file" name="file_import" id="file_import" required accept=".xlsx" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                                    <div class="w-full p-12 border-2 border-dashed border-slate-200 rounded-3xl bg-slate-50/50 group-hover:bg-blue-50/50 group-hover:border-blue-200 transition-all flex flex-col items-center justify-center gap-4 text-center">
                                        <div class="h-20 w-20 bg-white border border-slate-100 rounded-2xl flex items-center justify-center text-slate-400 group-hover:text-blue-500 group-hover:scale-110 group-hover:shadow-lg transition-all">
                                            <i class="fas fa-file-invoice text-4xl"></i>
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
                                <a href="process/download_outlet_template.php" class="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-2 group">
                                    <i class="fas fa-download p-2 bg-blue-50 rounded-lg group-hover:bg-blue-100 transition-all"></i>
                                    Unduh Template Excel
                                </a>
                                <button type="submit" class="w-full md:w-auto px-10 py-4 bg-blue-600 text-white font-black text-sm rounded-2xl hover:bg-blue-700 shadow-xl shadow-blue-200 active:scale-95 transition-all flex items-center justify-center gap-3">
                                    <i class="fas fa-upload"></i> Mulai Proses Import
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Results Tables -->
                <div class="space-y-10">
                    <?php if (!empty($skipped_existing_rows)): ?>
                        <div class="glass-card rounded-3xl border border-white/50 overflow-hidden shadow-xl shadow-amber-900/5">
                            <div class="bg-amber-50/50 p-6 border-b border-amber-100">
                                <h2 class="text-lg font-extrabold text-amber-800 tracking-tight flex items-center gap-2">
                                    <i class="fas fa-clone text-amber-400"></i>
                                    Data Dilewati (Sudah Ada)
                                    <span class="bg-amber-200 text-amber-800 text-[10px] font-black rounded-full h-5 px-2 flex items-center justify-center ml-auto"><?php echo count($skipped_existing_rows); ?></span>
                                </h2>
                            </div>
                            <div class="overflow-x-auto max-h-96">
                                <table class="w-full text-left">
                                    <thead class="bg-slate-50/50">
                                        <tr>
                                            <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center w-20">Baris</th>
                                            <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">ID Outlet</th>
                                            <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Nama Outlet</th>
                                            <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Alasan</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($skipped_existing_rows as $row): ?>
                                        <tr class="hover:bg-slate-50/30 transition-colors">
                                            <td class="py-3 px-6 text-center text-xs font-black text-slate-300"><?php echo $row['row_index']; ?></td>
                                            <td class="py-3 px-6 font-mono text-xs font-bold text-slate-800 tracking-tighter"><?php echo htmlspecialchars($row['data']['A']); ?></td>
                                            <td class="py-3 px-6 text-xs font-medium text-slate-600"><?php echo htmlspecialchars($row['data']['B']); ?></td>
                                            <td class="py-3 px-6 text-[10px] font-bold text-amber-600 italic"><?php echo htmlspecialchars($row['reason']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($failed_rows)): ?>
                        <div class="glass-card rounded-3xl border border-white/50 overflow-hidden shadow-xl shadow-red-900/5">
                            <div class="bg-red-50/50 p-6 border-b border-red-100">
                                <h2 class="text-lg font-extrabold text-red-800 tracking-tight flex items-center gap-2">
                                    <i class="fas fa-exclamation-circle text-red-400"></i>
                                    Data Gagal Diimpor (Error)
                                    <span class="bg-red-200 text-red-800 text-[10px] font-black rounded-full h-5 px-2 flex items-center justify-center ml-auto"><?php echo count($failed_rows); ?></span>
                                </h2>
                            </div>
                            <div class="overflow-x-auto max-h-96">
                                <table class="w-full text-left border-collapse">
                                    <thead class="bg-slate-50/50">
                                        <tr>
                                            <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center w-20">Baris</th>
                                            <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Detail Data</th>
                                            <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Alasan Kegagalan</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($failed_rows as $row): ?>
                                        <tr class="hover:bg-slate-50/30 transition-colors">
                                            <td class="py-4 px-6 text-center text-xs font-black text-slate-300"><?php echo $row['row_index']; ?></td>
                                            <td class="py-4 px-6">
                                                <div class="flex flex-col gap-1">
                                                    <div class="flex items-center gap-2">
                                                        <span class="px-2 py-0.5 bg-slate-100 text-slate-600 text-[10px] font-black rounded uppercase">ID: <?php echo htmlspecialchars($row['data']['A']); ?></span>
                                                        <span class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($row['data']['B']); ?></span>
                                                    </div>
                                                    <div class="text-[10px] font-medium text-slate-400">
                                                        Code: <span class="text-slate-600"><?php echo htmlspecialchars($row['data']['C']); ?></span> | Brand: <span class="text-slate-600"><?php echo htmlspecialchars($row['data']['D']); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-4 px-6 text-[10px] font-bold text-red-600 italic leading-relaxed"><?php echo htmlspecialchars($row['reason']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
                    <div class="flex justify-center pt-8">
                        <a href="admin_import_outlets.php" class="px-10 py-4 bg-slate-800 text-white font-black text-sm rounded-2xl hover:bg-slate-900 shadow-xl shadow-slate-200 active:scale-95 transition-all flex items-center gap-3">
                            <i class="fas fa-sync-alt"></i> Import Lagi
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        const fileInput = document.getElementById('file_import');
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                const fileName = e.target.files[0]?.name;
                const display = document.getElementById('file-name');
                if (fileName) {
                    display.textContent = 'Terpilih: ' + fileName;
                    display.classList.remove('hidden');
                } else {
                    display.classList.add('hidden');
                }
            });
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
?>
