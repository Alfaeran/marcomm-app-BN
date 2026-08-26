<?php
// admin_manage_matpro_types.php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

// Inisialisasi pesan
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// --- Logika Paginasi & Filter ---
$records_per_page = isset($_GET['limit']) && in_array($_GET['limit'], [10, 25, 50, 100]) ? (int)$_GET['limit'] : 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$where_clauses = [];
$param_types = "";
$param_values = [];
$filter_query_string = "";

$search_keyword = $_GET['keyword'] ?? '';
$project_filter = $_GET['project_id'] ?? '';
$brand_filter = $_GET['brand'] ?? '';
$status_filter = $_GET['status'] ?? ''; // 'active', 'inactive', 'all'

// Filter pencarian
if (!empty($search_keyword)) {
    $where_clauses[] = "mt.type_name LIKE ?";
    $param_types .= "s";
    $param_values[] = "%" . $search_keyword . "%";
    $filter_query_string .= "&keyword=" . urlencode($search_keyword);
}
if (!empty($project_filter)) {
    $where_clauses[] = "mt.project_id = ?";
    $param_types .= "i";
    $param_values[] = (int)$project_filter;
    $filter_query_string .= "&project_id=" . urlencode($project_filter);
}
if (!empty($brand_filter)) {
    $where_clauses[] = "mp.brand = ?";
    $param_types .= "s";
    $param_values[] = $brand_filter;
    $filter_query_string .= "&brand=" . urlencode($brand_filter);
}
if ($status_filter === 'active') {
    $where_clauses[] = "mt.is_active = 1";
    $filter_query_string .= "&status=active";
} elseif ($status_filter === 'inactive') {
    $where_clauses[] = "mt.is_active = 0";
    $filter_query_string .= "&status=inactive";
} else {
    $filter_query_string .= "&status=all";
}


$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

// Query untuk menghitung total data
$count_sql = "SELECT COUNT(mt.id) as total FROM matpro_types mt JOIN matpro_projects mp ON mt.project_id = mp.id" . $where_sql;
$stmt_count = $mysqli->prepare($count_sql);
if ($stmt_count) {
    if (!empty($param_values)) {
        $stmt_count->bind_param($param_types, ...$param_values);
    }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
} else {
    $total_records = 0;
    $error_message = "Gagal menghitung total jenis Matpro: " . $mysqli->error;
}
$total_pages = ceil($total_records / $records_per_page);

// Query untuk mengambil data jenis Matpro
$sql = "SELECT mt.id, mt.type_name, mt.is_active, mp.project_name, mp.brand 
        FROM matpro_types mt 
        JOIN matpro_projects mp ON mt.project_id = mp.id" 
        . $where_sql . " ORDER BY mp.project_name ASC, mt.type_name ASC LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param($param_types_page, ...$param_values_page);
    $stmt->execute();
    $types_result = $stmt->get_result();
    $stmt->close();
} else {
    $types_result = false;
    $error_message = "Gagal mengambil data jenis Matpro: " . $mysqli->error;
}

// Ambil daftar proyek untuk filter
$projects_for_filter = $mysqli->query("SELECT id, project_name FROM matpro_projects ORDER BY project_name");
// Ambil daftar brand unik untuk filter
$brands_for_filter = $mysqli->query("SELECT DISTINCT brand FROM matpro_projects WHERE brand IS NOT NULL ORDER BY brand");
?>
$app_name = get_setting($mysqli, 'app_name');

// Ambil daftar proyek untuk filter
$projects_for_filter = $mysqli->query("SELECT id, project_name FROM matpro_projects ORDER BY project_name");
// Ambil daftar brand unik untuk filter
$brands_for_filter = $mysqli->query("SELECT DISTINCT brand FROM matpro_projects WHERE brand IS NOT NULL ORDER BY brand");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Jenis Matpro - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Jenis Matpro</h2>
                        <p class="text-slate-500 font-medium text-sm">Klasifikasi material promosi berdasarkan kategori proyek.</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <a href="admin_matpro_type_form.php" class="px-5 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all flex items-center gap-2">
                        <i class="fas fa-plus"></i> Tambah Jenis
                    </a>
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

                <!-- Filter Card -->
                <div class="glass-card p-6 border border-white/50 shadow-xl shadow-blue-900/5">
                    <form action="admin_manage_matpro_types.php" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-6 items-end">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Cari Jenis</label>
                            <input type="text" name="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" placeholder="Nama jenis..." 
                                   class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Proyek</label>
                            <select name="project_id" class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none">
                                <option value="">Semua Proyek</option>
                                <?php if ($projects_for_filter) { mysqli_data_seek($projects_for_filter, 0); while($project = $projects_for_filter->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($project['id']); ?>" <?php if ($project_filter == $project['id']) echo 'selected'; ?>><?php echo htmlspecialchars($project['project_name']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Brand</label>
                            <select name="brand" class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none">
                                <option value="">Semua Brand</option>
                                <?php if ($brands_for_filter) { mysqli_data_seek($brands_for_filter, 0); while($brand = $brands_for_filter->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($brand['brand']); ?>" <?php if ($brand_filter == $brand['brand']) echo 'selected'; ?>><?php echo htmlspecialchars($brand['brand']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Status</label>
                            <select name="status" class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none">
                                <option value="all" <?php if($status_filter == 'all') echo 'selected'; ?>>Semua Status</option>
                                <option value="active" <?php if($status_filter == 'active') echo 'selected'; ?>>Aktif</option>
                                <option value="inactive" <?php if($status_filter == 'inactive') echo 'selected'; ?>>Tidak Aktif</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="flex-grow bg-slate-800 text-white font-bold py-3 px-4 rounded-2xl hover:bg-slate-900 transition-all text-sm shadow-lg shadow-slate-200 flex items-center justify-center gap-2">
                                <i class="fas fa-filter"></i> Terapkan
                            </button>
                            <a href="admin_manage_matpro_types.php" class="bg-white border border-slate-200 text-slate-600 font-bold py-3 px-6 rounded-2xl hover:bg-slate-50 transition-all text-sm flex items-center justify-center">
                                <i class="fas fa-undo"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Table Section -->
                <form id="bulkDeleteForm" action="process/admin_matpro_type_process.php" method="POST">
                    <input type="hidden" name="action" value="bulk_delete">
                    
                    <div class="flex flex-col md:flex-row justify-between items-center mb-6 px-1 gap-4">
                        <div class="flex items-center gap-4 text-sm font-medium text-slate-500 bg-white/50 backdrop-blur-md rounded-2xl p-2 border border-white/50 shadow-sm">
                            <select onchange="window.location.href = 'admin_manage_matpro_types.php?page=1<?php echo str_replace(['&limit='.$records_per_page, '&page='.$page], '', $filter_query_string); ?>&limit=' + this.value" 
                                    class="bg-transparent border-none focus:ring-0 font-bold text-slate-800 cursor-pointer">
                                <option value="10" <?php if($records_per_page == 10) echo 'selected'; ?>>10</option>
                                <option value="25" <?php if($records_per_page == 25) echo 'selected'; ?>>25</option>
                                <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50</option>
                                <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100</option>
                            </select>
                            <span class="pr-4 border-l border-slate-200 pl-4">Menampilkan <strong class="text-slate-800"><?php echo $total_records; ?></strong> total data</span>
                        </div>

                        <button type="submit" id="bulkDeleteBtn" disabled 
                                class="bg-red-50 text-red-600 border border-red-100 font-black text-[10px] uppercase tracking-widest px-6 py-4 rounded-2xl hover:bg-red-600 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-all shadow-sm"
                                onclick="return confirm('Apakah Anda yakin ingin menghapus jenis Matpro yang dipilih? Ini juga akan menghapus semua stok terkait!');">
                            <i class="fas fa-trash-alt mr-2"></i> Hapus Terpilih
                        </button>
                    </div>

                    <div class="glass-card rounded-3xl border border-white/50 overflow-hidden shadow-xl shadow-blue-900/5">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50/50 border-b border-slate-100">
                                        <th class="py-5 px-6 w-12 text-center">
                                            <input type="checkbox" id="selectAllTypes" class="w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 transition-all">
                                        </th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Nama Jenis</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Proyek</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white/30 backdrop-blur-sm">
                                    <?php if ($types_result && $types_result->num_rows > 0): ?>
                                        <?php while($type = $types_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors group">
                                            <td class="py-5 px-6 text-center">
                                                <input type="checkbox" name="type_ids[]" value="<?php echo $type['id']; ?>" class="type-checkbox w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 transition-all">
                                            </td>
                                            <td class="py-5 px-6">
                                                <p class="font-bold text-slate-800"><?php echo htmlspecialchars($type['type_name']); ?></p>
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs font-bold text-slate-600"><?php echo htmlspecialchars($type['project_name']); ?></span>
                                                    <span class="px-2 py-0.5 bg-blue-50 text-blue-600 rounded text-[9px] font-black uppercase tracking-tighter border border-blue-100"><?php echo htmlspecialchars($type['brand']); ?></span>
                                                </div>
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="flex justify-center">
                                                    <?php if($type['is_active']): ?>
                                                        <span class="px-3 py-1 bg-emerald-50 text-emerald-600 rounded-full text-[10px] font-black uppercase tracking-widest border border-emerald-100">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="px-3 py-1 bg-slate-100 text-slate-400 rounded-full text-[10px] font-black uppercase tracking-widest border border-slate-200">Non-Aktif</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="flex items-center justify-center gap-2">
                                                    <a href="admin_matpro_type_form.php?id=<?php echo $type['id']; ?>" 
                                                       class="h-9 w-9 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 border border-amber-100 hover:bg-amber-600 hover:text-white transition-all shadow-sm"
                                                       title="Edit">
                                                        <i class="fas fa-pencil-alt text-sm"></i>
                                                    </a>
                                                    <button type="button" onclick="deleteSingle(<?php echo $type['id']; ?>)" 
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
                                            <td colspan="5" class="py-12 text-center text-slate-400 font-bold text-sm">Tidak ada data jenis Matpro.</td>
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
                                echo '<a href="?page='.($page-1).$filter_query_string.'" class="h-10 px-4 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold"><i class="fas fa-chevron-left"></i></a>';
                            }

                            if ($start_page > 1) {
                                echo '<a href="?page=1'.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold">1</a>';
                                if ($start_page > 2) echo '<span class="h-10 w-10 flex items-center justify-center text-slate-400">...</span>';
                            }

                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $active = ($i == $page) ? 'bg-slate-800 text-white shadow-lg shadow-slate-200' : 'glass-card text-slate-600 hover:bg-slate-800 hover:text-white';
                                echo '<a href="?page='.$i.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center rounded-xl '.$active.' transition-all text-sm font-bold">'.$i.'</a>';
                            }

                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) echo '<span class="h-10 w-10 flex items-center justify-center text-slate-400">...</span>';
                                echo '<a href="?page='.$total_pages.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold">'.$total_pages.'</a>';
                            }

                            if ($page < $total_pages) {
                                echo '<a href="?page='.($page+1).$filter_query_string.'" class="h-10 px-4 flex items-center justify-center rounded-xl glass-card text-slate-600 hover:bg-slate-800 hover:text-white transition-all text-sm font-bold"><i class="fas fa-chevron-right"></i></a>';
                            }
                        ?>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllTypesCheckbox = document.getElementById('selectAllTypes');
    const typeCheckboxes = document.querySelectorAll('.type-checkbox');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

    function updateBulkDeleteButton() {
        const checkedCount = document.querySelectorAll('.type-checkbox:checked').length;
        bulkDeleteBtn.disabled = checkedCount === 0;
        if(checkedCount > 0) {
            bulkDeleteBtn.classList.add('bg-red-600', 'text-white');
            bulkDeleteBtn.classList.remove('bg-red-50', 'text-red-600');
        } else {
            bulkDeleteBtn.classList.remove('bg-red-600', 'text-white');
            bulkDeleteBtn.classList.add('bg-red-50', 'text-red-600');
        }
    }

    selectAllTypesCheckbox.addEventListener('change', function() {
        typeCheckboxes.forEach(checkbox => { checkbox.checked = this.checked; });
        updateBulkDeleteButton();
    });

    typeCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            selectAllTypesCheckbox.checked = document.querySelectorAll('.type-checkbox:checked').length === typeCheckboxes.length;
            updateBulkDeleteButton();
        });
    });

    updateBulkDeleteButton();
});

function deleteSingle(id) {
    if (confirm('Apakah Anda yakin ingin menghapus jenis Matpro ini? Ini juga akan menghapus semua stok terkait! Aksi ini tidak bisa dibatalkan.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'process/admin_matpro_type_process.php';
        form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>
