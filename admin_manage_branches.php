<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}
// Query untuk mengambil semua data branch
$sql = "SELECT id, nama_branch, brand FROM branches ORDER BY brand, nama_branch";
$branches_result = $mysqli->query($sql);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Manajemen Branch - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Manajemen Branch</h2>
                        <p class="text-slate-500 font-medium">Kelola data kantor cabang dan brand yang beroperasi di wilayah Anda.</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <a href="admin_import_branches.php" class="px-5 py-3 bg-white/50 backdrop-blur-md border border-slate-200 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-file-excel"></i> Import Branch
                    </a>
                    <a href="admin_branch_form.php" class="px-5 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all flex items-center gap-2">
                        <i class="fas fa-plus"></i> Tambah Branch
                    </a>
                </div>
            </header>

            <div class="max-w-5xl mx-auto">
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

                <div class="glass-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50/50">
                                <tr>
                                    <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest w-20">ID</th>
                                    <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Nama Branch</th>
                                    <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Brand</th>
                                    <th class="py-4 px-6 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white/30">
                                <?php if ($branches_result && $branches_result->num_rows > 0): ?>
                                    <?php while($branch = $branches_result->fetch_assoc()): ?>
                                    <tr class="hover:bg-blue-50/30 transition-colors">
                                        <td class="py-4 px-6 whitespace-nowrap">
                                            <span class="text-xs font-black text-slate-400">#<?php echo $branch['id']; ?></span>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="text-sm font-extrabold text-slate-800"><?php echo htmlspecialchars($branch['nama_branch']); ?></span>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 bg-slate-100 text-slate-600 rounded-lg">
                                                <?php echo htmlspecialchars($branch['brand']); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-6 text-center">
                                            <div class="flex justify-center items-center gap-3">
                                                <a href="admin_branch_form.php?id=<?php echo $branch['id']; ?>" 
                                                   class="h-9 w-9 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 border border-amber-100 hover:bg-amber-100 transition-all" 
                                                   title="Edit Branch">
                                                    <i class="fas fa-pencil-alt text-sm"></i>
                                                </a>
                                                <button type="button" 
                                                        class="h-9 w-9 flex items-center justify-center rounded-xl bg-red-50 text-red-600 border border-red-100 hover:bg-red-600 hover:text-white transition-all" 
                                                        onclick="showDeleteModal(<?php echo $branch['id']; ?>)"
                                                        title="Hapus Branch">
                                                    <i class="fas fa-trash-alt text-sm"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="py-12 text-center">
                                            <div class="flex flex-col items-center gap-4">
                                                <div class="h-16 w-16 bg-slate-50 text-slate-300 rounded-2xl flex items-center justify-center text-2xl">
                                                    <i class="fas fa-code-branch"></i>
                                                </div>
                                                <p class="text-slate-400 font-bold text-sm">Tidak ada data branch yang terdaftar.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Premium Delete Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center z-[200] p-4">
        <div class="glass-card max-w-sm w-full p-8 text-center transform scale-95 opacity-0 transition-all duration-300" id="deleteModalContent">
            <div class="h-16 w-16 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-6">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="text-xl font-extrabold text-slate-800 mb-2">Hapus Branch?</h3>
            <p class="text-sm font-medium text-slate-500 mb-8 leading-relaxed">PENTING: Menghapus branch juga akan menghapus semua micro cluster dan site di bawahnya secara permanen.</p>
            
            <div class="flex gap-3">
                <button id="cancelDelete" class="flex-1 py-3 bg-slate-100 text-slate-600 font-bold text-sm rounded-xl hover:bg-slate-200 transition-all">Batal</button>
                <form id="deleteForm" action="process/admin_branch_process.php" method="POST" class="flex-1">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteBranchId">
                    <button type="submit" class="w-full py-3 bg-red-600 text-white font-bold text-sm rounded-xl hover:bg-red-700 shadow-lg shadow-red-200 active:scale-95 transition-all">Hapus</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const deleteModal = document.getElementById('deleteModal');
        const deleteModalContent = document.getElementById('deleteModalContent');
        const cancelDeleteButton = document.getElementById('cancelDelete');
        const deleteBranchIdInput = document.getElementById('deleteBranchId');

        function showDeleteModal(branchId) {
            deleteBranchIdInput.value = branchId;
            deleteModal.classList.remove('hidden');
            deleteModal.classList.add('flex');
            setTimeout(() => {
                deleteModalContent.classList.remove('scale-95', 'opacity-0');
                deleteModalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        function closeDeleteModal() {
            deleteModalContent.classList.remove('scale-100', 'opacity-100');
            deleteModalContent.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                deleteModal.classList.add('hidden');
                deleteModal.classList.remove('flex');
            }, 300);
        }

        cancelDeleteButton.onclick = closeDeleteModal;
        document.getElementById('sidebarOverlay').addEventListener('click', toggleSidebar);
    </script>
</body>
</html>
