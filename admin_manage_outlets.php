<?php
// admin_manage_outlets.php
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
$export_query_string = "";

$search_keyword = $_GET['keyword'] ?? '';
$brand_filter = $_GET['brand'] ?? '';
$branch_filter = $_GET['branch_id'] ?? '';
$mc_filter = $_GET['mc_id'] ?? '';

// Filter pencarian
if (!empty($search_keyword)) {
    $where_clauses[] = "(o.id_outlet LIKE ? OR o.nama_outlet LIKE ? OR s.site_name LIKE ?)";
    $param_types .= "sss";
    $keyword_like = "%" . $search_keyword . "%";
    $param_values[] = $keyword_like;
    $param_values[] = $keyword_like;
    $param_values[] = $keyword_like;
    $filter_query_string .= "&keyword=" . urlencode($search_keyword);
    $export_query_string  .= "&keyword=" . urlencode($search_keyword);
}
if (!empty($brand_filter)) {
    // PERBAIKAN: Logika filter disederhanakan karena 'BOTH' sudah dihapus
    $where_clauses[] = "o.brand = ?";
    $param_types .= "s";
    $param_values[] = $brand_filter;
    $filter_query_string .= "&brand=" . urlencode($brand_filter);
    $export_query_string  .= "&brand=" . urlencode($brand_filter);
}
if (!empty($branch_filter)) {
    $where_clauses[] = "s.branch_id = ?";
    $param_types .= "i";
    $param_values[] = (int)$branch_filter;
    $filter_query_string .= "&branch_id=" . urlencode($branch_filter);
    $export_query_string  .= "&branch_id=" . urlencode($branch_filter);
}
if (!empty($mc_filter)) {
    $where_clauses[] = "s.micro_cluster_id = ?";
    $param_types .= "i";
    $param_values[] = (int)$mc_filter;
    $filter_query_string .= "&mc_id=" . urlencode($mc_filter);
    $export_query_string  .= "&mc_id=" . urlencode($mc_filter);
}

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

// Query untuk menghitung total data
$count_sql = "SELECT COUNT(o.id) as total 
              FROM outlets o 
              LEFT JOIN sites s ON o.site_id = s.id 
              LEFT JOIN branches b ON s.branch_id = b.id 
              LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id" . $where_sql;
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
    $error_message = "Gagal menghitung total outlet: " . $mysqli->error;
}
$total_pages = ceil($total_records / $records_per_page);

// Query untuk mengambil data outlet
$sql = "SELECT o.id, o.id_outlet, o.nama_outlet, o.Id_Outlet_Nama_Outlet, o.brand,
               s.site_name, s.site_id as site_code, s.kecamatan, s.kabupaten,
               b.nama_branch, mc.nama_micro_cluster
        FROM outlets o 
        LEFT JOIN sites s ON o.site_id = s.id
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id" 
        . $where_sql . " ORDER BY o.nama_outlet ASC LIMIT ? OFFSET ?";
$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param($param_types_page, ...$param_values_page);
    $stmt->execute();
    $outlets_result = $stmt->get_result();
    $stmt->close();
} else {
    $outlets_result = false;
    $error_message = "Gagal mengambil data outlet: " . $mysqli->error;
}

// PERBAIKAN: Hanya tampilkan brand '3ID' dan 'IM3' pada filter
$brands_for_filter = ['3ID', 'IM3'];

// Ambil daftar branch dan micro cluster yang sudah dipilih jika ada
$initial_branches_query = null;
if (!empty($brand_filter)) {
    $stmt_branches = $mysqli->prepare("SELECT id, nama_branch FROM branches WHERE brand = ? ORDER BY nama_branch");
    if ($stmt_branches) {
        $stmt_branches->bind_param("s", $brand_filter);
        $stmt_branches->execute();
        $initial_branches_query = $stmt_branches->get_result();
        $stmt_branches->close();
    }
}

$initial_mcs_query = null;
if (!empty($branch_filter)) {
    $stmt_mcs = $mysqli->prepare("SELECT id, nama_micro_cluster FROM micro_clusters WHERE branch_id = ? ORDER BY nama_micro_cluster");
    if ($stmt_mcs) {
        // PERBAIKAN: Gunakan variabel untuk bind_param agar tidak error
        $branch_filter_int = (int)$branch_filter;
        $stmt_mcs->bind_param("i", $branch_filter_int);
        $stmt_mcs->execute();
        $initial_mcs_query = $stmt_mcs->get_result();
        $stmt_mcs->close();
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Manajemen Outlet - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Outlet</h2>
                        <p class="text-slate-500 font-medium text-sm">Kelola data outlet dan klasifikasi wilayah.</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <a href="process/export_outlets_csv.php?<?php echo htmlspecialchars(ltrim($export_query_string, '&')); ?>" 
                       class="px-5 py-3 bg-emerald-600 text-white font-bold text-sm rounded-2xl hover:bg-emerald-700 shadow-lg shadow-emerald-200 active:scale-95 transition-all flex items-center gap-2">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                    <a href="admin_import_outlets.php" class="px-5 py-3 bg-white/50 backdrop-blur-md border border-slate-200 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-file-import"></i> Import Outlet
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
                    <form action="admin_manage_outlets.php" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-6 items-end">
                        <div class="space-y-2 lg:col-span-2 xl:col-span-1">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Cari ID/Nama/Site</label>
                            <input type="text" name="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" placeholder="Ketik sesuatu..." 
                                   class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Brand</label>
                            <select name="brand" id="brand_filter" class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none">
                                <option value="">Semua Brand</option>
                                <?php foreach ($brands_for_filter as $brand_option): ?>
                                    <option value="<?php echo htmlspecialchars($brand_option); ?>" <?php if ($brand_filter == $brand_option) echo 'selected'; ?>><?php echo htmlspecialchars($brand_option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Branch</label>
                            <select name="branch_id" id="branch_filter" <?php if(empty($brand_filter)) echo 'disabled'; ?>
                                    class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none disabled:opacity-50">
                                <option value=""><?php echo empty($brand_filter) ? 'Pilih Brand dulu' : 'Pilih Branch'; ?></option>
                                <?php if ($initial_branches_query) { mysqli_data_seek($initial_branches_query, 0); while($branch = $initial_branches_query->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($branch['id']); ?>" <?php if ($branch_filter == $branch['id']) echo 'selected'; ?>><?php echo htmlspecialchars($branch['nama_branch']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Micro Cluster</label>
                            <select name="mc_id" id="mc_filter" <?php if(empty($branch_filter)) echo 'disabled'; ?>
                                    class="w-full bg-white/50 border border-slate-200 rounded-2xl py-3 px-4 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all text-sm font-medium appearance-none disabled:opacity-50">
                                <option value=""><?php echo empty($branch_filter) ? 'Pilih Branch dulu' : 'Pilih Micro Cluster'; ?></option>
                                <?php if ($initial_mcs_query) { mysqli_data_seek($initial_mcs_query, 0); while($mc = $initial_mcs_query->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($mc['id']); ?>" <?php if ($mc_filter == $mc['id']) echo 'selected'; ?>><?php echo htmlspecialchars($mc['nama_micro_cluster']); ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="flex-grow bg-slate-800 text-white font-bold py-3 px-4 rounded-2xl hover:bg-slate-900 transition-all text-sm shadow-lg shadow-slate-200 flex items-center justify-center gap-2">
                                <i class="fas fa-filter"></i> Terapkan
                            </button>
                            <a href="admin_manage_outlets.php" class="bg-white border border-slate-200 text-slate-600 font-bold py-3 px-6 rounded-2xl hover:bg-slate-50 transition-all text-sm flex items-center justify-center">
                                <i class="fas fa-undo"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Table Section -->
                <form id="bulkDeleteForm" action="process/admin_outlet_process.php" method="POST">
                    <input type="hidden" name="action" value="bulk_delete">
                    
                    <div class="flex flex-col md:flex-row justify-between items-center mb-6 px-1 gap-4">
                        <div class="flex items-center gap-4 text-sm font-medium text-slate-500 bg-white/50 backdrop-blur-md rounded-2xl p-2 border border-white/50 shadow-sm">
                            <select onchange="window.location.href = 'admin_manage_outlets.php?page=1<?php echo str_replace(['&limit='.$records_per_page, '&page='.$page], '', $filter_query_string); ?>&limit=' + this.value" 
                                    class="bg-transparent border-none focus:ring-0 font-bold text-slate-800 cursor-pointer">
                                <option value="10" <?php if($records_per_page == 10) echo 'selected'; ?>>10</option>
                                <option value="25" <?php if($records_per_page == 25) echo 'selected'; ?>>25</option>
                                <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50</option>
                                <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100</option>
                            </select>
                            <span class="pr-4 border-l border-slate-200 pl-4">Menampilkan <strong class="text-slate-800"><?php echo $total_records; ?></strong> total data</span>
                        </div>

                        <div class="flex gap-2">
                            <button type="submit" id="bulkDeleteBtn" disabled 
                                    class="bg-red-50 text-red-600 border border-red-100 font-black text-[10px] uppercase tracking-widest px-6 py-4 rounded-2xl hover:bg-red-600 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-all shadow-sm"
                                    onclick="return confirm('Apakah Anda yakin ingin menghapus outlet yang dipilih? Ini juga akan menghapus semua aktivitas Matpro terkait!');">
                                <i class="fas fa-trash-alt mr-2"></i> Hapus Terpilih
                            </button>
                            <button type="button" onclick="deleteAllOutlets()" 
                                    class="bg-white border border-red-200 text-red-500 font-black text-[10px] uppercase tracking-widest px-6 py-4 rounded-2xl hover:bg-red-50 transition-all shadow-sm">
                                <i class="fas fa-dumpster mr-2"></i> Hapus Semua
                            </button>
                        </div>
                    </div>

                    <div class="glass-card rounded-3xl border border-white/50 overflow-hidden shadow-xl shadow-blue-900/5">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50/50 border-b border-slate-100">
                                        <th class="py-5 px-6 w-12 text-center">
                                            <input type="checkbox" id="selectAllOutlets" class="w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 transition-all">
                                        </th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">ID & Nama Outlet</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Wilayah (Branch/MC)</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Site & Lokasi</th>
                                        <th class="py-5 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white/30 backdrop-blur-sm">
                                    <?php if ($outlets_result && $outlets_result->num_rows > 0): ?>
                                        <?php while($outlet = $outlets_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors group">
                                            <td class="py-5 px-6 text-center">
                                                <input type="checkbox" name="outlet_ids[]" value="<?php echo $outlet['id']; ?>" class="outlet-checkbox w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 transition-all">
                                            </td>
                                            <td class="py-5 px-6">
                                                <p class="font-bold text-slate-800 line-clamp-1"><?php echo htmlspecialchars($outlet['nama_outlet']); ?></p>
                                                <div class="flex items-center gap-2 mt-1">
                                                    <span class="text-xs font-medium text-slate-400"><?php echo htmlspecialchars($outlet['id_outlet']); ?></span>
                                                    <span class="px-2 py-0.5 bg-blue-50 text-blue-600 rounded text-[9px] font-black uppercase tracking-tighter border border-blue-100"><?php echo htmlspecialchars($outlet['brand'] ?? '-'); ?></span>
                                                </div>
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="space-y-1">
                                                    <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($outlet['nama_branch'] ?? '-'); ?></p>
                                                    <p class="text-[10px] font-medium text-slate-400 flex items-center gap-1"><i class="fas fa-map-marker-alt text-[8px]"></i> <?php echo htmlspecialchars($outlet['nama_micro_cluster'] ?? '-'); ?></p>
                                                </div>
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="space-y-1">
                                                    <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($outlet['site_name'] ?? '-'); ?> <span class="text-[10px] font-normal text-slate-400">(<?php echo htmlspecialchars($outlet['site_code'] ?? '-'); ?>)</span></p>
                                                    <p class="text-[10px] font-medium text-slate-400 uppercase tracking-tighter"><?php echo htmlspecialchars($outlet['kecamatan'] ?? '-'); ?> | <?php echo htmlspecialchars($outlet['kabupaten'] ?? '-'); ?></p>
                                                </div>
                                            </td>
                                            <td class="py-5 px-6">
                                                <div class="flex items-center justify-center gap-2">
                                                    <a href="admin_outlet_form.php?id=<?php echo $outlet['id']; ?>" 
                                                       class="h-9 w-9 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 border border-amber-100 hover:bg-amber-600 hover:text-white transition-all shadow-sm"
                                                       title="Edit">
                                                        <i class="fas fa-pencil-alt text-sm"></i>
                                                    </a>
                                                    <button type="button" onclick="deleteSingle(<?php echo $outlet['id']; ?>)" 
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
                                            <td colspan="5" class="py-12 text-center text-slate-400 font-bold text-sm">Tidak ada data outlet.</td>
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
    const selectAllOutletsCheckbox = document.getElementById('selectAllOutlets');
    const outletCheckboxes = document.querySelectorAll('.outlet-checkbox');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

    function updateBulkDeleteButton() {
        const checkedCount = document.querySelectorAll('.outlet-checkbox:checked').length;
        bulkDeleteBtn.disabled = checkedCount === 0;
        if(checkedCount > 0) {
            bulkDeleteBtn.classList.add('bg-red-600', 'text-white');
            bulkDeleteBtn.classList.remove('bg-red-50', 'text-red-600');
        } else {
            bulkDeleteBtn.classList.remove('bg-red-600', 'text-white');
            bulkDeleteBtn.classList.add('bg-red-50', 'text-red-600');
        }
    }

    selectAllOutletsCheckbox.addEventListener('change', function(e) {
        outletCheckboxes.forEach(checkbox => { checkbox.checked = e.target.checked; });
        updateBulkDeleteButton();
    });

    outletCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            selectAllOutletsCheckbox.checked = document.querySelectorAll('.outlet-checkbox:checked').length === outletCheckboxes.length;
            updateBulkDeleteButton();
        });
    });

    updateBulkDeleteButton();

    // Cascading Filters Logic
    const brandFilter = document.getElementById('brand_filter');
    const branchFilter = document.getElementById('branch_filter');
    const mcFilter = document.getElementById('mc_filter');

    function loadOptions(url, selectElement, prompt, selectedValue = null) {
        const originalText = selectElement.options[0].text;
        selectElement.options[0].text = 'Memuat...';
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                selectElement.innerHTML = `<option value="">${prompt}</option>`;
                data.forEach(item => {
                    const option = new Option(item.nama_branch || item.nama_micro_cluster, item.id);
                    selectElement.add(option);
                });
                if (selectedValue) {
                    selectElement.value = selectedValue;
                }
                selectElement.disabled = false;
            })
            .catch(error => {
                console.error('Error fetching options:', error);
                selectElement.innerHTML = `<option value="">Gagal memuat</option>`;
                selectElement.disabled = true;
            });
    }

    brandFilter.addEventListener('change', () => {
        branchFilter.innerHTML = '<option value="">Pilih Brand dulu</option>';
        branchFilter.disabled = true;
        mcFilter.innerHTML = '<option value="">Pilih Branch dulu</option>';
        mcFilter.disabled = true;
        
        if(brandFilter.value) {
            loadOptions(`api_helper.php?action=get_branches_by_brand&brand=${brandFilter.value}`, branchFilter, 'Semua Branch');
        }
    });

    branchFilter.addEventListener('change', () => {
        mcFilter.innerHTML = '<option value="">Pilih Branch dulu</option>';
        mcFilter.disabled = true;
        if(branchFilter.value) {
            loadOptions(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${branchFilter.value}`, mcFilter, 'Semua Micro Cluster');
        }
    });
    
    // Initial load if filters are pre-selected
    const initialBrand = '<?php echo $brand_filter; ?>';
    const initialBranch = '<?php echo $branch_filter; ?>';
    const initialMc = '<?php echo $mc_filter; ?>';

    if (initialBrand) {
        // No need to load anything if it's already rendered on server side for initial view
        // But for consistency and ensuring the dropdowns are active:
        branchFilter.disabled = false;
    }
    if (initialBranch) {
        mcFilter.disabled = false;
    }
});

function deleteSingle(id) {
    if (confirm('Apakah Anda yakin ingin menghapus outlet ini? Ini juga akan menghapus semua aktivitas Matpro terkait! Aksi ini tidak bisa dibatalkan.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'process/admin_outlet_process.php';
        form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteAllOutlets() {
    if (confirm('PERINGATAN! Aksi ini tidak dapat dibatalkan. Apakah Anda benar-benar yakin ingin menghapus SEMUA data outlet? Ini juga akan menghapus semua aktivitas Matpro terkait!')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'process/admin_outlet_process.php';
        form.innerHTML = `<input type="hidden" name="action" value="delete_all_outlets">`;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>
