<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}
$current_admin_id = $_SESSION['id'];

// --- Logika Filter & Urutan ---
$where_clauses = [];
$param_types = "";
$param_values = [];
$filter_query_string = "";

$search_keyword = $_GET['keyword'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'id_desc';
$brand_filter = $_GET['brand'] ?? '';

// Filter pencarian
if (!empty($search_keyword)) {
    $where_clauses[] = "(u.nama LIKE ? OR u.username LIKE ?)";
    $param_types .= "ss";
    $keyword_like = "%" . $search_keyword . "%";
    $param_values[] = $keyword_like;
    $param_values[] = $keyword_like;
    $filter_query_string .= "&keyword=" . urlencode($search_keyword);
}

// Filter Brand
if (!empty($brand_filter)) {
    $where_clauses[] = "u.brand = ?";
    $param_types .= "s";
    $param_values[] = $brand_filter;
    $filter_query_string .= "&brand=" . urlencode($brand_filter);
}

// Logika Urutan
$allowed_sorts = [
    'id_desc' => 'u.id DESC',
    'nama_asc' => 'u.nama ASC',
    'nama_desc' => 'u.nama DESC',
    'username_asc' => 'u.username ASC',
    'username_desc' => 'u.username DESC'
];
$order_by_sql = $allowed_sorts[$sort_by] ?? 'u.id DESC';
$filter_query_string .= "&sort_by=" . urlencode($sort_by);

$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
}

// Query untuk mengambil data user
$sql = "SELECT 
            u.id, u.nama, u.username, u.role, u.brand, b.nama_branch,
            (SELECT GROUP_CONCAT(mc.nama_micro_cluster SEPARATOR ', ') 
             FROM user_micro_clusters umc 
             JOIN micro_clusters mc ON umc.micro_cluster_id = mc.id 
             WHERE umc.user_id = u.id) AS micro_clusters_list
        FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        $where_sql
        ORDER BY $order_by_sql";

$stmt = $mysqli->prepare($sql);
if (!empty($param_values)) {
    $stmt->bind_param($param_types, ...$param_values);
}
$stmt->execute();
$users_result = $stmt->get_result();
if ($users_result === false) { die("Error executing query: " . $mysqli->error); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Manajemen User - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Manajemen User</h2>
                        <p class="text-slate-500 font-medium">Kelola akses, peran, dan alokasi wilayah untuk seluruh pengguna sistem.</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <a href="process/export_users_csv.php?<?php echo ltrim($filter_query_string, '&'); ?>" class="px-5 py-3 bg-white/50 backdrop-blur-md border border-slate-200 text-emerald-600 font-bold text-sm rounded-2xl hover:bg-emerald-50 hover:border-emerald-200 transition-all flex items-center gap-2">
                        <i class="fas fa-file-excel"></i> Export Data
                    </a>
                    <a href="admin_import_users.php" class="px-5 py-3 bg-white/50 backdrop-blur-md border border-slate-200 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-file-import"></i> Import User
                    </a>
                    <a href="admin_user_form.php" class="px-5 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all flex items-center gap-2">
                        <i class="fas fa-plus"></i> Tambah User
                    </a>
                </div>
            </header>

            <div class="max-w-7xl mx-auto">
                <!-- Advanced Filter Card -->
                <div class="glass-card p-8 mb-10">
                    <div class="flex items-center gap-3 mb-8">
                        <div class="h-10 w-4 bg-blue-600 rounded-full"></div>
                        <h3 class="text-xl font-extrabold text-slate-800 tracking-tight">Filter Pengguna</h3>
                    </div>

                    <form action="admin_manage_users.php" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 items-end">
                        <div class="space-y-2">
                            <label for="keyword" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Cari Nama/Username</label>
                            <div class="relative group">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                    <i class="fas fa-search"></i>
                                </span>
                                <input type="text" name="keyword" id="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" 
                                       placeholder="Ketik kata kunci..."
                                       class="w-full bg-slate-50 border border-slate-200 rounded-2xl pl-12 pr-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all placeholder:text-slate-400">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label for="brand" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Brand</label>
                            <div class="relative">
                                <select name="brand" id="brand" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                    <option value="">Semua Brand</option>
                                    <option value="IM3" <?php if($brand_filter == 'IM3') echo 'selected'; ?>>IM3</option>
                                    <option value="3ID" <?php if($brand_filter == '3ID') echo 'selected'; ?>>3ID</option>
                                </select>
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </span>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label for="sort_by" class="text-xs font-black text-slate-400 uppercase tracking-widest px-1">Urutkan Berdasarkan</label>
                            <div class="relative">
                                <select name="sort_by" id="sort_by" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                    <option value="id_desc" <?php if($sort_by == 'id_desc') echo 'selected'; ?>>Pendaftaran Terbaru</option>
                                    <option value="nama_asc" <?php if($sort_by == 'nama_asc') echo 'selected'; ?>>Nama (A-Z)</option>
                                    <option value="nama_desc" <?php if($sort_by == 'nama_desc') echo 'selected'; ?>>Nama (Z-A)</option>
                                    <option value="username_asc" <?php if($sort_by == 'username_asc') echo 'selected'; ?>>Username (A-Z)</option>
                                    <option value="username_desc" <?php if($sort_by == 'username_desc') echo 'selected'; ?>>Username (Z-A)</option>
                                </select>
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </span>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <a href="admin_manage_users.php" class="flex-1 py-3 bg-slate-100 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-200 transition-all text-center">
                                <i class="fas fa-undo mr-2"></i> Reset
                            </a>
                            <button type="submit" class="flex-[2] py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 active:scale-95 transition-all">
                                <i class="fas fa-filter mr-2"></i> Terapkan
                            </button>
                        </div>
                    </form>
                </div>

                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="glass-card bg-emerald-50/50 border-emerald-200 p-4 mb-8 flex items-center gap-3">
                        <div class="h-10 w-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle"></i></div>
                        <p class="text-emerald-800 font-bold"><?php echo htmlspecialchars($_SESSION['success_message']); ?></p>
                    </div>
                <?php unset($_SESSION['success_message']); endif; ?>

                <form action="process/admin_user_process.php" method="POST" id="bulk-delete-form">
                    <input type="hidden" name="action" value="bulk_delete">
                    
                    <div class="glass-card overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200">
                                <thead class="bg-slate-50/50">
                                    <tr>
                                        <th class="py-4 px-6 text-center w-12">
                                            <input type="checkbox" id="select-all" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                        </th>
                                        <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Informasi Pengguna</th>
                                        <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Username</th>
                                        <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Peran</th>
                                        <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Brand</th>
                                        <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Branch & Wilayah</th>
                                        <th class="text-center py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white/30">
                                    <?php if ($users_result->num_rows > 0): ?>
                                        <?php while($user = $users_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-blue-50/30 transition-colors">
                                            <td class="py-4 px-6 text-center">
                                                <?php if($user['role'] !== 'admin'): ?>
                                                    <input type="checkbox" name="user_ids[]" value="<?php echo $user['id']; ?>" class="user-checkbox rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-4 px-6">
                                                <div class="flex items-center gap-3">
                                                    <div class="h-10 w-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-500 font-bold border-2 border-white shadow-sm overflow-hidden">
                                                        <?php echo strtoupper(substr($user['nama'], 0, 1)); ?>
                                                    </div>
                                                    <span class="text-sm font-extrabold text-slate-800"><?php echo htmlspecialchars($user['nama']); ?></span>
                                                </div>
                                            </td>
                                            <td class="py-4 px-6">
                                                <span class="text-sm font-bold text-slate-600"><?php echo htmlspecialchars($user['username']); ?></span>
                                            </td>
                                            <td class="py-4 px-6">
                                                <?php 
                                                $role_color = ($user['role'] == 'admin') ? 'bg-indigo-50 text-indigo-600 border-indigo-100' : 'bg-blue-50 text-blue-600 border-blue-100';
                                                ?>
                                                <span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 <?php echo $role_color; ?> rounded-lg border">
                                                    <?php echo htmlspecialchars($user['role']); ?>
                                                </span>
                                            </td>
                                            <td class="py-4 px-6">
                                                <?php if(!empty($user['brand'])): ?>
                                                    <span class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($user['brand']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-sm font-medium text-slate-400 italic">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-4 px-6">
                                                <div class="space-y-1">
                                                    <p class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($user['nama_branch'] ?? '-'); ?></p>
                                                    <p class="text-[10px] font-medium text-slate-400 line-clamp-1 italic"><?php echo htmlspecialchars($user['micro_clusters_list'] ?? '-'); ?></p>
                                                </div>
                                            </td>
                                            <td class="py-4 px-6">
                                                <div class="flex justify-center items-center gap-3">
                                                    <a href="admin_user_form.php?id=<?php echo $user['id']; ?>" 
                                                       class="h-9 w-9 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 border border-amber-100 hover:bg-amber-100 transition-all" 
                                                       title="Edit User">
                                                        <i class="fas fa-pencil-alt text-sm"></i>
                                                    </a>
                                                    <?php if($user['role'] !== 'admin' || $user['id'] != $current_admin_id): ?>
                                                        <button type="button" 
                                                                onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                                                class="h-9 w-9 flex items-center justify-center rounded-xl bg-blue-50 text-blue-600 border border-blue-100 hover:bg-blue-100 transition-all" 
                                                                title="Reset Password">
                                                            <i class="fas fa-key text-sm"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="py-12 text-center">
                                                <div class="flex flex-col items-center gap-4">
                                                    <div class="h-16 w-16 bg-slate-50 text-slate-300 rounded-2xl flex items-center justify-center text-2xl">
                                                        <i class="fas fa-users-slash"></i>
                                                    </div>
                                                    <p class="text-slate-400 font-bold text-sm">Tidak ada data user yang cocok dengan filter.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-8">
                        <button type="submit" class="px-6 py-3 bg-red-50 text-red-600 border border-red-100 font-black text-xs uppercase tracking-widest rounded-2xl hover:bg-red-600 hover:text-white hover:shadow-lg hover:shadow-red-200 transition-all flex items-center gap-2">
                            <i class="fas fa-trash-alt"></i> Hapus yang Dipilih
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        document.getElementById('sidebarOverlay').addEventListener('click', toggleSidebar);

        document.getElementById('select-all').addEventListener('change', function(e) {
            document.querySelectorAll('.user-checkbox').forEach(function(checkbox) {
                checkbox.checked = e.target.checked;
            });
        });

        document.getElementById('bulk-delete-form').addEventListener('submit', function(e) {
            const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
            if (checkedCount === 0) {
                alert('Pilih setidaknya satu user untuk dihapus.');
                e.preventDefault();
                return;
            }
            if (!confirm(`Apakah Anda yakin ingin menghapus ${checkedCount} user yang dipilih?`)) {
                e.preventDefault();
            }
        });

        function resetPassword(userId, username) {
            if (confirm(`Apakah Anda yakin ingin me-reset password untuk user ${username} ke default (marcomm123)?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'process/admin_reset_user_password.php';
                
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                form.appendChild(userIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
