<?php
// admin_matpro_stock_form.php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

$mode = 'add';
$stock_data = [
    'id' => '',
    'project_id' => '',
    'type_id' => '',
    'branch_id' => '',
    'micro_cluster_id' => null, // Default null for optional MC
    'stock_quantity' => 0
];

// Inisialisasi pesan
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// Ambil semua proyek aktif untuk dropdown (kita akan mengambil brand-nya juga)
$projects_result = $mysqli->query("SELECT id, project_name, brand FROM matpro_projects WHERE is_active = 1 ORDER BY project_name ASC");

// Ambil semua branch untuk dropdown (akan difilter oleh JS)
// Kita tidak perlu mengambilnya di PHP lagi karena JS akan memuatnya secara dinamis
// $branches_result = $mysqli->query("SELECT id, nama_branch FROM branches ORDER BY nama_branch ASC");

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $mode = 'edit';
    $id = (int)$_GET['id'];
    $stmt = $mysqli->prepare("SELECT * FROM matpro_stocks WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $stock_data = $result->fetch_assoc();
        } else {
            $_SESSION['error_message'] = "Stok Matpro tidak ditemukan.";
            header("location: admin_manage_matpro_stocks.php");
            exit;
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Gagal menyiapkan query: " . $mysqli->error;
        header("location: admin_manage_matpro_stocks.php");
        exit;
    }
}

// Ambil data awal untuk dropdown berantai jika dalam mode edit
$initial_types_query = null;
if (!empty($stock_data['project_id'])) {
    $stmt_types = $mysqli->prepare("SELECT id, type_name FROM matpro_types WHERE project_id = ? AND is_active = 1 ORDER BY type_name");
    if ($stmt_types) {
        $stmt_types->bind_param("i", (int)$stock_data['project_id']);
        $stmt_types->execute();
        $initial_types_query = $stmt_types->get_result();
        $stmt_types->close();
    }
}

$initial_mcs_query = null;
if (!empty($stock_data['branch_id'])) {
    $stmt_mcs = $mysqli->prepare("SELECT id, nama_micro_cluster FROM micro_clusters WHERE branch_id = ? ORDER BY nama_micro_cluster");
    if ($stmt_mcs) {
        $stmt_mcs->bind_param("i", (int)$stock_data['branch_id']);
        $stmt_mcs->execute();
        $initial_mcs_query = $stmt_mcs->get_result();
        $stmt_mcs->close();
    }
}

// Ambil brand dari proyek yang sedang diedit (jika mode edit)
$current_project_brand = '';
if ($mode === 'edit' && !empty($stock_data['project_id'])) {
    $stmt_proj_brand = $mysqli->prepare("SELECT brand FROM matpro_projects WHERE id = ?");
    if ($stmt_proj_brand) {
        $stmt_proj_brand->bind_param("i", (int)$stock_data['project_id']);
        $stmt_proj_brand->execute();
        $current_project_brand = $stmt_proj_brand->get_result()->fetch_assoc()['brand'] ?? '';
        $stmt_proj_brand->close();
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Stok Matpro</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <h1 class="text-2xl font-bold text-gray-800 mb-6"><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Stok Matpro</h1>
    
    <?php if (!empty($success_message)): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert">
        <p><?php echo $success_message; ?></p>
    </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
        <p><?php echo $error_message; ?></p>
    </div>
    <?php endif; ?>

    <form action="process/admin_matpro_stock_process.php" method="POST">
        <input type="hidden" name="action" value="<?php echo $mode; ?>">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($stock_data['id']); ?>">

        <div class="mb-4">
            <label for="project_id" class="block text-gray-700 font-bold mb-2">Proyek Matpro</label>
            <select name="project_id" id="project_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="">-- Pilih Proyek --</option>
                <?php mysqli_data_seek($projects_result, 0); // Reset pointer
                while($project = $projects_result->fetch_assoc()): ?>
                    <option 
                        value="<?php echo $project['id']; ?>" 
                        data-brand="<?php echo htmlspecialchars($project['brand']); ?>"
                        <?php if ($stock_data['project_id'] == $project['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($project['project_name'] . ' (' . $project['brand'] . ')'); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-4">
            <label for="type_id" class="block text-gray-700 font-bold mb-2">Jenis Matpro</label>
            <select name="type_id" id="type_id" required class="w-full px-3 py-2 border rounded-lg bg-white" <?php if(empty($stock_data['project_id'])) echo 'disabled'; ?>>
                <option value="">Pilih Proyek dulu</option>
                <?php if ($initial_types_query) { mysqli_data_seek($initial_types_query, 0); while($type = $initial_types_query->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($type['id']); ?>" <?php if ($stock_data['type_id'] == $type['id']) echo 'selected'; ?>><?php echo htmlspecialchars($type['type_name']); ?></option>
                <?php endwhile; } ?>
            </select>
        </div>
        
        <div class="mb-4">
            <label for="branch_id" class="block text-gray-700 font-bold mb-2">Branch</label>
            <select name="branch_id" id="branch_id" required class="w-full px-3 py-2 border rounded-lg bg-white" <?php if(empty($stock_data['project_id'])) echo 'disabled'; ?>>
                <option value="">Pilih Proyek dulu</option>
                <?php 
                // Initial branches will be loaded by JS based on project brand
                // if ($branches_result) { mysqli_data_seek($branches_result, 0); while($branch = $branches_result->fetch_assoc()): ?>
                //     <option 
                //         value="<?php //echo $branch['id']; ?>" 
                //         <?php //if ($stock_data['branch_id'] == $branch['id']) echo 'selected'; ?>>
                //         <?php //echo htmlspecialchars($branch['nama_branch']); ?>
                //     </option>
                <?php //endwhile; } ?>
            </select>
        </div>

        <div class="mb-4">
            <label for="micro_cluster_id" class="block text-gray-700 font-bold mb-2">Micro Cluster (Opsional)</label>
            <select name="micro_cluster_id" id="micro_cluster_id" class="w-full px-3 py-2 border rounded-lg bg-white" <?php if(empty($stock_data['branch_id'])) echo 'disabled'; ?>>
                <option value="">-- Pilih Branch dulu (atau kosongkan) --</option>
                <?php if ($initial_mcs_query) { mysqli_data_seek($initial_mcs_query, 0); while($mc = $initial_mcs_query->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($mc['id']); ?>" <?php if ($stock_data['micro_cluster_id'] == $mc['id']) echo 'selected'; ?>><?php echo htmlspecialchars($mc['nama_micro_cluster']); ?></option>
                <?php endwhile; } ?>
            </select>
            <p class="text-sm text-gray-600 mt-1">Kosongkan untuk stok level Branch.</p>
        </div>

        <div class="mb-6">
            <label for="stock_quantity" class="block text-gray-700 font-bold mb-2">Jumlah Stok</label>
            <input type="number" name="stock_quantity" id="stock_quantity" value="<?php echo htmlspecialchars($stock_data['stock_quantity']); ?>" required class="w-full px-3 py-2 border rounded-lg">
        </div>

        <div class="mt-6 flex justify-end">
            <a href="admin_manage_matpro_stocks.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">Batal</a>
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Simpan</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const projectSelect = document.getElementById('project_id');
    const typeSelect = document.getElementById('type_id');
    const branchSelect = document.getElementById('branch_id');
    const microClusterSelect = document.getElementById('micro_cluster_id');

    async function loadOptions(url, selectElement, prompt, selectedValue = null) {
        selectElement.innerHTML = `<option value="">${prompt}</option>`;
        selectElement.disabled = true;
        try {
            const response = await fetch(url);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const data = await response.json();
            
            selectElement.innerHTML = `<option value="">${prompt}</option>`; // Clear and add prompt again
            data.forEach(item => {
                const optionText = item.type_name || item.nama_branch || item.nama_micro_cluster;
                const option = new Option(optionText, item.id);
                selectElement.add(option);
            });
            if (selectedValue) {
                selectElement.value = selectedValue;
            }
            selectElement.disabled = false;
        } catch (error) {
            console.error('Error loading options:', error);
            selectElement.innerHTML = `<option value="">Gagal memuat</option>`;
            selectElement.disabled = true;
        }
    }

    projectSelect.addEventListener('change', () => {
        typeSelect.innerHTML = '<option value="">Pilih Proyek dulu</option>';
        typeSelect.disabled = true;
        branchSelect.innerHTML = '<option value="">Pilih Proyek dulu</option>'; // Reset branch
        branchSelect.disabled = true;
        microClusterSelect.innerHTML = '<option value="">Pilih Branch dulu (atau kosongkan) --</option>'; // Reset MC
        microClusterSelect.disabled = true;

        const selectedProjectBrand = projectSelect.options[projectSelect.selectedIndex].dataset.brand;
        
        if (projectSelect.value) {
            // Load Types based on Project
            loadOptions(`api_helper.php?action=get_matpro_types_by_project&project_id=${projectSelect.value}`, typeSelect, '-- Pilih Jenis Matpro --');
            
            // Load Branches based on Project Brand
            if (selectedProjectBrand) {
                loadOptions(`api_helper.php?action=get_branches_by_brand_or_both&brand=${selectedProjectBrand}`, branchSelect, '-- Pilih Branch --');
            }
        }
    });

    branchSelect.addEventListener('change', () => {
        microClusterSelect.innerHTML = '<option value="">Pilih Branch dulu (atau kosongkan) --</option>';
        microClusterSelect.disabled = true;
        if (branchSelect.value) {
            loadOptions(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${branchSelect.value}`, microClusterSelect, '-- Pilih Micro Cluster --');
        }
    });

    // Initial load for edit mode
    const initialProjectId = '<?php echo htmlspecialchars($stock_data['project_id']); ?>';
    const initialTypeId = '<?php echo htmlspecialchars($stock_data['type_id']); ?>';
    const initialBranchId = '<?php echo htmlspecialchars($stock_data['branch_id']); ?>';
    const initialMicroClusterId = '<?php echo htmlspecialchars($stock_data['micro_cluster_id'] ?? ''); ?>'; // Handle null for MC

    // Load initial types and branches if in edit mode
    if (initialProjectId) {
        // Fetch project brand for initial branch loading
        const currentProjectBrand = '<?php echo htmlspecialchars($current_project_brand); ?>'; // Ambil brand dari PHP
        
        loadOptions(`api_helper.php?action=get_matpro_types_by_project&project_id=${initialProjectId}`, typeSelect, '-- Pilih Jenis Matpro --', initialTypeId);
        
        if (currentProjectBrand) {
            loadOptions(`api_helper.php?action=get_branches_by_brand_or_both&brand=${currentProjectBrand}`, branchSelect, '-- Pilih Branch --', initialBranchId)
                .then(() => {
                    if (initialBranchId && initialMicroClusterId !== '') { // Only load MC if branch is set and MC was not null
                        return loadOptions(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${initialBranchId}`, microClusterSelect, '-- Pilih Micro Cluster --', initialMicroClusterId);
                    }
                });
        }
    }
});
</script>
</body>
</html>
