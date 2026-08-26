<?php
// admin_import_matpro_stocks.php
// Menggabungkan form import dan proses import stok Matpro ke dalam satu file.

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

// Inisialisasi pesan
$success_message = '';
$error_message = '';

// Ambil pesan dari sesi jika ada (dari proses sebelumnya atau redirect)
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// --- LOGIKA PEMROSESAN FORM SUBMISSION (POST REQUEST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['file_import'])) {
    $file = $_FILES['file_import']['tmp_name'];
    $imported_count = 0;
    
    // Mulai transaksi database
    $mysqli->begin_transaction();

    try {
        // Validasi file yang diupload
        if ($_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Kesalahan upload file: " . $_FILES['file_import']['error']);
        }
        if (!file_exists($file)) {
            throw new Exception("File tidak ditemukan di server sementara.");
        }

        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        
        // Iterasi mulai dari baris kedua (baris pertama adalah header)
        foreach ($sheet->getRowIterator(2) as $row) {
            $rowIndex = $row->getRowIndex();
            $project_name_excel = trim($sheet->getCell('A' . $rowIndex)->getValue());
            $type_name_excel = trim($sheet->getCell('B' . $rowIndex)->getValue());
            $branch_name_excel = trim($sheet->getCell('C' . $rowIndex)->getValue());
            $micro_cluster_name_excel = trim($sheet->getCell('D' . $rowIndex)->getValue()); // Opsional
            $stock_quantity_excel = (int)trim($sheet->getCell('E' . $rowIndex)->getValue());

            // Lewati baris kosong
            if (empty($project_name_excel) && empty($type_name_excel) && empty($branch_name_excel) && empty($micro_cluster_name_excel) && empty($stock_quantity_excel)) {
                continue;
            }

            // Validasi data yang wajib ada
            if (empty($project_name_excel) || empty($type_name_excel) || empty($branch_name_excel)) {
                throw new Exception("Error di baris " . $rowIndex . ": Kolom 'Nama Proyek', 'Nama Jenis Matpro', dan 'Nama Branch' wajib diisi.");
            }

            // Cari project_id dan brand proyek
            $stmt_project = $mysqli->prepare("SELECT id, brand FROM matpro_projects WHERE project_name = ? AND is_active = 1");
            if (!$stmt_project) { throw new Exception("Gagal menyiapkan statement cek proyek: " . $mysqli->error); }
            $stmt_project->bind_param("s", $project_name_excel);
            $stmt_project->execute();
            $res_project = $stmt_project->get_result();
            if ($res_project->num_rows === 0) { throw new Exception("Proyek '" . htmlspecialchars($project_name_excel) . "' tidak ditemukan atau tidak aktif."); }
            $project_data = $res_project->fetch_assoc();
            $project_id = $project_data['id'];
            $project_brand = $project_data['brand']; // Ambil brand proyek
            $stmt_project->close();

            // Cari type_id
            $stmt_type = $mysqli->prepare("SELECT id FROM matpro_types WHERE type_name = ? AND project_id = ? AND is_active = 1");
            if (!$stmt_type) { throw new Exception("Gagal menyiapkan statement cek jenis: " . $mysqli->error); }
            $stmt_type->bind_param("si", $type_name_excel, $project_id);
            $stmt_type->execute();
            $res_type = $stmt_type->get_result();
            if ($res_type->num_rows === 0) { throw new Exception("Jenis Matpro '" . htmlspecialchars($type_name_excel) . "' untuk proyek '" . htmlspecialchars($project_name_excel) . "' tidak ditemukan atau tidak aktif."); }
            $type_id = $res_type->fetch_assoc()['id'];
            $stmt_type->close();

            // Cari branch_id dan brand branch
            $stmt_branch = $mysqli->prepare("SELECT id, brand FROM branches WHERE nama_branch = ?");
            if (!$stmt_branch) { throw new Exception("Gagal menyiapkan statement cek branch: " . $mysqli->error); }
            $stmt_branch->bind_param("s", $branch_name_excel);
            $stmt_branch->execute();
            $res_branch = $stmt_branch->get_result();
            if ($res_branch->num_rows === 0) { throw new Exception("Branch '" . htmlspecialchars($branch_name_excel) . "' tidak ditemukan."); }
            $branch_data = $res_branch->fetch_assoc();
            $branch_id = $branch_data['id'];
            $branch_brand = $branch_data['brand']; // Ambil brand branch
            $stmt_branch->close();

            // START PERBAIKAN: Validasi konsistensi brand antara proyek dan branch
            if ($project_brand === 'IM3' || $project_brand === '3ID') {
                if ($branch_brand !== $project_brand && $branch_brand !== 'BOTH') {
                    throw new Exception("Error di baris " . $rowIndex . ": Brand Branch ('" . htmlspecialchars($branch_brand) . "') tidak cocok dengan Brand Proyek ('" . htmlspecialchars($project_brand) . "').");
                }
            } else if ($project_brand === 'BOTH') {
                // Jika proyek adalah BOTH, branch bisa IM3, 3ID, atau BOTH (semua valid)
                if (!in_array($branch_brand, ['IM3', '3ID', 'BOTH'])) {
                     throw new Exception("Error di baris " . $rowIndex . ": Brand Branch ('" . htmlspecialchars($branch_brand) . "') tidak valid untuk Proyek 'BOTH'.");
                }
            }
            // END PERBAIKAN

            // Cari micro_cluster_id (opsional)
            $micro_cluster_id = null;
            if (!empty($micro_cluster_name_excel)) {
                $stmt_mc = $mysqli->prepare("SELECT id FROM micro_clusters WHERE nama_micro_cluster = ? AND branch_id = ?");
                if (!$stmt_mc) { throw new Exception("Gagal menyiapkan statement cek micro cluster: " . $mysqli->error); }
                $stmt_mc->bind_param("si", $micro_cluster_name_excel, $branch_id);
                $stmt_mc->execute();
                $res_mc = $stmt_mc->get_result();
                if ($res_mc->num_rows === 0) { throw new Exception("Error di baris " . $rowIndex . ": Micro Cluster '" . htmlspecialchars($micro_cluster_name_excel) . "' tidak ditemukan di Branch '" . htmlspecialchars($branch_name_excel) . "'. Kosongkan kolom jika stok level Branch."); }
                $micro_cluster_id = $res_mc->fetch_assoc()['id'];
                $stmt_mc->close();
            }

            // Cek apakah stok sudah ada (UNIQUE constraint: project_id, type_id, branch_id, micro_cluster_id)
            $sql_check_existing = "SELECT id FROM matpro_stocks WHERE project_id = ? AND type_id = ? AND branch_id = ?";
            $check_types = "iii";
            $check_values = [$project_id, $type_id, $branch_id];

            if ($micro_cluster_id !== null) {
                $sql_check_existing .= " AND micro_cluster_id = ?";
                $check_types .= "i";
                $check_values[] = $micro_cluster_id;
            } else {
                $sql_check_existing .= " AND micro_cluster_id IS NULL";
            }
            
            $stmt_check_existing = $mysqli->prepare($sql_check_existing);
            if (!$stmt_check_existing) { throw new Exception("Gagal menyiapkan statement cek stok existing: " . $mysqli->error); }
            call_user_func_array([$stmt_check_existing, 'bind_param'], array_merge([$check_types], $check_values));
            $stmt_check_existing->execute();
            $result_existing = $stmt_check_existing->get_result();
            $existing_stock_id = null;
            if ($result_existing->num_rows > 0) {
                $existing_stock_id = $result_existing->fetch_assoc()['id'];
            }
            $stmt_check_existing->close();

            // Insert atau Update stok
            if ($existing_stock_id) {
                // Update stok yang sudah ada
                $sql_update = "UPDATE matpro_stocks SET stock_quantity = ?, last_updated_by = ?, last_updated_at = NOW() WHERE id = ?";
                $stmt_update = $mysqli->prepare($sql_update);
                if (!$stmt_update) { throw new Exception("Gagal menyiapkan statement update stok: " . $mysqli->error); }
                $stmt_update->bind_param("iii", $stock_quantity_excel, $_SESSION['id'], $existing_stock_id);
                $stmt_update->execute();
                $stmt_update->close();
            } else {
                // Insert stok baru
                $sql_insert = "INSERT INTO matpro_stocks (project_id, type_id, branch_id, micro_cluster_id, stock_quantity, last_updated_by) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt_insert = $mysqli->prepare($sql_insert);
                if (!$stmt_insert) { throw new Exception("Gagal menyiapkan statement insert stok: " . $mysqli->error); }
                
                $bind_types = "iiiiii"; // Default types
                $bind_params = [$project_id, $type_id, $branch_id, $micro_cluster_id, $stock_quantity_excel, $_SESSION['id']];

                // Jika micro_cluster_id adalah NULL, sesuaikan binding
                if ($micro_cluster_id === null) {
                    $sql_insert = "INSERT INTO matpro_stocks (project_id, type_id, branch_id, micro_cluster_id, stock_quantity, last_updated_by) VALUES (?, ?, ?, NULL, ?, ?)";
                    $bind_types = "iiii"; // project, type, branch, quantity, updated_by
                    $bind_params = [$project_id, $type_id, $branch_id, $stock_quantity_excel, $_SESSION['id']];
                }

                call_user_func_array([$stmt_insert, 'bind_param'], array_merge([$bind_types], $bind_params));
                $stmt_insert->execute();
                $stmt_insert->close();
            }
            $imported_count++;
        }

        // Commit transaksi jika semua berhasil
        $mysqli->commit();
        $_SESSION['success_message'] = "Sukses! " . $imported_count . " data stok Matpro berhasil diimpor/diperbarui.";

    } catch (Exception $e) {
        // Rollback transaksi jika ada error
        $mysqli->rollback();
        // Tangani error duplikat (UNIQUE constraint violation)
        if ($mysqli->errno == 1062) {
            $_SESSION['error_message'] = "Gagal! Terdapat kombinasi Proyek, Jenis, Branch, dan Micro Cluster (jika ada) duplikat di file Excel atau database pada baris " . ($rowIndex ?? 'N/A') . ".";
        } else {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
    
    // Redirect kembali ke halaman ini untuk menampilkan pesan dan membersihkan POST data
    header("location: admin_import_matpro_stocks.php");
    exit();
}
// --- AKHIR LOGIKA PEMROSESAN FORM SUBMISSION ---

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Import Stok Matpro - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Import Stok Matpro</h2>
                        <p class="text-slate-500 font-medium text-sm">Unggah data inventori matpro secara massal.</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a href="admin_manage_matpro_stocks.php" class="px-5 py-2.5 bg-white text-slate-600 font-bold text-xs rounded-xl border border-slate-200 hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </header>

            <div class="max-w-4xl mx-auto space-y-8">
                <!-- Status Messages -->
                <?php if (!empty($success_message)): ?>
                <div class="glass-card border-l-4 border-emerald-500 text-emerald-700 p-6 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                    <div class="h-10 w-10 rounded-full bg-emerald-100 flex items-center justify-center shrink-0">
                        <i class="fas fa-check"></i>
                    </div>
                    <p class="font-bold text-sm"><?php echo $success_message; ?></p>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                <div class="glass-card border-l-4 border-red-500 text-red-700 p-6 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                    <div class="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <p class="font-bold text-sm"><?php echo $error_message; ?></p>
                </div>
                <?php endif; ?>

                <!-- Instruction Card -->
                <div class="glass-card rounded-3xl p-8 border border-white/50 bg-indigo-50/30">
                    <div class="flex items-start gap-5">
                        <div class="h-12 w-12 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center shrink-0 shadow-sm">
                            <i class="fas fa-boxes text-xl"></i>
                        </div>
                        <div class="flex-grow">
                            <h3 class="text-lg font-bold text-slate-800 mb-4">Petunjuk Import Stok</h3>
                            <ul class="space-y-3 text-sm text-slate-600 font-medium">
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check-circle text-indigo-500 text-[10px] mt-1.5"></i>
                                    <span>Gunakan file Excel format <strong class="text-slate-800">.xlsx</strong>. Data mulai baris kedua.</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check-circle text-indigo-500 text-[10px] mt-1.5"></i>
                                    <span><strong class="text-slate-800">Nama Proyek, Jenis, dan Branch</strong> harus sudah terdaftar & aktif.</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-info-circle text-indigo-500 text-xs mt-1"></i>
                                    <span>Kombinasi yang sudah ada akan <strong class="text-indigo-600 italic">DIPERBARUI</strong> secara otomatis.</span>
                                </li>
                            </ul>
                            
                            <div class="mt-8 p-6 bg-white/50 rounded-2xl border border-indigo-100">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Format Kolom Excel (A-E):</p>
                                <div class="flex flex-wrap gap-2">
                                    <span class="px-3 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-[10px] font-bold ring-1 ring-indigo-200">A: Nama Proyek</span>
                                    <span class="px-3 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-[10px] font-bold ring-1 ring-indigo-200">B: Nama Jenis Matpro</span>
                                    <span class="px-3 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-[10px] font-bold ring-1 ring-indigo-200">C: Nama Branch</span>
                                    <span class="px-3 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-[10px] font-bold ring-1 ring-indigo-200">D: Micro Cluster (Opsional)</span>
                                    <span class="px-3 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-[10px] font-bold ring-1 ring-indigo-200">E: Jumlah Stok</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Card -->
                <div class="glass-card rounded-3xl p-10 border border-white/50 shadow-xl shadow-blue-900/5">
                    <form action="admin_import_matpro_stocks.php" method="POST" enctype="multipart/form-data" class="space-y-8">
                        <div class="space-y-4">
                            <label for="file_import" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Pilih File XLSX</label>
                            <div class="relative group">
                                <input type="file" name="file_import" id="file_import" required accept=".xlsx" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                                <div class="w-full p-12 border-2 border-dashed border-slate-200 rounded-3xl bg-slate-50/50 group-hover:bg-indigo-50/50 group-hover:border-indigo-200 transition-all flex flex-col items-center justify-center gap-4 text-center">
                                    <div class="h-20 w-20 bg-white border border-slate-100 rounded-2xl flex items-center justify-center text-slate-400 group-hover:text-indigo-500 group-hover:scale-110 group-hover:shadow-lg transition-all">
                                        <i class="fas fa-file-excel text-4xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-slate-700">Klik atau seret file stok ke sini</p>
                                        <p class="text-xs text-slate-400 mt-1">Sistem akan segera menvalidasi seluruh baris data.</p>
                                    </div>
                                    <div id="file-name" class="mt-2 text-xs font-black text-indigo-600 hidden"></div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col md:flex-row items-center justify-between gap-6 pt-6 border-t border-slate-100">
                             <a href="process/download_matpro_stock_template.php" class="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-2 group">
                                <i class="fas fa-download p-2 bg-blue-50 rounded-lg group-hover:bg-blue-100 transition-all"></i>
                                Unduh Template Excel
                            </a>
                            <button type="submit" class="w-full md:w-auto px-12 py-4 bg-indigo-600 text-white font-black text-sm rounded-2xl hover:bg-indigo-700 shadow-xl shadow-indigo-200 active:scale-95 transition-all flex items-center justify-center gap-3">
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
<?php
ob_end_flush();
?>
