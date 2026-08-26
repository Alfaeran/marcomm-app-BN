<?php
// admin_manage_matpro_stocks.php
require_once 'config/database.php';

// --- Bagian Awal: Penanganan Aksi Khusus (Download & Import) ---
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    require_once 'vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Template Import Stok');
    $headers = ['Nama Proyek', 'Nama Jenis Matpro', 'Brand', 'Nama Branch', 'Nama Micro Cluster (Opsional)', 'Jumlah Stok'];
    $sheet->fromArray($headers, NULL, 'A1');
    foreach (range('A', 'F') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="template_import_stok.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);
$failed_rows = [];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['file_import'])) {
    require_once 'vendor/autoload.php';
    $file = $_FILES['file_import']['tmp_name'];
    $imported_count = 0;
    $updated_count = 0;
    
    try {
        if ($_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Kesalahan upload file: " . $_FILES['file_import']['error']);
        }
        
        $projects_map = [];
        $result_projects = $mysqli->query("SELECT id, project_name, brand FROM matpro_projects");
        while ($row = $result_projects->fetch_assoc()) { $projects_map[trim(strtoupper($row['project_name']))] = ['id' => $row['id'], 'brand' => $row['brand']]; }

        $types_map = [];
        $result_types = $mysqli->query("SELECT id, type_name, project_id FROM matpro_types");
        while ($row = $result_types->fetch_assoc()) { 
            $key = $row['project_id'] . '_' . trim(strtoupper($row['type_name']));
            $types_map[$key] = $row['id'];
        }

        $branches_map = [];
        $result_branches = $mysqli->query("SELECT id, nama_branch, brand FROM branches");
        while ($row = $result_branches->fetch_assoc()) { $branches_map[trim(strtoupper($row['nama_branch']))] = ['id' => $row['id'], 'brand' => $row['brand']]; }
        
        $mcs_map = [];
        $result_mcs = $mysqli->query("SELECT id, nama_micro_cluster FROM micro_clusters");
        while ($row = $result_mcs->fetch_assoc()) { $mcs_map[trim(strtoupper($row['nama_micro_cluster']))] = $row['id']; }

        $existing_stocks_map = [];
        $result_stocks = $mysqli->query("SELECT id, project_id, type_id, branch_id, micro_cluster_id FROM matpro_stocks");
        while ($row = $result_stocks->fetch_assoc()) {
            $key = $row['project_id'] . '_' . $row['type_id'] . '_' . $row['branch_id'] . '_' . ($row['micro_cluster_id'] ?? '0');
            $existing_stocks_map[$key] = $row['id'];
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        
        $rows_to_insert = [];
        $rows_to_update = [];

        foreach ($sheet->getRowIterator(2) as $row) {
            $rowIndex = $row->getRowIndex();
            $rowData = $sheet->rangeToArray('A' . $rowIndex . ':F' . $rowIndex, NULL, TRUE, FALSE)[0];
            
            try {
                list($project_name, $type_name, $brand, $branch_name, $mc_name, $quantity) = array_map('trim', $rowData);

                if (empty($project_name) && empty($type_name)) continue;

                if (empty($project_name) || empty($type_name) || empty($brand) || empty($branch_name) || !is_numeric($quantity)) {
                    throw new Exception("Kolom Proyek, Jenis, Brand, Branch, dan Jumlah Stok (angka) wajib diisi.");
                }
                
                $project_data = $projects_map[trim(strtoupper($project_name))] ?? null;
                if ($project_data === null) throw new Exception("Nama Proyek tidak ditemukan.");
                $project_id = $project_data['id'];

                if (strcasecmp($project_data['brand'], $brand) != 0 && $project_data['brand'] !== 'BOTH') {
                    throw new Exception("Brand Proyek ('".$project_data['brand']."') tidak cocok dengan Brand di Excel ('".$brand."').");
                }

                $type_key = $project_id . '_' . trim(strtoupper($type_name));
                $type_id = $types_map[$type_key] ?? null;
                if ($type_id === null) throw new Exception("Nama Jenis tidak cocok dengan Proyek.");
                
                $branch_data = $branches_map[trim(strtoupper($branch_name))] ?? null;
                if ($branch_data === null) throw new Exception("Nama Branch tidak ditemukan.");
                if (strcasecmp($branch_data['brand'], $brand) != 0 && $branch_data['brand'] !== 'BOTH') {
                     throw new Exception("Brand Branch ('".$branch_data['brand']."') tidak cocok dengan Brand di Excel ('".$brand."').");
                }
                $branch_id = $branch_data['id'];

                $mc_id = null;
                if (!empty($mc_name)) {
                    $mc_id = $mcs_map[trim(strtoupper($mc_name))] ?? null;
                    if ($mc_id === null) throw new Exception("Nama Micro Cluster tidak ditemukan.");
                }

                $stock_key = $project_id . '_' . $type_id . '_' . $branch_id . '_' . ($mc_id ?? '0');
                
                $data_to_process = [
                    'project_id' => $project_id, 'type_id' => $type_id, 'branch_id' => $branch_id,
                    'micro_cluster_id' => $mc_id, 'stock_quantity' => (int)$quantity
                ];

                if (isset($existing_stocks_map[$stock_key])) {
                    $data_to_process['id'] = $existing_stocks_map[$stock_key];
                    $rows_to_update[] = $data_to_process;
                } else {
                    $rows_to_insert[] = $data_to_process;
                }

            } catch (Exception $e) {
                $failed_rows[] = ['row' => $rowIndex, 'data' => $rowData, 'reason' => $e->getMessage()];
            }
        }

        if (!empty($rows_to_insert) || !empty($rows_to_update)) {
            $mysqli->begin_transaction();
            
            if (!empty($rows_to_insert)) {
                $sql_insert = "INSERT INTO matpro_stocks (project_id, type_id, branch_id, micro_cluster_id, stock_quantity, last_updated_by) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt_insert = $mysqli->prepare($sql_insert);
                foreach ($rows_to_insert as $data) {
                    $stmt_insert->bind_param("iiiiii", $data['project_id'], $data['type_id'], $data['branch_id'], $data['micro_cluster_id'], $data['stock_quantity'], $_SESSION['id']);
                    $stmt_insert->execute();
                    if ($stmt_insert->affected_rows > 0) $imported_count++;
                }
                $stmt_insert->close();
            }

            if (!empty($rows_to_update)) {
                $sql_update = "UPDATE matpro_stocks SET stock_quantity = stock_quantity + ?, last_updated_by = ? WHERE id = ?";
                $stmt_update = $mysqli->prepare($sql_update);
                foreach ($rows_to_update as $data) {
                    $stmt_update->bind_param("iii", $data['stock_quantity'], $_SESSION['id'], $data['id']);
                    $stmt_update->execute();
                    if ($stmt_update->affected_rows > 0) $updated_count++;
                }
                $stmt_update->close();
            }
            $mysqli->commit();
        }
        
        if ($imported_count > 0 || $updated_count > 0) {
            $success_message = "Proses selesai! " . $imported_count . " stok baru diimpor dan " . $updated_count . " stok diperbarui.";
        }
        if (!empty($failed_rows)) {
            $error_message = count($failed_rows) . " data gagal diproses. Lihat detail di bawah.";
        }
        if (empty($success_message) && empty($error_message)) {
            $error_message = "Tidak ada data yang diproses dari file.";
        }

    } catch (Exception $e) {
        $in_transaction = property_exists($mysqli, 'in_transaction') ? $mysqli->in_transaction : (count($mysqli->error_list) > 0);
        if ($in_transaction) $mysqli->rollback();
        $error_message = "Error fatal: " . $e->getMessage();
    }
}


// --- Logika untuk Menampilkan Halaman ---
$records_per_page = 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$where_clauses = [];
$param_types = "";
$param_values = [];
$filter_query_string = http_build_query(array_filter($_GET, fn($key) => $key !== 'page', ARRAY_FILTER_USE_KEY));

$project_filter = $_GET['project_id'] ?? '';
$brand_filter = $_GET['brand'] ?? '';
$branch_filter = $_GET['branch_id'] ?? '';
$mc_filter = $_GET['mc_id'] ?? '';

if (!empty($project_filter)) { $where_clauses[] = "ms.project_id = ?"; $param_types .= "i"; $param_values[] = (int)$project_filter; }
if (!empty($brand_filter)) { $where_clauses[] = "p.brand = ?"; $param_types .= "s"; $param_values[] = $brand_filter; }
if (!empty($branch_filter)) { $where_clauses[] = "ms.branch_id = ?"; $param_types .= "i"; $param_values[] = (int)$branch_filter; }
if (!empty($mc_filter)) { $where_clauses[] = "ms.micro_cluster_id = ?"; $param_types .= "i"; $param_values[] = (int)$mc_filter; }

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

$count_sql = "SELECT COUNT(ms.id) as total FROM matpro_stocks ms JOIN matpro_projects p ON ms.project_id = p.id JOIN branches b ON ms.branch_id = b.id" . $where_sql;
$stmt_count = $mysqli->prepare($count_sql);
if ($stmt_count) {
    if (!empty($param_values)) $stmt_count->bind_param($param_types, ...$param_values);
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
} else {
    $total_records = 0;
    $error_message = "Gagal menghitung total stok: " . $mysqli->error;
}
$total_pages = ceil($total_records / $records_per_page);

$sql = "SELECT ms.id, ms.stock_quantity, p.project_name, p.brand, t.type_name, b.nama_branch, mc.nama_micro_cluster
        FROM matpro_stocks ms
        JOIN matpro_projects p ON ms.project_id = p.id
        JOIN matpro_types t ON ms.type_id = t.id
        JOIN branches b ON ms.branch_id = b.id
        LEFT JOIN micro_clusters mc ON ms.micro_cluster_id = mc.id"
        . $where_sql . " ORDER BY p.project_name, t.type_name, b.nama_branch, mc.nama_micro_cluster ASC LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    if (!empty($param_values_page)) $stmt->bind_param($param_types_page, ...$param_values_page);
    $stmt->execute();
    $stocks_result = $stmt->get_result();
    $stmt->close();
} else {
    $stocks_result = false;
    $error_message = "Gagal mengambil data stok: " . $mysqli->error;
}

$app_name = get_setting($mysqli, 'app_name');

$projects_for_filter = $mysqli->query("SELECT id, project_name FROM matpro_projects WHERE is_active = 1 ORDER BY project_name");
$brands_for_filter = $mysqli->query("SELECT DISTINCT brand FROM branches WHERE brand IS NOT NULL AND brand != 'BOTH' ORDER BY brand");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Manajemen Stok Matpro - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Stok Matpro</h2>
                        <p class="text-slate-500 font-medium text-sm">Monitor dan kelola ketersediaan material promosi.</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <button type="button" id="openImportModalBtn" class="px-5 py-3 bg-white/50 backdrop-blur-md border border-slate-200 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-file-import"></i> Import Massal
                    </button>
                    <button type="button" id="openAddModalBtn" class="px-5 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all flex items-center gap-2">
                        <i class="fas fa-plus"></i> Tambah Stok
                    </button>
                </div>
            </header>

            <div class="max-w-7xl mx-auto space-y-8">
                <?php if (!empty($success_message)): ?>
                    <div class="glass-card bg-emerald-50/50 border-emerald-200 p-4 mb-4 flex items-center gap-3">
                        <div class="h-10 w-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle"></i></div>
                        <p class="text-emerald-800 font-bold"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="glass-card bg-red-50/50 border-red-200 p-4 mb-4 flex items-center gap-3">
                        <div class="h-10 w-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center"><i class="fas fa-exclamation-triangle"></i></div>
                        <p class="text-red-800 font-bold"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($failed_rows)): ?>
                    <div class="glass-card border-red-200 overflow-hidden">
                        <div class="bg-red-50 px-6 py-4 border-b border-red-100">
                            <h3 class="text-sm font-black text-red-800 uppercase tracking-widest">Detail Kegagalan Import</h3>
                        </div>
                        <div class="overflow-x-auto max-h-60">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-red-50/50 sticky top-0">
                                    <tr>
                                        <th class="py-3 px-4 font-bold text-red-600 w-16">Baris</th>
                                        <th class="py-3 px-4 font-bold text-red-600">Data Baris</th>
                                        <th class="py-3 px-4 font-bold text-red-600">Alasan</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-red-50">
                                    <?php foreach ($failed_rows as $failed): ?>
                                    <tr>
                                        <td class="py-3 px-4 font-bold"><?php echo $failed['row']; ?></td>
                                        <td class="py-3 px-4 font-mono text-[10px]"><?php echo htmlspecialchars(implode(', ', $failed['data'])); ?></td>
                                        <td class="py-3 px-4 text-red-600"><?php echo htmlspecialchars($failed['reason']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Filter Card -->
                <div class="glass-card p-6 border border-white/50 shadow-xl shadow-blue-900/5">
                    <form action="admin_manage_matpro_stocks.php" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-6 items-end">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Proyek</label>
                            <select name="project_id" id="project_filter" class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none">
                                <option value="">Semua Proyek</option>
                                <?php if ($projects_for_filter) { mysqli_data_seek($projects_for_filter, 0); while($p = $projects_for_filter->fetch_assoc()): ?>
                                <option value="<?php echo $p['id']; ?>" <?php if ($project_filter == $p['id']) echo 'selected'; ?>><?php echo htmlspecialchars($p['project_name']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Brand</label>
                            <select name="brand" id="brand_filter" class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none">
                                <option value="">Semua Brand</option>
                                <?php if ($brands_for_filter) { mysqli_data_seek($brands_for_filter, 0); while($b = $brands_for_filter->fetch_assoc()): ?>
                                <option value="<?php echo $b['brand']; ?>" <?php if ($brand_filter == $b['brand']) echo 'selected'; ?>><?php echo htmlspecialchars($b['brand']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Branch</label>
                            <select name="branch_id" id="branch_filter" class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none disabled:opacity-50" disabled>
                                <option value="">Pilih Brand dulu</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Micro Cluster</label>
                            <select name="mc_id" id="mc_filter" class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none disabled:opacity-50" disabled>
                                <option value="">Pilih Branch dulu</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="flex-grow bg-slate-800 text-white font-bold py-3 px-4 rounded-2xl hover:bg-slate-900 transition-all text-sm shadow-lg shadow-slate-200 flex items-center justify-center gap-2">
                                <i class="fas fa-filter"></i> Terapkan
                            </button>
                            <a href="admin_manage_matpro_stocks.php" class="bg-white border border-slate-200 text-slate-600 font-bold py-3 px-6 rounded-2xl hover:bg-slate-50 transition-all text-sm flex items-center justify-center">
                                <i class="fas fa-undo"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Table Section -->
                <form id="mainForm" action="process/admin_matpro_stock_process.php" method="POST">
                    <input type="hidden" name="action" id="main_action" value="bulk_delete">
                    
                    <div class="flex flex-col md:flex-row justify-between items-center mb-6 px-1 gap-4">
                        <div class="flex items-center gap-4 text-sm font-medium text-slate-500 bg-white/50 backdrop-blur-md rounded-2xl p-2 border border-white/50 shadow-sm">
                            <span class="px-4">Menampilkan <strong class="text-slate-800"><?php echo count($stocks_result ? $stocks_result->fetch_all(MYSQLI_ASSOC) : []); mysqli_data_seek($stocks_result, 0); ?></strong> dari <strong class="text-slate-800"><?php echo $total_records; ?></strong> total data</span>
                        </div>

                        <button type="submit" id="bulkDeleteBtn" disabled 
                                class="bg-red-50 text-red-600 border border-red-100 font-black text-[10px] uppercase tracking-widest px-6 py-4 rounded-2xl hover:bg-red-600 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-all shadow-sm"
                                onclick="return confirm('Anda yakin ingin menghapus stok yang dipilih? Aksi ini tidak bisa dibatalkan.');">
                            <i class="fas fa-trash-alt mr-2"></i> Hapus Terpilih
                        </button>
                    </div>

                    <div class="glass-card rounded-3xl border border-white/50 overflow-hidden shadow-xl shadow-blue-900/5">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50/50 border-b border-slate-100">
                                        <th class="py-5 px-6 w-12 text-center">
                                            <input type="checkbox" id="selectAll" class="w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 transition-all">
                                        </th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Matpro & Proyek</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Branch / MC</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Stok</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white/30 backdrop-blur-sm">
                                     <?php if ($stocks_result && $stocks_result->num_rows > 0): ?>
                                        <?php while($stock = $stocks_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors group">
                                            <td class="py-5 px-6 text-center">
                                                <input type="checkbox" name="stock_ids[]" value="<?php echo $stock['id']; ?>" class="stock-checkbox w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 transition-all">
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="space-y-1">
                                                    <p class="font-bold text-slate-800"><?php echo htmlspecialchars($stock['type_name']); ?></p>
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-tighter"><?php echo htmlspecialchars($stock['project_name']); ?></span>
                                                        <span class="h-1 w-1 bg-slate-300 rounded-full"></span>
                                                        <span class="text-[10px] font-bold text-blue-500"><?php echo htmlspecialchars($stock['brand']); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="space-y-1">
                                                    <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($stock['nama_branch']); ?></p>
                                                    <?php if($stock['nama_micro_cluster']): ?>
                                                        <p class="text-[10px] font-medium text-slate-500 italic"><?php echo htmlspecialchars($stock['nama_micro_cluster']); ?></p>
                                                    <?php else: ?>
                                                        <span class="text-[9px] font-black text-slate-300 uppercase tracking-tight">Main Branch</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="py-5 px-6 text-center">
                                                <span class="text-sm font-black text-slate-800"><?php echo number_format($stock['stock_quantity']); ?></span>
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="flex items-center justify-center gap-2">
                                                    <button type="button" onclick="openEditModal(<?php echo $stock['id']; ?>)" 
                                                            class="h-9 w-9 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 border border-amber-100 hover:bg-amber-600 hover:text-white transition-all shadow-sm"
                                                            title="Edit">
                                                        <i class="fas fa-pencil-alt text-sm"></i>
                                                    </button>
                                                    <button type="button" onclick="deleteSingle(<?php echo $stock['id']; ?>)" 
                                                            class="h-9 w-9 flex items-center justify-center rounded-xl bg-red-50 text-red-600 border border-red-100 hover:bg-red-600 hover:text-white transition-all shadow-sm"
                                                            title="Hapus">
                                                        <i class="fas fa-trash-alt text-sm"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="py-12 text-center text-slate-400 font-bold text-sm">Tidak ada data stok.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </form>
            
                <!-- Paginasi -->
                <?php if($total_pages > 1): ?>
                <div class="mt-10 flex justify-center pb-10">
                    <nav class="flex items-center gap-2" aria-label="Pagination">
                        <?php
                            $max_pages_to_show = 5;
                            $start_page = max(1, $page - floor($max_pages_to_show / 2));
                            $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                            $start_page = max(1, $end_page - $max_pages_to_show + 1);

                            if ($page > 1) {
                                echo '<a href="?page='.($page-1).'&'.$filter_query_string.'" class="h-10 px-4 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold"><i class="fas fa-chevron-left"></i></a>';
                            }

                            if ($start_page > 1) {
                                echo '<a href="?page=1&'.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold">1</a>';
                                if ($start_page > 2) echo '<span class="h-10 w-10 flex items-center justify-center text-slate-400">...</span>';
                            }

                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $active = ($i == $page) ? 'bg-slate-800 text-white shadow-lg shadow-slate-200' : 'glass-card text-slate-600 hover:bg-slate-800 hover:text-white';
                                echo '<a href="?page='.$i.'&'.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center rounded-xl '.$active.' transition-all text-sm font-bold">'.$i.'</a>';
                            }

                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) echo '<span class="h-10 w-10 flex items-center justify-center text-slate-400">...</span>';
                                echo '<a href="?page='.$total_pages.'&'.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold">'.$total_pages.'</a>';
                            }

                            if ($page < $total_pages) {
                                echo '<a href="?page='.($page+1).'&'.$filter_query_string.'" class="h-10 px-4 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold"><i class="fas fa-chevron-right"></i></a>';
                            }
                        ?>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
        </div>
    </div>
    
    <!-- Modal untuk Import -->
    <div id="importModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-[100] hidden px-4">
        <div class="glass-card p-8 rounded-3xl shadow-2xl w-full max-w-2xl border border-white/50 animate-in fade-in zoom-in duration-300">
            <h2 class="text-2xl font-black text-slate-800 mb-6">Import Stok Massal</h2>
            <div class="bg-blue-50 border border-blue-100 p-5 rounded-2xl mb-8">
                <p class="font-bold text-blue-800 text-sm mb-3">Petunjuk Import:</p>
                <ul class="space-y-2">
                    <li class="flex items-start gap-2 text-blue-700 text-xs"><i class="fas fa-info-circle mt-0.5"></i> Gunakan file Excel (.xlsx). Stok yang sudah ada akan dijumlahkan otomatis.</li>
                    <li class="flex items-start gap-2 text-blue-700 text-xs"><i class="fas fa-check-circle mt-0.5"></i> Pastikan Nama Proyek, Jenis, Brand, dan Branch sudah terdaftar di sistem.</li>
                    <li class="flex items-start gap-2 text-blue-700 text-xs"><i class="fas fa-exclamation-triangle mt-0.5"></i> Kolom Micro Cluster opsional (kosongkan untuk level Branch).</li>
                </ul>
            </div>
            <form action="admin_manage_matpro_stocks.php" method="POST" enctype="multipart/form-data" class="space-y-8">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Pilih File XLSX</label>
                    <div class="relative group">
                        <input type="file" name="file_import" required accept=".xlsx" 
                               class="w-full bg-slate-50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium">
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row gap-4 justify-between items-center bg-slate-50 p-4 rounded-2xl border border-slate-100">
                    <a href="admin_manage_matpro_stocks.php?action=download_template" class="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-2">
                        <i class="fas fa-download"></i> Unduh Template Excel
                    </a>
                    <div class="flex gap-3 w-full sm:w-auto">
                        <button type="button" id="closeImportModalBtn" class="flex-grow sm:flex-grow-0 px-6 py-3 bg-white border border-slate-200 text-slate-600 font-bold text-sm rounded-xl hover:bg-slate-100 transition-all">Batal</button>
                        <button type="submit" class="flex-grow sm:flex-grow-0 px-6 py-3 bg-blue-600 text-white font-bold text-sm rounded-xl hover:bg-blue-700 shadow-lg shadow-blue-100 transition-all">Mulai Import</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal untuk Edit -->
    <div id="editModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-[100] hidden px-4">
        <div class="glass-card p-8 rounded-3xl shadow-2xl w-full max-w-lg border border-white/50 animate-in fade-in zoom-in duration-300">
            <h2 class="text-2xl font-black text-slate-800 mb-6">Penyesuaian Stok</h2>
            <form id="editForm" action="process/admin_matpro_stock_process.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_stock_id">
                
                <div class="bg-slate-50 p-5 rounded-2xl border border-slate-100 space-y-3" id="edit_stock_details">
                    <!-- Dynamic Content -->
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Total Stok Terbaru</label>
                    <input type="number" name="stock_quantity" id="edit_stock_quantity" required min="0"
                           class="w-full bg-white border border-slate-200 rounded-2xl py-4 px-6 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-xl font-black text-slate-800">
                    <p class="text-[10px] font-bold text-slate-400 ml-1">Masukkan jumlah total akhir (bukan penambahan).</p>
                </div>

                <div class="flex gap-3 pt-4 font-bold">
                    <button type="button" id="closeEditModalBtn" class="flex-grow py-4 bg-white border border-slate-200 text-slate-600 rounded-2xl hover:bg-slate-50 transition-all text-sm">Batal</button>
                    <button type="submit" class="flex-grow py-4 bg-blue-600 text-white rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-100 transition-all text-sm">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal Import
    const openImportBtn = document.getElementById('openImportModalBtn');
    const closeImportBtn = document.getElementById('closeImportModalBtn');
    const importModal = document.getElementById('importModal');
    openImportBtn.addEventListener('click', () => importModal.classList.remove('hidden'));
    closeImportBtn.addEventListener('click', () => importModal.classList.add('hidden'));

    // Modal Edit
    const openAddBtn = document.getElementById('openAddModalBtn');
    const closeEditBtn = document.getElementById('closeEditModalBtn');
    const editModal = document.getElementById('editModal');
    // Redirect ke form tambah stok yang terpisah
    openAddBtn.addEventListener('click', () => window.location.href = 'admin_matpro_stock_form.php');
    closeEditBtn.addEventListener('click', () => editModal.classList.add('hidden'));

    // Cascading filter
    const brandFilter = document.getElementById('brand_filter');
    const branchFilter = document.getElementById('branch_filter');
    const mcFilter = document.getElementById('mc_filter');

    function loadOptions(url, selectElement, prompt, selectedValue = null) {
        fetch(url)
            .then(response => response.json())
            .then(data => {
                selectElement.innerHTML = `<option value="">${prompt}</option>`;
                data.forEach(item => {
                    const optionText = item.nama_branch || item.nama_micro_cluster;
                    const option = new Option(optionText, item.id);
                    selectElement.add(option);
                });
                if (selectedValue) selectElement.value = selectedValue;
                selectElement.disabled = false;
            })
            .catch(error => {
                console.error('Error:', error);
                selectElement.innerHTML = `<option value="">Gagal memuat</option>`;
            });
    }

    brandFilter.addEventListener('change', function() {
        const brand = this.value;
        branchFilter.innerHTML = '<option value="">Pilih Branch</option>';
        mcFilter.innerHTML = '<option value="">Pilih Branch dulu</option>';
        branchFilter.disabled = true;
        mcFilter.disabled = true;
        if (brand) {
            loadOptions(`api_helper.php?action=get_branches_by_brand&brand=${brand}`, branchFilter, 'Semua Branch');
        }
    });

    branchFilter.addEventListener('change', function() {
        const branchId = this.value;
        mcFilter.innerHTML = '<option value="">Pilih Micro Cluster</option>';
        mcFilter.disabled = true;
        if (branchId) {
            loadOptions(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${branchId}`, mcFilter, 'Semua Micro Cluster');
        }
    });
    
    const initialBrand = '<?php echo $brand_filter; ?>';
    const initialBranch = '<?php echo $branch_filter; ?>';
    const initialMc = '<?php echo $mc_filter; ?>';

    if (initialBrand) {
        loadOptions(`api_helper.php?action=get_branches_by_brand&brand=${initialBrand}`, branchFilter, 'Semua Branch', initialBranch);
        if(initialBranch) {
            loadOptions(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${initialBranch}`, mcFilter, 'Semua Micro Cluster', initialMc);
        }
    }
    
    // Logic for bulk delete
    const mainForm = document.getElementById('mainForm');
    const selectAllCheckbox = document.getElementById('selectAll');
    const stockCheckboxes = document.querySelectorAll('.stock-checkbox');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

    function updateBulkDeleteButton() {
        const checkedCount = document.querySelectorAll('.stock-checkbox:checked').length;
        bulkDeleteBtn.disabled = checkedCount === 0;
        if(checkedCount > 0) {
            bulkDeleteBtn.classList.add('bg-red-600', 'text-white');
            bulkDeleteBtn.classList.remove('bg-red-50', 'text-red-600');
        } else {
            bulkDeleteBtn.classList.remove('bg-red-600', 'text-white');
            bulkDeleteBtn.classList.add('bg-red-50', 'text-red-600');
        }
    }

    selectAllCheckbox.addEventListener('change', function() {
        stockCheckboxes.forEach(checkbox => { checkbox.checked = this.checked; });
        updateBulkDeleteButton();
    });

    stockCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            selectAllCheckbox.checked = document.querySelectorAll('.stock-checkbox:checked').length === stockCheckboxes.length;
            updateBulkDeleteButton();
        });
    });

    updateBulkDeleteButton();
});

function openEditModal(stockId) {
    fetch(`api_helper.php?action=get_stock_details&id=${stockId}`)
        .then(response => response.json())
        .then(data => {
            if (data && !data.error) {
                document.getElementById('edit_stock_id').value = data.id;
                document.getElementById('edit_stock_quantity').value = data.stock_quantity;
                
                const detailsDiv = document.getElementById('edit_stock_details');
                detailsDiv.innerHTML = `
                    <div class="flex items-center gap-3 mb-3 border-b border-slate-200 pb-3">
                        <div class="h-10 w-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center font-black text-xs uppercase">${data.brand.charAt(0)}</div>
                        <div>
                            <p class="text-sm font-bold text-slate-800">${data.type_name}</p>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">${data.project_name}</p>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold text-slate-500 uppercase">Wilayah:</p>
                        <p class="text-xs font-bold text-slate-700">${data.nama_branch} ${data.nama_micro_cluster ? '— ' + data.nama_micro_cluster : ''}</p>
                    </div>
                `;
                
                document.getElementById('editModal').classList.remove('hidden');
            } else {
                alert('Gagal memuat detail stok: ' + (data.error || 'Data tidak ditemukan.'));
            }
        })
        .catch(error => {
            console.error('Error fetching stock details:', error);
            alert('Terjadi kesalahan saat memuat data.');
        });
}

function deleteSingle(id) {
    if (confirm('Anda yakin ingin menghapus stok ini? Aksi ini tidak bisa dibatalkan.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'process/admin_matpro_stock_process.php';
        form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>
