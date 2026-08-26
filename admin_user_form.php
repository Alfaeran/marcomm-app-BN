<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

// Mode: 'add' atau 'edit'
$mode = 'add';
$user_data = [
    'id' => '', 'nama' => '', 'username' => '', 'role' => 'user', 
    'brand' => '', 'branch_id' => ''
];
$assigned_mc_ids = []; // Untuk menyimpan ID MC yang sudah terpilih

// Jika ada ID di URL, masuk ke mode edit
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $mode = 'edit';
    $id = (int)$_GET['id'];
    $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $user_data = $result->fetch_assoc();
        // Ambil juga MC yang sudah terhubung dengan user ini
        $stmt_mc = $mysqli->prepare("SELECT micro_cluster_id FROM user_micro_clusters WHERE user_id = ?");
        $stmt_mc->bind_param("i", $id);
        $stmt_mc->execute();
        $result_mc = $stmt_mc->get_result();
        while($row = $result_mc->fetch_assoc()) {
            $assigned_mc_ids[] = (int)$row['micro_cluster_id'];
        }
        $stmt_mc->close();
    } else {
        header("location: admin_manage_users.php");
        exit;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> User</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-2xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <h1 class="text-2xl font-bold text-gray-800 mb-6"><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> User</h1>
    
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
        <p><?php echo $_SESSION['error_message']; ?></p>
    </div>
    <?php unset($_SESSION['error_message']); endif; ?>

    <form action="process/admin_user_process.php" method="POST">
        <input type="hidden" name="action" value="<?php echo $mode; ?>">
        <input type="hidden" name="id" value="<?php echo $user_data['id']; ?>">

        <div class="mb-4">
            <label for="nama" class="block text-gray-700 font-bold mb-2">Nama Lengkap</label>
            <input type="text" name="nama" id="nama" value="<?php echo htmlspecialchars($user_data['nama']); ?>" required class="w-full px-3 py-2 border rounded-lg">
        </div>
        <div class="mb-4">
            <label for="username" class="block text-gray-700 font-bold mb-2">Username</label>
            <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" required class="w-full px-3 py-2 border rounded-lg">
        </div>
        <div class="mb-4 relative">
            <label for="password" class="block text-gray-700 text-sm font-bold mb-2">Password</label>
            <input type="password" name="password" id="password" <?php if ($mode == 'add') echo 'required'; ?> class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            <span class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5 cursor-pointer" style="top: 1.375rem;" onclick="togglePasswordVisibility('password')">
                <i class="fa fa-eye" id="toggle-password-icon-password"></i>
            </span>
            <?php if ($mode == 'edit'): ?>
                <small class="text-gray-500">Kosongkan jika tidak ingin mengubah password.</small>
            <?php endif; ?>
        </div>
        <div class="mb-4">
            <label for="role" class="block text-gray-700 font-bold mb-2">Role</label>
            <select name="role" id="role" required class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="user" <?php if ($user_data['role'] == 'user') echo 'selected'; ?>>User</option>
                <option value="admin" <?php if ($user_data['role'] == 'admin') echo 'selected'; ?>>Admin</option>
            </select>
        </div>
        <div class="mb-4">
            <label for="brand" class="block text-gray-700 font-bold mb-2">Brand</label>
            <select name="brand" id="brand" class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="">-- Tidak Terikat Brand (Admin) --</option>
                <option value="IM3" <?php if ($user_data['brand'] == 'IM3') echo 'selected'; ?>>IM3</option>
                <option value="3ID" <?php if ($user_data['brand'] == '3ID') echo 'selected'; ?>>3ID</option>
            </select>
        </div>
        <div class="mb-4">
            <label for="branch_id" class="block text-gray-700 font-bold mb-2">Branch</label>
            <select name="branch_id" id="branch_id" class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="">-- Pilih Brand dulu --</option>
            </select>
        </div>
        <div class="mb-4">
            <label class="block text-gray-700 font-bold mb-2">Micro Cluster (Bisa pilih lebih dari satu)</label>
            <div id="micro-cluster-list" class="mt-2 p-4 border rounded-lg h-48 overflow-y-auto bg-gray-50">
                <p class="text-gray-500">Pilih Branch terlebih dahulu.</p>
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <a href="admin_manage_users.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">Batal</a>
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Simpan</button>
        </div>
    </form>
</div>

<script>
function togglePasswordVisibility(fieldId) {
    const passwordField = document.getElementById(fieldId);
    const icon = document.getElementById('toggle-password-icon-' + fieldId);
    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordField.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const brandSelect = document.getElementById('brand');
    const branchSelect = document.getElementById('branch_id');
    const microClusterListDiv = document.getElementById('micro-cluster-list');
    const assignedMcIds = <?php echo json_encode($assigned_mc_ids); ?>;

    function addSelectAllListener() {
        const selectAllCheckbox = document.getElementById('select-all-mc');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checkboxes = microClusterListDiv.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
            });
        }
    }

    function loadBranches(selectedBrand) {
        branchSelect.innerHTML = '<option value="">-- Memuat... --</option>';
        microClusterListDiv.innerHTML = '<p class="text-gray-500">Pilih Branch terlebih dahulu.</p>';

        if (!selectedBrand) {
            branchSelect.innerHTML = '<option value="">-- Pilih Brand dulu --</option>';
            return;
        }

        fetch(`api_helper.php?action=get_branches_by_brand&brand=${selectedBrand}`)
            .then(response => response.json())
            .then(data => {
                branchSelect.innerHTML = '<option value="">-- Pilih Branch --</option>';
                data.forEach(branch => {
                    const option = new Option(branch.nama_branch, branch.id);
                    branchSelect.add(option);
                });
                
                const selectedBranchId = '<?php echo $user_data['branch_id']; ?>';
                if (selectedBranchId) {
                    branchSelect.value = selectedBranchId;
                    loadMicroClusters(selectedBranchId);
                }
            });
    }

    function loadMicroClusters(selectedBranch) {
        microClusterListDiv.innerHTML = '<p class="text-gray-500">Memuat...</p>';

        if (!selectedBranch) {
            microClusterListDiv.innerHTML = '<p class="text-gray-500">Pilih Branch terlebih dahulu.</p>';
            return;
        }

        fetch(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${selectedBranch}`)
            .then(response => response.json())
            .then(data => {
                microClusterListDiv.innerHTML = '';
                if (data.length === 0) {
                    microClusterListDiv.innerHTML = '<p class="text-gray-500">Tidak ada Micro Cluster di bawah Branch ini.</p>';
                } else {
                    const selectAllDiv = document.createElement('div');
                    selectAllDiv.className = 'pb-2 mb-2 border-b';
                    selectAllDiv.innerHTML = `
                        <label class="inline-flex items-center font-semibold">
                            <input type="checkbox" id="select-all-mc" class="form-checkbox h-5 w-5 text-indigo-600">
                            <span class="ml-2">Pilih Semua / Hapus Pilihan</span>
                        </label>
                    `;
                    microClusterListDiv.appendChild(selectAllDiv);
                    
                    data.forEach(mc => {
                        const isChecked = assignedMcIds.includes(parseInt(mc.id));
                        const checkboxDiv = document.createElement('div');
                        checkboxDiv.className = 'ml-2';
                        checkboxDiv.innerHTML = `
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="micro_cluster_ids[]" value="${mc.id}" class="form-checkbox h-5 w-5 text-blue-600" ${isChecked ? 'checked' : ''}>
                                <span class="ml-2">${mc.nama_micro_cluster}</span>
                            </label>
                        `;
                        microClusterListDiv.appendChild(checkboxDiv);
                    });

                    addSelectAllListener();
                }
            });
    }

    brandSelect.addEventListener('change', () => loadBranches(brandSelect.value));
    branchSelect.addEventListener('change', () => loadMicroClusters(branchSelect.value));

    if (brandSelect.value) {
        loadBranches(brandSelect.value);
    }
});
</script>
</body>
</html>
