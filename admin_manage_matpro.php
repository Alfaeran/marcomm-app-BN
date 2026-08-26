<?php
// admin_manage_matpro.php (REVISED with Search Feature)

// --- EXPORT DATA HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    require_once 'config/database.php';
    require_once 'vendor/autoload.php';

    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
        http_response_code(403);
        echo "Akses ditolak";
        exit;
    }
    
    ob_start();

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Matpro Stocks Export');

    $headers = [
        'ID', 'Username', 'Nama Proyek', 'Brand Proyek', 'Nama Jenis',
        'Nama Branch', 'Nama Micro Cluster', 'Jumlah Stok', 'Status Aktif',
        'Terakhir Diperbarui Oleh', 'Terakhir Diperbarui Pada'
    ];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }

    $sql = "SELECT 
                ms.id, 
                u.username, 
                ms.project_name, 
                ms.project_brand, 
                ms.type_name, 
                b.nama_branch, 
                mc.nama_micro_cluster, 
                ms.stock_quantity, 
                IF(ms.is_active = 1, 'Aktif', 'Nonaktif') as status_aktif, 
                updater.username as last_updated_by_username, 
                ms.last_updated_at
            FROM matpro_stocks ms
            LEFT JOIN users u ON ms.user_id = u.id
            LEFT JOIN branches b ON ms.branch_id = b.id
            LEFT JOIN micro_clusters mc ON ms.micro_cluster_id = mc.id
            LEFT JOIN users updater ON ms.last_updated_by = updater.id
            ORDER BY ms.project_brand, ms.project_name, b.nama_branch";
    $result = $mysqli->query($sql);

    $rowNumber = 2;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $col = 'A';
            foreach ($row as $value) {
                $sheet->setCellValue($col . $rowNumber, $value);
                $col++;
            }
            $rowNumber++;
        }
    }

    $filename = 'matpro_stocks_export_' . date('Y-m-d') . '.xlsx';
    
    ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// --- CRITICAL: FILE DOWNLOAD HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    require_once 'config/database.php';
    require_once 'vendor/autoload.php';

    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
        http_response_code(403);
        echo "Akses Ditolak.";
        exit;
    }
    
    ob_start();

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Template Super Import');
    
    $headers = ['Nama Proyek', 'Brand Proyek (IM3/3ID/BOTH)', 'Nama Jenis Matpro', 'Nama Branch', 'Nama Micro Cluster (Opsional)', 'Jumlah Stok', 'Username User Input'];
    $sheet->fromArray($headers, NULL, 'A1');
    foreach (range('A', 'G') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
    
    ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="template_super_import_stok.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// --- MAIN PAGE LOGIC ---
require_once 'config/database.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

// --- Helper Functions ---
if (!function_exists('ref_values')) {
    function ref_values(&$arr){
        $refs = [];
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
}

if (!function_exists('generate_stock_key')) {
    function generate_stock_key($data) {
        return ($data['user_id'] ?? '0') . '_' . 
               trim(strtoupper($data['project_name'])) . '_' . 
               trim(strtoupper($data['type_name'])) . '_' . 
               ($data['branch_id'] ?? '0') . '_' . 
               ($data['micro_cluster_id'] ?? '0') . '_' . 
               trim(strtoupper($data['project_brand']));
    }
}

// --- FORM PROCESSING (POST REQUEST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $mysqli->begin_transaction();
    try {
        switch ($action) {
            case 'edit_stock':
                $stock_id = (int)($_POST['id'] ?? 0);
                $user_id = (int)($_POST['user_id'] ?? 0);
                $project_name = trim($_POST['project_name'] ?? '');
                $project_brand = trim($_POST['project_brand'] ?? '');
                $type_name = trim($_POST['type_name'] ?? '');
                $branch_id = (int)($_POST['branch_id'] ?? 0);
                $micro_cluster_id = !empty($_POST['micro_cluster_id']) ? (int)$_POST['micro_cluster_id'] : null;
                $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);

                if (empty($stock_id) || empty($user_id) || empty($project_name) || empty($project_brand) || empty($type_name) || empty($branch_id)) {
                    throw new Exception("Semua field kecuali Micro Cluster wajib diisi.");
                }

                $sql_update = "UPDATE matpro_stocks SET 
                                user_id = ?, project_name = ?, project_brand = ?, type_name = ?,
                                branch_id = ?, micro_cluster_id = ?, stock_quantity = ?,
                                is_active = IF(? > 0, 1, 0),
                                last_updated_by = ?, last_updated_at = NOW()
                              WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql_update);
                if (!$stmt) throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                
                $stmt->bind_param("isssiiisii", 
                    $user_id, $project_name, $project_brand, $type_name, 
                    $branch_id, $micro_cluster_id, $stock_quantity, 
                    $stock_quantity, $_SESSION['id'], $stock_id
                );

                if (!$stmt->execute()) {
                    if ($mysqli->errno == 1062) {
                        throw new Exception("Gagal memperbarui: Kombinasi User, Proyek, Jenis, dan Lokasi yang sama sudah ada.");
                    } else {
                        throw new Exception("Gagal mengeksekusi update: " . $stmt->error);
                    }
                }
                $_SESSION['success_message'] = "Data stok berhasil diperbarui.";
                break;

            case 'delete_stock':
            case 'bulk_delete_stock':
                $ids_to_process = ($action === 'delete_stock') ? [$_POST['id']] : ($_POST['stock_ids'] ?? []);
                if (empty($ids_to_process)) throw new Exception("Tidak ada stok yang dipilih.");
                
                $placeholders = implode(',', array_fill(0, count($ids_to_process), '?'));
                $types = str_repeat('i', count($ids_to_process));
                
                $sql = "UPDATE matpro_stocks SET is_active = 0, last_updated_by = ? WHERE id IN ($placeholders)";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                
                array_unshift($ids_to_process, $_SESSION['id']);
                $stmt->bind_param('i' . $types, ...$ids_to_process);
                $stmt->execute();
                $_SESSION['success_message'] = $stmt->affected_rows . " stok berhasil dinonaktifkan.";
                break;

            case 'bulk_activate_stock':
                $ids_to_process = $_POST['stock_ids'] ?? [];
                if (empty($ids_to_process)) throw new Exception("Tidak ada stok yang dipilih.");

                $placeholders = implode(',', array_fill(0, count($ids_to_process), '?'));
                $types = str_repeat('i', count($ids_to_process));

                $sql = "UPDATE matpro_stocks SET is_active = 1, last_updated_by = ? WHERE id IN ($placeholders)";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) throw new Exception("Gagal menyiapkan statement: " . $mysqli->error);
                
                array_unshift($ids_to_process, $_SESSION['id']);
                $stmt->bind_param('i' . $types, ...$ids_to_process);
                $stmt->execute();
                $_SESSION['success_message'] = $stmt->affected_rows . " stok berhasil diaktifkan.";
                break;

            case 'super_import':
                require_once 'vendor/autoload.php';
                $file = $_FILES['file_import']['tmp_name'];
                $imported_count = 0;
                $updated_count = 0;
                $failed_rows = [];
                
                if ($_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Kesalahan upload file: " . $_FILES['file_import']['error']);
                }
                
                $branches_map = [];
                $result_branches = $mysqli->query("SELECT id, nama_branch, brand FROM branches");
                while ($row = $result_branches->fetch_assoc()) {
                    $key = trim(strtoupper($row['nama_branch'])) . '_' . trim(strtoupper($row['brand']));
                    $branches_map[$key] = $row['id'];
                }
                
                $mcs_map = [];
                $result_mcs = $mysqli->query("SELECT id, nama_micro_cluster, branch_id FROM micro_clusters");
                while ($row = $result_mcs->fetch_assoc()) {
                    $key = $row['branch_id'] . '_' . trim(strtoupper($row['nama_micro_cluster']));
                    $mcs_map[$key] = $row['id'];
                }

                $users_map = [];
                $result_users = $mysqli->query("SELECT id, username FROM users");
                while ($row = $result_users->fetch_assoc()) { $users_map[trim(strtoupper($row['username']))] = $row['id']; }

                $existing_stocks_map = [];
                $result_stocks = $mysqli->query("SELECT id, user_id, project_name, project_brand, type_name, branch_id, micro_cluster_id FROM matpro_stocks");
                while ($row = $result_stocks->fetch_assoc()) {
                    $key = generate_stock_key($row);
                    $existing_stocks_map[$key] = $row['id'];
                }

                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
                $sheet = $spreadsheet->getActiveSheet();
                
                $rows_to_insert = [];
                $rows_to_update = [];

                foreach ($sheet->getRowIterator(2) as $row) {
                    $rowIndex = $row->getRowIndex();
                    $rowData = $sheet->rangeToArray('A' . $rowIndex . ':G' . $rowIndex, NULL, TRUE, FALSE)[0];
                    
                    try {
                        list($project_name, $project_brand, $type_name, $branch_name, $mc_name, $quantity, $username_input) = array_map('trim', $rowData);

                        if (empty($project_name) && empty($type_name)) continue;

                        if (empty($project_name) || empty($project_brand) || empty($type_name) || empty($branch_name) || !is_numeric($quantity) || empty($username_input)) {
                            throw new Exception("Kolom Proyek, Brand, Jenis, Branch, Jumlah Stok (angka), dan Username wajib diisi.");
                        }
                        $project_brand_upper = strtoupper($project_brand);
                        if (!in_array($project_brand_upper, ['IM3', '3ID', 'BOTH'])) {
                            throw new Exception("Brand Proyek tidak valid. Harus 'IM3', '3ID', atau 'BOTH'.");
                        }

                        $user_id_from_excel = $users_map[trim(strtoupper($username_input))] ?? null;
                        if ($user_id_from_excel === null) throw new Exception("Username '" . htmlspecialchars($username_input) . "' tidak ditemukan.");

                        $branch_key = trim(strtoupper($branch_name)) . '_' . $project_brand_upper;
                        $branch_id = $branches_map[$branch_key] ?? null;
                        if ($branch_id === null) throw new Exception("Kombinasi Nama Branch '" . htmlspecialchars($branch_name) . "' dan Brand '" . htmlspecialchars($project_brand_upper) . "' tidak ditemukan.");
                        
                        $mc_id = null;
                        if (!empty($mc_name)) {
                            $mc_key = $branch_id . '_' . trim(strtoupper($mc_name));
                            $mc_id = $mcs_map[$mc_key] ?? null;
                            if ($mc_id === null) throw new Exception("Nama Micro Cluster '" . htmlspecialchars($mc_name) . "' tidak ditemukan di dalam Branch '" . htmlspecialchars($branch_name) . "'.");
                        }
                        
                        $data_to_process = [
                            'user_id' => $user_id_from_excel, 
                            'project_name' => $project_name,
                            'project_brand' => $project_brand_upper, 
                            'type_name' => $type_name,
                            'branch_id' => $branch_id, 
                            'micro_cluster_id' => $mc_id, 
                            'stock_quantity' => (int)$quantity
                        ];

                        $stock_key = generate_stock_key($data_to_process);

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

                if (!empty($rows_to_insert)) {
                    $sql_insert = "INSERT INTO matpro_stocks (user_id, project_name, project_brand, type_name, branch_id, micro_cluster_id, stock_quantity, last_updated_by, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, IF(? > 0, 1, 0))";
                    $stmt_insert = $mysqli->prepare($sql_insert);
                    if (!$stmt_insert) throw new Exception("Gagal menyiapkan statement insert: " . $mysqli->error);
                    foreach ($rows_to_insert as $data_row) {
                        $stmt_insert->bind_param("isssiiiii", $data_row['user_id'], $data_row['project_name'], $data_row['project_brand'], $data_row['type_name'], $data_row['branch_id'], $data_row['micro_cluster_id'], $data_row['stock_quantity'], $_SESSION['id'], $data_row['stock_quantity']);
                        if ($stmt_insert->execute()) $imported_count++;
                    }
                    $stmt_insert->close();
                }

                if (!empty($rows_to_update)) {
                    $sql_update = "UPDATE matpro_stocks SET stock_quantity = ?, last_updated_by = ?, is_active = IF(? > 0, 1, 0), last_updated_at = NOW() WHERE id = ?";
                    $stmt_update = $mysqli->prepare($sql_update);
                    if (!$stmt_update) throw new Exception("Gagal menyiapkan statement update: " . $mysqli->error);
                    foreach ($rows_to_update as $data_row) {
                        $stmt_update->bind_param("iiii", $data_row['stock_quantity'], $_SESSION['id'], $data_row['stock_quantity'], $data_row['id']);
                        if ($stmt_update->execute()) $updated_count++;
                    }
                    $stmt_update->close();
                }
                
                if ($imported_count > 0 || $updated_count > 0) $_SESSION['success_message'] = "Proses selesai! " . $imported_count . " stok baru diimpor dan " . $updated_count . " stok diperbarui.";
                if (!empty($failed_rows)) {
                    $_SESSION['error_message'] = count($failed_rows) . " data gagal diproses.";
                    $_SESSION['failed_rows'] = $failed_rows;
                }
                break;
        }
        $mysqli->commit();
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    header("location: admin_manage_matpro.php");
    exit();
}

// --- Data Fetching for Display ---
$success_message = $_SESSION['success_message'] ?? ''; unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? ''; unset($_SESSION['error_message']);
$failed_rows = $_SESSION['failed_rows'] ?? []; unset($_SESSION['failed_rows']);

$projects_for_filter = $mysqli->query("SELECT DISTINCT project_name FROM matpro_stocks ORDER BY project_name");
$types_for_filter = $mysqli->query("SELECT DISTINCT type_name FROM matpro_stocks ORDER BY type_name");
$users_for_filter = $mysqli->query("SELECT id, username, nama FROM users ORDER BY username");
$branches_for_filter = $mysqli->query("SELECT id, nama_branch FROM branches ORDER BY nama_branch");

$project_types_mapping = [];
$mapping_query = $mysqli->query("SELECT DISTINCT project_name, type_name FROM matpro_stocks ORDER BY project_name, type_name");
if ($mapping_query) {
    while($row = $mapping_query->fetch_assoc()) {
        $project_types_mapping[$row['project_name']][] = $row['type_name'];
    }
}

// Pagination, Filtering, and Searching
$records_per_page = 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$where_clauses = []; 
$param_types = ""; 
$param_values = [];

// NEW: Add 'search' to the list of filterable params and build query string
$filter_params = array_filter($_GET, fn($key) => in_array($key, ['search', 'project_name', 'type_name', 'user_id', 'branch_id', 'stock_status']), ARRAY_FILTER_USE_KEY);
$filter_query_string = http_build_query($filter_params);

// NEW: Search logic
$search_term = trim($_GET['search'] ?? '');
if (!empty($search_term)) {
    $where_clauses[] = "(ms.project_name LIKE ? OR ms.type_name LIKE ? OR u.username LIKE ? OR b.nama_branch LIKE ? OR mc.nama_micro_cluster LIKE ?)";
    $search_like = "%{$search_term}%";
    $param_types .= "sssss";
    array_push($param_values, $search_like, $search_like, $search_like, $search_like, $search_like);
}

// Existing filter logic
if (!empty($_GET['project_name'])) { $where_clauses[] = "ms.project_name = ?"; $param_types .= "s"; $param_values[] = $_GET['project_name']; }
if (!empty($_GET['type_name'])) { $where_clauses[] = "ms.type_name = ?"; $param_types .= "s"; $param_values[] = $_GET['type_name']; }
if (!empty($_GET['user_id'])) { $where_clauses[] = "ms.user_id = ?"; $param_types .= "i"; $param_values[] = (int)$_GET['user_id']; }
if (!empty($_GET['branch_id'])) { $where_clauses[] = "ms.branch_id = ?"; $param_types .= "i"; $param_values[] = (int)$_GET['branch_id']; }
$stock_status_filter = $_GET['stock_status'] ?? 'active';
if ($stock_status_filter === 'active') { $where_clauses[] = "ms.is_active = 1"; } 
elseif ($stock_status_filter === 'inactive') { $where_clauses[] = "ms.is_active = 0"; }

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

// REVISED: The count query MUST have the same joins as the main query for search/filtering to work
$count_sql = "SELECT COUNT(ms.id) as total 
              FROM matpro_stocks ms
              LEFT JOIN users u ON ms.user_id = u.id
              LEFT JOIN branches b ON ms.branch_id = b.id
              LEFT JOIN micro_clusters mc ON ms.micro_cluster_id = mc.id" 
              . $where_sql;
              
$stmt_count = $mysqli->prepare($count_sql);
if (!$stmt_count) {
    die("Error preparing count query: " . $mysqli->error);
}
if (!empty($param_values)) { call_user_func_array([$stmt_count, 'bind_param'], array_merge([$param_types], ref_values($param_values))); }
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
$total_pages = ceil($total_records / $records_per_page);

// The main query already has the necessary joins.
$sql_stocks = "SELECT ms.*, b.nama_branch, mc.nama_micro_cluster, u.username
        FROM matpro_stocks ms
        LEFT JOIN branches b ON ms.branch_id = b.id
        LEFT JOIN micro_clusters mc ON ms.micro_cluster_id = mc.id
        LEFT JOIN users u ON ms.user_id = u.id"
        . $where_sql . " ORDER BY ms.project_name, ms.type_name, b.nama_branch, mc.nama_micro_cluster ASC LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt_stocks = $mysqli->prepare($sql_stocks);
if (!$stmt_stocks) {
    die("Error preparing stocks query: " . $mysqli->error);
}
if (!empty($param_values_page)) { call_user_func_array([$stmt_stocks, 'bind_param'], array_merge([$param_types_page], ref_values($param_values_page))); }
$stmt_stocks->execute();
$stocks_result = $stmt_stocks->get_result();
$stmt_stocks->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Manajemen Stok Matpro - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
    <style>
        .glass-badge {
            background: rgba(255, 255, 255, 0.4);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
    </style>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Stok Material Promo</h2>
                        <p class="text-slate-500 font-medium">Manajemen inventaris, alokasi stok per user, dan logistik matpro.</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <button onclick="openSuperImportModal()" class="px-5 py-3 bg-white/50 backdrop-blur-md border border-slate-200 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-star text-amber-500"></i> Super Import
                    </button>
                    <a href="?action=export" class="px-5 py-3 bg-emerald-600 text-white font-bold text-sm rounded-2xl hover:bg-emerald-700 shadow-lg shadow-emerald-200 transition-all flex items-center gap-2">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </a>
                </div>
            </header>

            <div class="max-w-7xl mx-auto">

                <?php if (!empty($success_message)): ?>
                    <div class="glass-card bg-emerald-50/50 border-emerald-200 p-4 mb-8 flex items-center gap-3">
                        <div class="h-10 w-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle"></i></div>
                        <p class="text-emerald-800 font-bold"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="glass-card bg-red-50/50 border-red-200 p-4 mb-8 flex items-center gap-3">
                        <div class="h-10 w-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center"><i class="fas fa-exclamation-triangle"></i></div>
                        <p class="text-red-800 font-bold"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($failed_rows)): ?>
                    <div class="glass-card border-red-200 mb-8 overflow-hidden">
                        <div class="bg-red-50/50 px-6 py-4 border-b border-red-100">
                            <h3 class="text-red-800 font-black text-sm uppercase tracking-widest">Detail Kegagalan Import</h3>
                        </div>
                        <div class="overflow-x-auto max-h-60">
                            <table class="min-w-full divide-y divide-red-100">
                                <thead class="bg-red-50/30">
                                    <tr>
                                        <th class="py-3 px-6 text-left text-[10px] font-black text-red-400 uppercase tracking-widest">Baris</th>
                                        <th class="py-3 px-6 text-left text-[10px] font-black text-red-400 uppercase tracking-widest text-left">Data Mentah</th>
                                        <th class="py-3 px-6 text-left text-[10px] font-black text-red-400 uppercase tracking-widest text-left">Pesan Error</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-red-50 bg-white/20">
                                    <?php foreach ($failed_rows as $failed): ?>
                                    <tr>
                                        <td class="py-3 px-6 text-xs font-bold text-red-700">#<?php echo htmlspecialchars($failed['row']); ?></td>
                                        <td class="py-3 px-6 text-[10px] font-medium text-slate-500 italic"><?php echo htmlspecialchars(implode(', ', $failed['data'])); ?></td>
                                        <td class="py-3 px-6 text-xs font-semibold text-red-600"><?php echo htmlspecialchars($failed['reason']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Advanced Inventory Filter Card -->
                <div class="glass-card p-8 mb-10">
                    <div class="flex items-center gap-3 mb-8">
                        <div class="h-10 w-4 bg-blue-600 rounded-full"></div>
                        <h3 class="text-xl font-extrabold text-slate-800 tracking-tight">Filter Inventaris Matpro</h3>
                    </div>

                    <form method="GET" action="admin_manage_matpro.php" class="space-y-8">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <div class="lg:col-span-2 space-y-2">
                                <label for="search_box" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Cari Inventaris</label>
                                <div class="relative group">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                        <i class="fas fa-search"></i>
                                    </span>
                                    <input type="text" id="search_box" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" 
                                           placeholder="Cari Proyek, Jenis, User, atau Branch..."
                                           class="w-full bg-slate-50 border border-slate-200 rounded-2xl pl-12 pr-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all placeholder:text-slate-400">
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Tampilkan Data</label>
                                <div class="relative">
                                    <select name="stock_status" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                        <option value="active" <?php echo ($stock_status_filter == 'active') ? 'selected' : ''; ?>>Stok Aktif</option>
                                        <option value="inactive" <?php echo ($stock_status_filter == 'inactive') ? 'selected' : ''; ?>>Stok Nonaktif</option>
                                        <option value="all" <?php echo ($stock_status_filter == 'all') ? 'selected' : ''; ?>>Semua Stok</option>
                                    </select>
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Proyek</label>
                                <div class="relative">
                                    <select name="project_name" id="filterProjectName" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                        <option value="">Semua Proyek</option>
                                        <?php if($projects_for_filter): $projects_for_filter->data_seek(0); while($p = $projects_for_filter->fetch_assoc()): ?>
                                            <option value="<?php echo htmlspecialchars($p['project_name']); ?>" <?php echo ($_GET['project_name'] ?? '') == $p['project_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['project_name']); ?></option>
                                        <?php endwhile; endif; ?>
                                    </select>
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="space-y-2">
                                <label class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Jenis Matpro</label>
                                <div class="relative">
                                    <select name="type_name" id="filterTypeName" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                        <option value="">Semua Jenis</option>
                                        <?php if($types_for_filter): $types_for_filter->data_seek(0); while($t = $types_for_filter->fetch_assoc()): ?>
                                            <option value="<?php echo htmlspecialchars($t['type_name']); ?>" <?php echo ($_GET['type_name'] ?? '') == $t['type_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['type_name']); ?></option>
                                        <?php endwhile; endif; ?>
                                    </select>
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">User Input</label>
                                <div class="relative">
                                    <select name="user_id" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                        <option value="">Semua User</option>
                                        <?php if($users_for_filter): $users_for_filter->data_seek(0); while($user = $users_for_filter->fetch_assoc()): ?>
                                            <option value="<?php echo $user['id']; ?>" <?php echo ($_GET['user_id'] ?? '') == $user['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($user['username']); ?></option>
                                        <?php endwhile; endif; ?>
                                    </select>
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Branch</label>
                                <div class="relative">
                                    <select name="branch_id" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                        <option value="">Semua Branch</option>
                                        <?php if($branches_for_filter): $branches_for_filter->data_seek(0); while($br = $branches_for_filter->fetch_assoc()): ?>
                                            <option value="<?php echo $br['id']; ?>" <?php echo ($_GET['branch_id'] ?? '') == $br['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($br['nama_branch']); ?></option>
                                        <?php endwhile; endif; ?>
                                    </select>
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-4">
                            <a href="admin_manage_matpro.php" class="px-8 py-3 bg-slate-100 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-200 transition-all text-center">
                                <i class="fas fa-undo mr-2"></i> Reset
                            </a>
                            <button type="submit" class="px-10 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 active:scale-95 transition-all">
                                <i class="fas fa-filter mr-2"></i> Terapkan Filter
                            </button>
                        </div>
                    </form>
                </div>

                <div class="flex flex-col md:flex-row justify-between items-center gap-6 mb-8">
                    <div class="flex flex-wrap gap-3">
                        <button onclick="openBulkDeleteConfirmModal()" class="px-5 py-3 bg-red-50 text-red-600 border border-red-100 font-bold text-sm rounded-2xl hover:bg-red-600 hover:text-white disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center gap-2" id="bulkDeleteBtn" disabled>
                            <i class="fas fa-power-off"></i> Nonaktifkan Terpilih
                        </button>
                        <button onclick="openBulkActivateConfirmModal()" class="px-5 py-3 bg-emerald-50 text-emerald-600 border border-emerald-100 font-bold text-sm rounded-2xl hover:bg-emerald-600 hover:text-white disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center gap-2" id="bulkActivateBtn" disabled>
                            <i class="fas fa-check-circle"></i> Aktifkan Terpilih
                        </button>
                    </div>

                    <div class="bg-white/50 backdrop-blur-md px-5 py-2.5 rounded-2xl border border-slate-200 flex items-center gap-3">
                        <i class="fas fa-database text-blue-500 text-xs"></i>
                        <span class="text-xs font-bold text-slate-500">Total <span class="text-slate-800"><?php echo number_format($total_records); ?></span> Entri Inventaris</span>
                    </div>
                </div>

                    <div class="glass-card overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200">
                                <thead class="bg-slate-50/50">
                                    <tr>
                                        <th class="py-4 px-6 text-center w-12">
                                            <input type="checkbox" id="selectAllStocks" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                        </th>
                                        <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">User / Pemegang</th>
                                        <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Detail Proyek</th>
                                        <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Jenis Matpro</th>
                                        <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Lokasi Alokasi</th>
                                        <th class="py-4 px-6 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest w-24">Jumlah</th>
                                        <th class="py-4 px-6 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                                        <th class="py-4 px-6 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest w-28">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white/30 text-slate-700">
                                    <?php if ($stocks_result && $stocks_result->num_rows > 0): ?>
                                        <?php while($row = $stocks_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-blue-50/30 transition-colors">
                                            <td class="py-4 px-6 text-center">
                                                <input type="checkbox" name="stock_ids[]" value="<?php echo $row['id']; ?>" class="stock-checkbox rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                            </td>
                                            <td class="py-4 px-6">
                                                <div class="flex items-center gap-3">
                                                    <div class="h-9 w-9 bg-blue-100/50 text-blue-600 rounded-xl flex items-center justify-center font-bold text-sm">
                                                        <?php echo strtoupper(substr($row['username'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-extrabold text-slate-800 tracking-tight leading-none"><?php echo htmlspecialchars($row['username'] ?? 'N/A'); ?></p>
                                                        <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase">Pemilik Stok</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-4 px-6">
                                                <div class="space-y-1.5">
                                                    <p class="text-sm font-extrabold text-slate-800 line-clamp-1 tracking-tight"><?php echo htmlspecialchars($row['project_name']); ?></p>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[9px] font-black uppercase tracking-tighter <?php echo $row['project_brand'] == 'IM3' ? 'bg-amber-100 text-amber-700' : ($row['project_brand'] == '3ID' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-700'); ?>">
                                                        <?php echo htmlspecialchars($row['project_brand']); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="py-4 px-6">
                                                <span class="text-xs font-bold text-slate-600 bg-white/50 border border-slate-100 px-3 py-1.5 rounded-xl shadow-sm italic"><?php echo htmlspecialchars($row['type_name']); ?></span>
                                            </td>
                                            <td class="py-4 px-6">
                                                <div class="space-y-1">
                                                    <p class="text-[11px] font-black text-slate-700 uppercase leading-none tracking-tight"><?php echo htmlspecialchars($row['nama_branch']); ?></p>
                                                    <p class="text-[10px] font-medium text-slate-400 italic line-clamp-1"><?php echo htmlspecialchars($row['nama_micro_cluster'] ?? '-'); ?></p>
                                                </div>
                                            </td>
                                            <td class="py-4 px-6 text-center">
                                                <span class="text-lg font-black text-slate-900"><?php echo number_format($row['stock_quantity']); ?></span>
                                            </td>
                                            <td class="py-4 px-6 text-center">
                                                <?php if ($row['is_active']): ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-full text-[10px] font-black uppercase tracking-widest">
                                                        <span class="h-1.5 w-1.5 bg-emerald-500 rounded-full animate-pulse"></span> Aktif
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 text-slate-400 rounded-full text-[10px] font-black uppercase tracking-widest">
                                                        Nonaktif
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-4 px-6">
                                                <div class="flex justify-center items-center gap-3">
                                                    <button type="button" 
                                                            data-stock='<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>'
                                                            onclick="openEditStockModal(this)"
                                                            class="h-9 w-9 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 border border-amber-100 hover:bg-amber-100 transition-all" 
                                                            title="Edit">
                                                        <i class="fas fa-edit text-sm"></i>
                                                    </button>
                                                    <button type="button" onclick="openSingleDeleteConfirmModal('<?php echo $row['id']; ?>')" 
                                                            class="h-9 w-9 flex items-center justify-center rounded-xl bg-red-50 text-red-600 border border-red-100 hover:bg-red-600 hover:text-white transition-all" 
                                                            title="Nonaktifkan">
                                                        <i class="fas fa-power-off text-sm"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="py-24 text-center">
                                                <div class="flex flex-col items-center gap-4">
                                                    <div class="h-20 w-20 bg-slate-50 text-slate-300 rounded-3xl flex items-center justify-center text-3xl">
                                                        <i class="fas fa-boxes"></i>
                                                    </div>
                                                    <div>
                                                        <p class="text-slate-800 font-extrabold">Inventaris tidak ditemukan</p>
                                                        <p class="text-slate-400 text-sm font-medium mt-1">Gunakan filter atau pencarian di atas untuk memfilter data.</p>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-10 flex flex-col md:flex-row items-center justify-between gap-6 pb-12">
                        <p class="text-sm font-bold text-slate-500 order-2 md:order-1">
                            Menampilkan halaman <span class="text-slate-800"><?php echo $page; ?></span> dari <span class="text-slate-800"><?php echo $total_pages; ?></span>
                        </p>
                        
                        <?php if ($total_pages > 1): ?>
                        <nav class="flex items-center gap-2 order-1 md:order-2">
                            <?php
                            $max_pages_to_show = 5;
                            $start_page = max(1, $page - floor($max_pages_to_show / 2));
                            $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                            $start_page = max(1, $end_page - $max_pages_to_show + 1);

                            if ($page > 1): ?>
                                <a href="?page=<?php echo $page-1; ?>&<?php echo $filter_query_string; ?>" 
                                   class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-all">
                                    <i class="fas fa-chevron-left text-xs"></i>
                                </a>
                            <?php endif;

                            for ($i = $start_page; $i <= $end_page; $i++): 
                                $is_active = ($i == $page);
                            ?>
                                <a href="?page=<?php echo $i; ?>&<?php echo $filter_query_string; ?>" 
                                   class="h-10 px-4 flex items-center justify-center rounded-xl <?php echo $is_active ? 'bg-blue-600 text-white shadow-lg shadow-blue-100 border-blue-600' : 'glass-card text-slate-600 hover:bg-blue-50 hover:text-blue-600'; ?> text-sm font-bold transition-all">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor;

                            if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page+1; ?>&<?php echo $filter_query_string; ?>" 
                                   class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-all">
                                    <i class="fas fa-chevron-right text-xs"></i>
                                </a>
                            <?php endif; ?>
                        </nav>
                        <?php endif; ?>
                    </div>
        </div>
    </div>
    
            </div>
        </main>
    </div>
    
    <!-- Modal Super Import -->
    <div id="superImportModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeModal('superImportModal')"></div>
        <div class="glass-card w-full max-w-xl relative transform scale-95 opacity-0 transition-all duration-300 ease-out" id="superImportModalContent">
            <div class="flex items-center justify-between p-6 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 bg-amber-100 text-amber-600 rounded-xl flex items-center justify-center font-bold">
                        <i class="fas fa-star"></i>
                    </div>
                    <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Super Import Stok</h2>
                </div>
                <button onclick="closeModal('superImportModal')" class="text-slate-400 hover:text-slate-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form action="admin_manage_matpro.php" method="POST" enctype="multipart/form-data" class="p-8 space-y-8">
                <input type="hidden" name="action" value="super_import">
                
                <div class="space-y-3">
                    <label for="file_import" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Pilih File Excel (.xlsx)</label>
                    <div class="relative group">
                        <input type="file" name="file_import" id="file_import" required accept=".xlsx" 
                               class="w-full bg-slate-50 border-2 border-dashed border-slate-200 rounded-2xl p-8 text-sm font-medium text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100 transition-all cursor-pointer">
                    </div>
                    <p class="text-[10px] text-slate-400 font-medium px-1 italic">* Pastikan format file sesuai dengan template yang disediakan.</p>
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-slate-100">
                    <a href="?action=download_template" class="text-xs font-bold text-blue-600 hover:text-blue-800 flex items-center gap-2 transition-colors">
                        <i class="fas fa-download"></i> Unduh Template Excel
                    </a>
                    <div class="flex gap-3">
                        <button type="button" onclick="closeModal('superImportModal')" class="px-6 py-2.5 bg-slate-50 text-slate-500 font-bold text-xs rounded-xl hover:bg-slate-100 transition-all">
                            Batal
                        </button>
                        <button type="submit" class="px-8 py-2.5 bg-blue-600 text-white font-bold text-xs rounded-xl hover:bg-blue-700 shadow-lg shadow-blue-100 transition-all">
                            Mulai Import
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Edit Stok -->
    <div id="stockModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeModal('stockModal')"></div>
        <div class="glass-card w-full max-w-2xl relative transform scale-95 opacity-0 transition-all duration-300 ease-out" id="stockModalContent">
            <div class="flex items-center justify-between p-6 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center font-bold">
                        <i class="fas fa-edit"></i>
                    </div>
                    <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Edit Detail Stok</h2>
                </div>
                <button onclick="closeModal('stockModal')" class="text-slate-400 hover:text-slate-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form id="stockForm" action="admin_manage_matpro.php" method="POST" class="p-8 space-y-6">
                <input type="hidden" name="action" value="edit_stock">
                <input type="hidden" name="id" id="stockId">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label for="stockProjectName" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Nama Proyek</label>
                            <input type="text" name="project_name" id="stockProjectName" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all" required>
                        </div>
                        <div class="space-y-2">
                            <label for="stockTypeName" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Jenis Matpro</label>
                            <input type="text" name="type_name" id="stockTypeName" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all" required>
                        </div>
                        <div class="space-y-2">
                            <label for="stockProjectBrand" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Brand Proyek</label>
                            <div class="relative">
                                <select name="project_brand" id="stockProjectBrand" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer" required>
                                    <option value="IM3">IM3</option> <option value="3ID">3ID</option> <option value="BOTH">BOTH</option>
                                </select>
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none font-bold">
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </span>
                            </div>
                        </div>
                         <div class="space-y-2">
                            <label for="stockQuantity" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Jumlah Stok</label>
                            <input type="number" name="stock_quantity" id="stockQuantity" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-extrabold focus:ring-4 focus:ring-blue-100 outline-none transition-all text-blue-600" required min="0">
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label for="stockUserId" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Pemilik Stok</label>
                            <div class="relative">
                                <select name="user_id" id="stockUserId" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer" required>
                                    <option value="">-- Pilih User --</option>
                                    <?php if (isset($users_for_filter) && $users_for_filter->num_rows > 0): $users_for_filter->data_seek(0); while($user = $users_for_filter->fetch_assoc()): ?>
                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label for="stockBranchId" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Branch</label>
                            <div class="relative">
                                <select name="branch_id" id="stockBranchId" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer" required>
                                    <option value="">-- Pilih Branch --</option>
                                     <?php if (isset($branches_for_filter) && $branches_for_filter->num_rows > 0): $branches_for_filter->data_seek(0); while($branch = $branches_for_filter->fetch_assoc()): ?>
                                     <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['nama_branch']); ?></option>
                                     <?php endwhile; endif; ?>
                                </select>
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none font-bold">
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </span>
                            </div>
                        </div>
                         <div class="space-y-2">
                            <label for="stockMcId" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Micro Cluster (Opsional)</label>
                            <div class="relative">
                                <select name="micro_cluster_id" id="stockMcId" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                    <option value="">-- Level Branch --</option>
                                </select>
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none font-bold">
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-6 border-t border-slate-100">
                    <button type="button" onclick="closeModal('stockModal')" class="px-8 py-3 bg-slate-50 text-slate-500 font-bold text-xs rounded-2xl hover:bg-slate-100 transition-all">
                        Batal
                    </button>
                    <button type="submit" class="px-10 py-3 bg-blue-600 text-white font-bold text-xs rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-100 active:scale-95 transition-all">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modals Konfirmasi -->
    <div id="bulkDeleteConfirmModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeModal('bulkDeleteConfirmModal')"></div>
        <div class="glass-card w-full max-w-sm relative p-8 text-center space-y-6 transform scale-95 opacity-0 transition-all duration-300 ease-out" id="bulkDeleteConfirmModalContent">
            <div class="h-16 w-16 bg-red-100 text-red-600 rounded-2xl flex items-center justify-center text-2xl mx-auto shadow-sm shadow-red-50">
                <i class="fas fa-power-off"></i>
            </div>
            <div>
                <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Nonaktifkan Data?</h2>
                <p class="text-slate-500 text-sm font-medium mt-2">Anda yakin ingin menonaktifkan <span id="selectedStockCount" class="text-red-600 font-black">0</span> data stok terpilih?</p>
            </div>
            <div class="flex flex-col gap-2">
                <button type="button" onclick="performBulkDelete()" class="w-full py-3 bg-red-600 text-white font-bold text-sm rounded-2xl hover:bg-red-700 shadow-lg shadow-red-100 transition-all">Ya, Nonaktifkan</button>
                <button type="button" onclick="closeModal('bulkDeleteConfirmModal')" class="w-full py-3 bg-slate-50 text-slate-500 font-bold text-sm rounded-2xl hover:bg-slate-100 transition-all">Batal</button>
            </div>
        </div>
    </div>

    <div id="bulkActivateConfirmModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeModal('bulkActivateConfirmModal')"></div>
        <div class="glass-card w-full max-w-sm relative p-8 text-center space-y-6 transform scale-95 opacity-0 transition-all duration-300 ease-out" id="bulkActivateConfirmModalContent">
            <div class="h-16 w-16 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center text-2xl mx-auto shadow-sm shadow-emerald-50">
                <i class="fas fa-check-circle"></i>
            </div>
            <div>
                <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Aktifkan Data?</h2>
                <p class="text-slate-500 text-sm font-medium mt-2">Anda yakin ingin mengaktifkan <span id="selectedActivateStockCount" class="text-emerald-600 font-black">0</span> data stok terpilih?</p>
            </div>
            <div class="flex flex-col gap-2">
                <button type="button" onclick="performBulkActivate()" class="w-full py-3 bg-emerald-600 text-white font-bold text-sm rounded-2xl hover:bg-emerald-700 shadow-lg shadow-emerald-100 transition-all">Ya, Aktifkan</button>
                <button type="button" onclick="closeModal('bulkActivateConfirmModal')" class="w-full py-3 bg-slate-50 text-slate-500 font-bold text-sm rounded-2xl hover:bg-slate-100 transition-all">Batal</button>
            </div>
        </div>
    </div>

    <div id="singleDeleteConfirmModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeModal('singleDeleteConfirmModal')"></div>
        <div class="glass-card w-full max-w-sm relative p-8 text-center space-y-6 transform scale-95 opacity-0 transition-all duration-300 ease-out" id="singleDeleteConfirmModalContent">
            <div class="h-16 w-16 bg-red-100 text-red-600 rounded-2xl flex items-center justify-center text-2xl mx-auto shadow-sm shadow-red-50">
                <i class="fas fa-power-off"></i>
            </div>
            <div>
                <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Nonaktifkan Stok?</h2>
                <p class="text-slate-500 text-sm font-medium mt-2">Anda yakin ingin menonaktifkan data stok ini?</p>
            </div>
            <div class="flex flex-col gap-2">
                <button type="button" id="confirmSingleDeleteBtn" class="w-full py-3 bg-red-600 text-white font-bold text-sm rounded-2xl hover:bg-red-700 shadow-lg shadow-red-100 transition-all">Ya, Nonaktifkan</button>
                <button type="button" onclick="closeModal('singleDeleteConfirmModal')" class="w-full py-3 bg-slate-50 text-slate-500 font-bold text-sm rounded-2xl hover:bg-slate-100 transition-all">Batal</button>
            </div>
        </div>
    </div>

    <form id="bulkDeleteForm" action="admin_manage_matpro.php" method="POST" class="hidden"><input type="hidden" name="action" value="bulk_delete_stock"></form>
    <form id="bulkActivateForm" action="admin_manage_matpro.php" method="POST" class="hidden"><input type="hidden" name="action" value="bulk_activate_stock"></form>
    <form id="singleDeleteForm" action="admin_manage_matpro.php" method="POST" class="hidden"><input type="hidden" name="action" value="delete_stock"><input type="hidden" name="id" id="singleDeleteStockId"></form>

    <script>
        // Modal Management
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            const content = document.getElementById(modalId + 'Content');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                content.classList.remove('scale-95', 'opacity-0');
            }, 10);
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            const content = document.getElementById(modalId + 'Content');
            content.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 300);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Hierarchical Filter Proyek -> Jenis Matpro
            const projectTypesMapping = <?php echo json_encode($project_types_mapping); ?>;
            const filterProjectName = document.getElementById('filterProjectName');
            const filterTypeName = document.getElementById('filterTypeName');
            
            if (filterProjectName && filterTypeName) {
                const updateTypesDropdown = () => {
                    const selectedProject = filterProjectName.value;
                    const currentType = filterTypeName.value; // Store currently selected type
                    
                    // Clear existing options except the first one
                    while (filterTypeName.options.length > 1) {
                        filterTypeName.remove(1);
                    }
                    
                    if (selectedProject && projectTypesMapping[selectedProject]) {
                        const allowedTypes = projectTypesMapping[selectedProject];
                        allowedTypes.forEach(type => {
                            const option = new Option(type, type);
                            filterTypeName.add(option);
                        });
                        
                        // Restore selected type if it's still valid for the new project
                        if (currentType && allowedTypes.includes(currentType)) {
                            filterTypeName.value = currentType;
                        } else {
                            filterTypeName.value = "";
                        }
                    } else if (!selectedProject) {
                        // If no project is selected, show all types again
                        <?php if($types_for_filter): $types_for_filter->data_seek(0); while($t = $types_for_filter->fetch_assoc()): ?>
                            filterTypeName.add(new Option("<?php echo htmlspecialchars($t['type_name']); ?>", "<?php echo htmlspecialchars($t['type_name']); ?>"));
                        <?php endwhile; endif; ?>
                        if (currentType) filterTypeName.value = currentType;
                    } else {
                        filterTypeName.value = "";
                    }
                };
                
                // Set initial state
                updateTypesDropdown();
                
                // Listen to changes
                filterProjectName.addEventListener('change', updateTypesDropdown);
            }

            const stockBranchSelect = document.getElementById('stockBranchId');
            const stockMcSelect = document.getElementById('stockMcId');
            
            function fetchMicroClusters(branchId, selectedMcId = null) {
                stockMcSelect.innerHTML = '<option value="">-- Memuat... --</option>';
                if (branchId) {
                    stockMcSelect.disabled = true;
                    fetch(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${branchId}`)
                        .then(response => {
                            if (!response.ok) { throw new Error('Network response was not ok'); }
                            return response.json();
                        })
                        .then(data => {
                            stockMcSelect.innerHTML = '<option value="">-- Level Branch --</option>';
                            if (data && data.length > 0) {
                                data.forEach(mc => {
                                    const option = new Option(mc.nama_micro_cluster, mc.id);
                                    stockMcSelect.add(option);
                                });
                            }
                            if (selectedMcId) {
                                stockMcSelect.value = selectedMcId;
                            }
                            stockMcSelect.disabled = false;
                        })
                        .catch(error => {
                            console.error('Error fetching micro clusters:', error);
                            stockMcSelect.innerHTML = '<option value="">Gagal memuat data</option>';
                            stockMcSelect.disabled = false;
                        });
                } else {
                     stockMcSelect.innerHTML = '<option value="">-- Level Branch --</option>';
                }
            }

            stockBranchSelect.addEventListener('change', function() {
                fetchMicroClusters(this.value);
            });

            const selectAllCheckbox = document.getElementById('selectAllStocks');
            const stockCheckboxes = document.querySelectorAll('.stock-checkbox');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const bulkActivateBtn = document.getElementById('bulkActivateBtn');

            function toggleBulkActionButtons() {
                const checkedCount = document.querySelectorAll('.stock-checkbox:checked').length;
                const anyChecked = checkedCount > 0;
                bulkDeleteBtn.disabled = !anyChecked;
                bulkActivateBtn.disabled = !anyChecked;
            }

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', (e) => {
                    stockCheckboxes.forEach(checkbox => { checkbox.checked = e.target.checked; });
                    toggleBulkActionButtons();
                });
            }

            stockCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    selectAllCheckbox.checked = Array.from(stockCheckboxes).every(cb => cb.checked);
                    toggleBulkActionButtons();
                });
            });
            
            window.openEditStockModal = function(buttonElement) {
                const stockData = JSON.parse(buttonElement.getAttribute('data-stock'));
                document.getElementById('stockForm').reset();
                document.getElementById('stockId').value = stockData.id;
                document.getElementById('stockUserId').value = stockData.user_id || '';
                document.getElementById('stockProjectName').value = stockData.project_name || '';
                document.getElementById('stockProjectBrand').value = stockData.project_brand || '';
                document.getElementById('stockTypeName').value = stockData.type_name || '';
                document.getElementById('stockBranchId').value = stockData.branch_id || '';
                document.getElementById('stockQuantity').value = stockData.stock_quantity;
                
                fetchMicroClusters(stockData.branch_id, stockData.micro_cluster_id);
                
                openModal('stockModal');
            }

            toggleBulkActionButtons();
        });

        function openSuperImportModal() { openModal('superImportModal'); }

        function openBulkDeleteConfirmModal() {
            document.getElementById('selectedStockCount').innerText = document.querySelectorAll('.stock-checkbox:checked').length;
            openModal('bulkDeleteConfirmModal');
        }

        function performBulkDelete() {
            const form = document.getElementById('bulkDeleteForm');
            form.innerHTML = '<input type="hidden" name="action" value="bulk_delete_stock">';
            document.querySelectorAll('.stock-checkbox:checked').forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'stock_ids[]';
                input.value = cb.value;
                form.appendChild(input);
            });
            form.submit();
        }

        function openBulkActivateConfirmModal() {
            document.getElementById('selectedActivateStockCount').innerText = document.querySelectorAll('.stock-checkbox:checked').length;
            openModal('bulkActivateConfirmModal');
        }

        function performBulkActivate() {
            const form = document.getElementById('bulkActivateForm');
            form.innerHTML = '<input type="hidden" name="action" value="bulk_activate_stock">';
            document.querySelectorAll('.stock-checkbox:checked').forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'stock_ids[]';
                input.value = cb.value;
                form.appendChild(input);
            });
            form.submit();
        }

        function openSingleDeleteConfirmModal(stockId) {
            document.getElementById('singleDeleteStockId').value = stockId;
            document.getElementById('confirmSingleDeleteBtn').onclick = function() {
                document.getElementById('singleDeleteForm').submit();
            };
            openModal('singleDeleteConfirmModal');
        }

        function toggleRowCheckbox(row, event) {
            // Don't toggle if clicking on a button, link, or the checkbox itself
            if (event.target.closest('button') || event.target.closest('a') || event.target.type === 'checkbox') return;
            
            const checkbox = row.querySelector('.stock-checkbox');
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                row.classList.toggle('bg-blue-50/50', checkbox.checked);
                toggleBulkActionButtons();
            }
        }
    </script>
</body>
</html>
