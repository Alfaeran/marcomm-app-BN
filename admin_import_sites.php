<?php
// admin_import_sites.php
ob_start();
require_once 'config/database.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// PERBAIKAN: Helper function untuk membuat lookup map agar kode lebih ringkas
function create_lookup_map($mysqli, $query, $key_column, $value_column) {
    $map = [];
    $result = $mysqli->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $map[trim(strtoupper($row[$key_column]))] = $row[$value_column];
        }
        $result->free();
    }
    return $map;
}

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['file_import'])) {
    $imported_count = 0;
    $updated_count = 0;
    
    $import_details = []; // Array untuk menyimpan detail setiap baris

    $ext = strtolower(pathinfo($_FILES['file_import']['name'], PATHINFO_EXTENSION));
    
    $mysqli->begin_transaction();
    try {
        if ($_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Kesalahan upload file: " . $_FILES['file_import']['error']);
        }

        if ($ext === 'sql') {
            // --- LOGIKA SMART SQL IMPORT (UPDATE DATA TANPA DROP) ---
            $sql_content = file_get_contents($_FILES['file_import']['tmp_name']);
            if ($sql_content === false) {
                throw new Exception("Gagal membaca file SQL.");
            }

            // 1. Ambil daftar kolom tabel 'sites' untuk membuat clause ON DUPLICATE KEY UPDATE
            $columns = [];
            $result = $mysqli->query("SHOW COLUMNS FROM sites");
            while ($row = $result->fetch_assoc()) {
                $col = $row['Field'];
                if ($col != 'id') { // Skip Primary Key (Auto Inc) dari update
                    $columns[] = "$col = VALUES($col)";
                }
            }
            $update_clause = " ON DUPLICATE KEY UPDATE " . implode(", ", $columns);

            // 2. Bersihkan SQL dari komentar
            $sql_content = preg_replace('/--.*$/m', '', $sql_content);
            $sql_content = preg_replace('/\/\*.*?\*\//s', '', $sql_content);

            // 3. Pecah query berdasarkan delimiter ;
            $queries = explode(';', $sql_content);
            $query_count = 0;

            foreach ($queries as $query) {
                $query = trim($query);
                if (empty($query)) continue;

                // 4. Filter Query: Hanya jalankan INSERT, abaikan CREATE/DROP/ALTER/LOCK
                if (preg_match('/^INSERT INTO/i', $query)) {
                    // Modifikasi INSERT menjadi INSERT ... ON DUPLICATE KEY UPDATE
                    // Hapus semicolon di akhir jika ada (karena explode menyisakan string)
                    $query = rtrim($query, ';');
                    $query .= $update_clause;

                    if (!$mysqli->query($query)) {
                        throw new Exception("Error Update Data: " . $mysqli->error);
                    }
                    $query_count++;
                } 
                // Abaikan CREATE TABLE, DROP TABLE, LOCK TABLES, dll agar tidak error & tidak hilang data
                elseif (preg_match('/^(CREATE|DROP|ALTER|TRUNCATE|LOCK|UNLOCK)/i', $query)) {
                    continue; 
                }
                // Jalankan query lain (misal SET) jika aman
                else {
                    $mysqli->query($query); 
                }
            }
            
            $mysqli->commit();
            $_SESSION['success_message'] = "Update Via SQL Berhasil! " . $query_count . " blok data diproses (Insert/Update). Tabel aman (tidak di-drop).";
            
        } elseif ($ext === 'xlsx') {
            // --- LOGIKA IMPORT EXCEL (YANG SUDAH ADA) ---
            $file = $_FILES['file_import']['tmp_name'];
            
            // --- Pre-fetch data untuk lookup (Brand-Aware) ---
            $branches_map = [];
            $res_b = $mysqli->query("SELECT id, nama_branch, brand FROM branches");
            while ($row = $res_b->fetch_assoc()) {
                $b_brand = strtoupper(trim($row['brand'] ?? 'IM3'));
                $b_name = strtoupper(trim($row['nama_branch']));
                $branches_map[$b_brand][$b_name] = $row['id'];
            }
            
            $micro_clusters_map = [];
            $res_mc = $mysqli->query("SELECT id, nama_micro_cluster, brand FROM micro_clusters");
            while ($row = $res_mc->fetch_assoc()) {
                $mc_brand = strtoupper(trim($row['brand'] ?? 'IM3'));
                $mc_name = strtoupper(trim($row['nama_micro_cluster']));
                $micro_clusters_map[$mc_brand][$mc_name] = $row['id'];
            }

            $existing_sites_map = create_lookup_map($mysqli, "SELECT id, site_id FROM sites", 'site_id', 'id');
            
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            
            // --- DYNAMIC HEADER MAPPING ---
            $headerRow = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', NULL, TRUE, FALSE)[0];
            $colMap = [];
            
            // Define keywords for mapping (case-insensitive)
            $keywords = [
                'site_id' => ['site id', 'site_id', 'id code'],
                'site_name' => ['site name', 'site_name', 'nama site'],
                'brand' => ['brand', 'operator'],
                'branch' => ['branch', 'nama branch', 'regional'],
                'mc' => ['micro cluster', 'nama micro cluster', 'cluster'],
                'area' => ['area'],
                'kabupaten' => ['kabupaten', 'kota'],
                'kecamatan' => ['kecamatan', 'district'],
                'region' => ['region', 'wilayah']
            ];

            foreach ($headerRow as $idx => $headerText) {
                if ($headerText === NULL) continue;
                $headerTextLower = strtolower(trim($headerText));
                foreach ($keywords as $key => $list) {
                    foreach ($list as $keyword) {
                        if (strpos($headerTextLower, $keyword) !== false) {
                            $colMap[$key] = $idx;
                            break 2; // Found mapping for this column
                        }
                    }
                }
            }

            // Validasi Minimal Header yang DIWAJIBKAN
            $required_headers = ['site_id', 'site_name', 'branch', 'mc'];
            $missing = [];
            foreach ($required_headers as $req) {
                if (!isset($colMap[$req])) $missing[] = $req;
            }
            if (!empty($missing)) {
                throw new Exception("Header Excel tidak lengkap! Kurang: " . implode(", ", $missing) . ". Pastikan baris pertama berisi nama kolom yang jelas.");
            }

            // Siapkan statement untuk INSERT dan UPDATE
            $sql_insert = "INSERT INTO sites (site_id, site_name, brand, micro_cluster_id, branch_id, kecamatan, kabupaten, area, region) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_insert = $mysqli->prepare($sql_insert);
            if (!$stmt_insert) throw new Exception("Gagal menyiapkan statement INSERT: " . $mysqli->error);

            $sql_update = "UPDATE sites SET site_name = ?, brand = ?, micro_cluster_id = ?, branch_id = ?, kecamatan = ?, kabupaten = ?, area = ?, region = ? WHERE site_id = ?";
            $stmt_update = $mysqli->prepare($sql_update);
            if (!$stmt_update) throw new Exception("Gagal menyiapkan statement UPDATE: " . $mysqli->error);

            foreach ($sheet->getRowIterator(2) as $row) {
                $rowIndex = $row->getRowIndex();
                
                try {
                    $rowData = $sheet->rangeToArray('A' . $rowIndex . ':' . $sheet->getHighestColumn() . $rowIndex, NULL, TRUE, FALSE)[0];
                    
                    // Ambil data berdasarkan map (dengan fallback string kosong)
                    $site_id_code = trim($rowData[$colMap['site_id']] ?? '');
                    $site_name    = trim($rowData[$colMap['site_name']] ?? '');
                    $brand_raw    = trim($rowData[$colMap['brand']] ?? 'IM3');
                    $branch_name  = trim($rowData[$colMap['branch']] ?? '');
                    $mc_name      = trim($rowData[$colMap['mc']] ?? '');
                    $area         = trim($rowData[$colMap['area']] ?? '');
                    $kabupaten    = trim($rowData[$colMap['kabupaten']] ?? '');
                    $kecamatan    = trim($rowData[$colMap['kecamatan']] ?? '');
                    $region       = trim($rowData[$colMap['region']] ?? '');

                    if (empty($site_id_code) && empty($site_name)) continue;

                    if (empty($site_id_code) || empty($site_name) || empty($mc_name) || empty($branch_name)) {
                        throw new Exception("Data wajib (ID, Nama, Branch, MC) tidak boleh kosong.");
                    }
                    
                    // Validasi Brand
                    $brand = strtoupper($brand_raw);
                    if (!in_array($brand, ['IM3', '3ID'])) {
                        // Coba infer dari site_id link
                        if (strpos(strtoupper($site_id_code), '_3ID') !== false) $brand = '3ID';
                        else $brand = 'IM3';
                    }

                    $mc_id = $micro_clusters_map[$brand][trim(strtoupper($mc_name))] ?? null;
                    $branch_id = $branches_map[$brand][trim(strtoupper($branch_name))] ?? null;

                    if ($mc_id === null) throw new Exception("Micro Cluster '" . htmlspecialchars($mc_name) . "' untuk brand $brand tidak terdaftar.");
                    if ($branch_id === null) throw new Exception("Branch '" . htmlspecialchars($branch_name) . "' untuk brand $brand tidak terdaftar.");
                    
                    if (isset($existing_sites_map[trim(strtoupper($site_id_code))])) {
                        $stmt_update->bind_param("sssiissss", $site_name, $brand, $mc_id, $branch_id, $kecamatan, $kabupaten, $area, $region, $site_id_code);
                        if (!$stmt_update->execute()) throw new Exception("Update error: " . $stmt_update->error);
                        $updated_count++;
                        $import_details[] = ['row' => $rowIndex, 'id' => $site_id_code, 'status' => 'UPDATED', 'msg' => "OK"];
                    } else {
                        $stmt_insert->bind_param("sssiissss", $site_id_code, $site_name, $brand, $mc_id, $branch_id, $kecamatan, $kabupaten, $area, $region);
                        if (!$stmt_insert->execute()) throw new Exception("Insert error: " . $stmt_insert->error);
                        $imported_count++;
                        $import_details[] = ['row' => $rowIndex, 'id' => $site_id_code, 'status' => 'INSERTED', 'msg' => "OK"];
                    }
                } catch (Exception $rowEx) {
                    $import_details[] = ['row' => $rowIndex, 'id' => ($site_id_code ?? '-'), 'status' => 'ERROR', 'msg' => $rowEx->getMessage()];
                }
            }
            
            $stmt_insert->close();
            $stmt_update->close();
            $mysqli->commit();
            $_SESSION['success_message'] = "Proses selesai! " . $imported_count . " baru, " . $updated_count . " update.";
            $_SESSION['import_details'] = $import_details;

        } else {
             throw new Exception("Format file tidak didukung! Gunakan .xlsx atau .sql");
        }

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['error_message'] = "Import Gagal: " . $e->getMessage();
    }
    
    header("location: admin_import_sites.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Import & Update Site - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Import & Update Site</h2>
                        <p class="text-slate-500 font-medium text-sm">Sinkronisasi data site secara massal melalui file Excel atau Restore SQL.</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a href="admin_manage_sites.php" class="px-5 py-2.5 bg-white text-slate-600 font-bold text-xs rounded-xl border border-slate-200 hover:bg-slate-50 transition-all flex items-center gap-2">
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

                <!-- Import Result Table (Only shows after import) -->
                <?php if (isset($_SESSION['import_details']) && !empty($_SESSION['import_details'])): ?>
                <div class="glass-card rounded-3xl p-6 border border-slate-200 shadow-xl overflow-hidden">
                    <h3 class="text-lg font-bold text-slate-800 mb-4 px-2">Laporan Hasil Import</h3>
                    <div class="overflow-x-auto max-h-96">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Baris</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Site ID</th>
                                    <th class="px-4 py-3 text-center text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-slate-100 text-sm">
                                <?php foreach ($_SESSION['import_details'] as $detail): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-2 font-medium text-slate-600"><?php echo $detail['row']; ?></td>
                                    <td class="px-4 py-2 font-bold text-slate-800"><?php echo htmlspecialchars($detail['id']); ?></td>
                                    <td class="px-4 py-2 text-center">
                                        <?php if ($detail['status'] === 'INSERTED'): ?>
                                            <span class="px-2 py-1 bg-emerald-100 text-emerald-700 rounded text-xs font-bold">BARU</span>
                                        <?php elseif ($detail['status'] === 'UPDATED'): ?>
                                            <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-bold">UPDATE</span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded text-xs font-bold"><?php echo htmlspecialchars($detail['status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-2 text-slate-600"><?php echo htmlspecialchars($detail['msg']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php unset($_SESSION['import_details']); // Hapus setelah ditampilkan ?>
                <?php endif; ?>

                <!-- Instruction Card -->
                <div class="glass-card rounded-3xl p-8 border border-white/50 bg-blue-50/30">
                    <div class="flex items-start gap-4">
                        <div class="h-10 w-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center shrink-0">
                            <i class="fas fa-lightbulb"></i>
                        </div>
                        <div class="flex-grow">
                            <h3 class="text-lg font-bold text-slate-800 mb-4">Petunjuk Penting!</h3>
                            <ul class="space-y-3 text-sm text-slate-600 font-medium">
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check-circle text-blue-500 text-[10px] mt-1.5"></i>
                                    <span>Gunakan file Excel format <strong class="text-slate-800">.xlsx</strong> atau file SQL <strong class="text-slate-800">.sql</strong> (Restore).</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check-circle text-blue-500 text-[10px] mt-1.5"></i>
                                    <span>Urutan kolom bebas, sistem akan membaca berdasarkan **Nama Header** di baris pertama.</span>
                                </li>
                                <li class="fas fa-sync-alt text-blue-500 text-xs mt-1.5"></i>
                                    <span>Jika <strong class="text-slate-800">Site ID</strong> sudah ada, data akan <strong class="text-blue-600 italic uppercase">DIPERBARUI</strong> secara otomatis.</span>
                                </li>
                            </ul>
                            
                            <div class="mt-8 p-6 bg-white/50 rounded-2xl border border-blue-100">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Header yang Dikenali (Baris 1):</p>
                                <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
                                    <span class="px-3 py-1 bg-white border border-slate-100 rounded-lg text-[10px] font-bold text-slate-700">Site ID</span>
                                    <span class="px-3 py-1 bg-white border border-slate-100 rounded-lg text-[10px] font-bold text-slate-700">Site Name</span>
                                    <span class="px-3 py-1 bg-white border border-slate-100 rounded-lg text-[10px] font-bold text-slate-700">Brand</span>
                                    <span class="px-3 py-1 bg-white border border-slate-100 rounded-lg text-[10px] font-bold text-slate-700">Nama Branch</span>
                                    <span class="px-3 py-1 bg-white border border-slate-100 rounded-lg text-[10px] font-bold text-slate-700">Micro Cluster</span>
                                    <span class="px-3 py-1 bg-white border border-slate-100 rounded-lg text-[10px] font-bold text-slate-400">Area</span>
                                    <span class="px-3 py-1 bg-white border border-slate-100 rounded-lg text-[10px] font-bold text-slate-400">Kabupaten</span>
                                    <span class="px-3 py-1 bg-white border border-slate-100 rounded-lg text-[10px] font-bold text-slate-400">Kecamatan</span>
                                    <span class="px-3 py-1 bg-white border border-slate-100 rounded-lg text-[10px] font-bold text-slate-400">Region</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upload Card -->
                <div class="glass-card rounded-3xl p-10 border border-white/50 shadow-xl shadow-blue-900/5">
                    <form action="admin_import_sites.php" method="POST" enctype="multipart/form-data" class="space-y-8">
                        <div class="space-y-4">
                            <label for="file_import" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Pilih File XLSX atau SQL</label>
                            <a href="process/download_site_template.php" class="text-xs font-bold text-blue-600 hover:text-blue-700 hover:underline float-right">
                                <i class="fas fa-download mr-1"></i> Download Template
                            </a>
                            <div class="relative group">
                                <input type="file" name="file_import" id="file_import" required accept=".xlsx, .sql" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                                <div class="w-full p-12 border-2 border-dashed border-slate-200 rounded-3xl bg-slate-50/50 group-hover:bg-blue-50/50 group-hover:border-blue-200 transition-all flex flex-col items-center justify-center gap-4 text-center">
                                    <div class="h-20 w-20 bg-white border border-slate-100 rounded-2xl flex items-center justify-center text-slate-400 group-hover:text-blue-500 group-hover:scale-110 group-hover:shadow-lg transition-all">
                                        <i class="fas fa-database text-4xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-slate-700">Klik atau seret file ke sini</p>
                                        <p class="text-xs text-slate-400 mt-1">Mendukung format <strong>.xlsx</strong> (Import) dan <strong>.sql</strong> (Restore)</p>
                                    </div>
                                    <div id="file-name" class="mt-2 text-xs font-black text-blue-600 hidden"></div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col md:flex-row items-center justify-end gap-6 pt-6 border-t border-slate-100">
                            <button type="submit" class="w-full md:w-auto px-12 py-4 bg-teal-600 text-white font-black text-sm rounded-2xl hover:bg-teal-700 shadow-xl shadow-teal-200 active:scale-95 transition-all flex items-center justify-center gap-3">
                                <i class="fas fa-rocket"></i> Mulai Proses
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
                display.textContent = 'Menyiapkan: ' + fileName;
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
