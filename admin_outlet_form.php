<?php
// admin_outlet_form.php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

$mode = 'add';
$outlet_data = [
    'id' => '', 
    'id_outlet' => '', // Menggunakan nama kolom yang benar
    'nama_outlet' => '', // Menggunakan nama kolom yang benar
    // 'organization_id' => '', // Dihapus karena tidak ada di DB aktual
    // 'organization_name' => '', // Dihapus karena tidak ada di DB aktual
    'site_id' => '', 
    'brand' => '',
    'branch_id' => '', // Untuk menyimpan branch_id terpilih dari site
    'micro_cluster_id' => '' // Untuk menyimpan micro_cluster_id terpilih dari site
];

// Inisialisasi pesan
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// Ambil data untuk dropdown filter (Brand)
$brands_for_filter = $mysqli->query("SELECT DISTINCT brand FROM branches WHERE brand IS NOT NULL ORDER BY brand");

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $mode = 'edit';
    $id = (int)$_GET['id'];
    $stmt = $mysqli->prepare("
        SELECT o.id, o.id_outlet, o.nama_outlet, o.site_id, o.brand, -- Menghapus kolom organisasi dari SELECT
               s.branch_id, s.micro_cluster_id, s.brand as site_brand
        FROM outlets o
        LEFT JOIN sites s ON o.site_id = s.id
        WHERE o.id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $outlet_data = $result->fetch_assoc();
            // Override brand jika site_brand ada, karena ini lebih akurat
            $outlet_data['brand'] = $outlet_data['site_brand'] ?? $outlet_data['brand']; 
        } else {
            $_SESSION['error_message'] = "Outlet tidak ditemukan.";
            header("location: admin_manage_outlets.php");
            exit;
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Gagal menyiapkan query: " . $mysqli->error;
        header("location: admin_manage_outlets.php");
        exit;
    }
}

// Ambil data awal untuk dropdown berantai jika dalam mode edit
$initial_branches_query = null;
if (!empty($outlet_data['brand'])) {
    $stmt_branches = $mysqli->prepare("SELECT id, nama_branch FROM branches WHERE brand = ? ORDER BY nama_branch");
    if ($stmt_branches) {
        $stmt_branches->bind_param("s", $outlet_data['brand']);
        $stmt_branches->execute();
        $initial_branches_query = $stmt_branches->get_result();
        $stmt_branches->close();
    }
}

$initial_mcs_query = null;
if (!empty($outlet_data['branch_id'])) {
    $stmt_mcs = $mysqli->prepare("SELECT id, nama_micro_cluster FROM micro_clusters WHERE branch_id = ? ORDER BY nama_micro_cluster");
    if ($stmt_mcs) {
        $stmt_mcs->bind_param("i", (int)$outlet_data['branch_id']);
        $stmt_mcs->execute();
        $initial_mcs_query = $stmt_mcs->get_result();
        $stmt_mcs->close();
    }
}

$initial_sites_query = null;
if (!empty($outlet_data['micro_cluster_id'])) {
    $stmt_sites = $mysqli->prepare("SELECT id, site_name FROM sites WHERE micro_cluster_id = ? ORDER BY site_name");
    if ($stmt_sites) {
        $stmt_sites->bind_param("i", (int)$outlet_data['micro_cluster_id']);
        $stmt_sites->execute();
        $initial_sites_query = $stmt_sites->get_result();
        $stmt_sites->close();
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Outlet</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-2xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <h1 class="text-2xl font-bold text-gray-800 mb-6"><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Outlet</h1>
    
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

    <form action="process/admin_outlet_process.php" method="POST">
        <input type="hidden" name="action" value="<?php echo $mode; ?>">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($outlet_data['id']); ?>">

        <div class="mb-4">
            <label for="id_outlet" class="block text-gray-700 font-bold mb-2">ID Outlet</label>
            <input type="text" name="id_outlet" id="id_outlet" value="<?php echo htmlspecialchars($outlet_data['id_outlet']); ?>" required class="w-full px-3 py-2 border rounded-lg">
        </div>
        <div class="mb-4">
            <label for="nama_outlet" class="block text-gray-700 font-bold mb-2">Nama Outlet</label>
            <input type="text" name="nama_outlet" id="nama_outlet" value="<?php echo htmlspecialchars($outlet_data['nama_outlet']); ?>" required class="w-full px-3 py-2 border rounded-lg">
        </div>
        <!-- Kolom organization_id dan organization_name dihapus dari form karena tidak ada di DB aktual -->

        <div class="mb-4">
            <label for="brand_filter" class="block text-gray-700 font-bold mb-2">Brand</label>
            <select name="brand" id="brand_filter" required class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="">-- Pilih Brand --</option>
                <?php if ($brands_for_filter) { mysqli_data_seek($brands_for_filter, 0); while($brand = $brands_for_filter->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($brand['brand']); ?>" <?php if ($outlet_data['brand'] == $brand['brand']) echo 'selected'; ?>><?php echo htmlspecialchars($brand['brand']); ?></option>
                <?php endwhile; } ?>
                <option value="BOTH" <?php if ($outlet_data['brand'] == 'BOTH') echo 'selected'; ?>>BOTH</option>
            </select>
        </div>

        <div class="mb-4">
            <label for="branch_id" class="block text-gray-700 font-bold mb-2">Branch</label>
            <select name="branch_id" id="branch_id" required class="w-full px-3 py-2 border rounded-lg bg-white" <?php if(empty($outlet_data['brand'])) echo 'disabled'; ?>>
                <option value="">Pilih Brand dulu</option>
                <?php if ($initial_branches_query) { mysqli_data_seek($initial_branches_query, 0); while($branch = $initial_branches_query->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($branch['id']); ?>" <?php if ($outlet_data['branch_id'] == $branch['id']) echo 'selected'; ?>><?php echo htmlspecialchars($branch['nama_branch']); ?></option>
                <?php endwhile; } ?>
            </select>
        </div>

        <div class="mb-4">
            <label for="micro_cluster_id" class="block text-gray-700 font-bold mb-2">Micro Cluster</label>
            <select name="micro_cluster_id" id="micro_cluster_id" required class="w-full px-3 py-2 border rounded-lg bg-white" <?php if(empty($outlet_data['branch_id'])) echo 'disabled'; ?>>
                <option value="">Pilih Branch dulu</option>
                <?php if ($initial_mcs_query) { mysqli_data_seek($initial_mcs_query, 0); while($mc = $initial_mcs_query->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($mc['id']); ?>" <?php if ($outlet_data['micro_cluster_id'] == $mc['id']) echo 'selected'; ?>><?php echo htmlspecialchars($mc['nama_micro_cluster']); ?></option>
                <?php endwhile; } ?>
            </select>
        </div>

        <div class="mb-6">
            <label for="site_id" class="block text-gray-700 font-bold mb-2">Site Name</label>
            <select name="site_id" id="site_id" required class="w-full px-3 py-2 border rounded-lg bg-white" <?php if(empty($outlet_data['micro_cluster_id'])) echo 'disabled'; ?>>
                <option value="">Pilih Micro Cluster dulu</option>
                <?php if ($initial_sites_query) { mysqli_data_seek($initial_sites_query, 0); while($site = $initial_sites_query->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($site['id']); ?>" <?php if ($outlet_data['site_id'] == $site['id']) echo 'selected'; ?>><?php echo htmlspecialchars($site['site_name']); ?></option>
                <?php endwhile; } ?>
            </select>
        </div>

        <div class="mt-6 flex justify-end">
            <a href="admin_manage_outlets.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">Batal</a>
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Simpan</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const brandSelect = document.getElementById('brand_filter');
    const branchSelect = document.getElementById('branch_id');
    const microClusterSelect = document.getElementById('micro_cluster_id');
    const siteSelect = document.getElementById('site_id');

    async function loadOptions(url, selectElement, prompt, selectedValue = null) {
        selectElement.innerHTML = `<option value="">${prompt}</option>`;
        selectElement.disabled = true;
        try {
            const response = await fetch(url);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const data = await response.json();
            data.forEach(item => {
                const option = new Option(item.nama_branch || item.nama_micro_cluster || item.site_name, item.id);
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

    brandSelect.addEventListener('change', () => {
        branchSelect.innerHTML = '<option value="">Pilih Brand dulu</option>';
        branchSelect.disabled = true;
        microClusterSelect.innerHTML = '<option value="">Pilih Branch dulu</option>';
        microClusterSelect.disabled = true;
        siteSelect.innerHTML = '<option value="">Pilih Micro Cluster dulu</option>';
        siteSelect.disabled = true;

        if (brandSelect.value) {
            loadOptions(`api_helper.php?action=get_branches_by_brand&brand=${brandSelect.value}`, branchSelect, '-- Pilih Branch --');
        }
    });

    branchSelect.addEventListener('change', () => {
        microClusterSelect.innerHTML = '<option value="">Pilih Branch dulu</option>';
        microClusterSelect.disabled = true;
        siteSelect.innerHTML = '<option value="">Pilih Micro Cluster dulu</option>';
        siteSelect.disabled = true;

        if (branchSelect.value) {
            loadOptions(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${branchSelect.value}`, microClusterSelect, '-- Pilih Micro Cluster --');
        }
    });

    microClusterSelect.addEventListener('change', () => {
        siteSelect.innerHTML = '<option value="">Pilih Micro Cluster dulu</option>';
        siteSelect.disabled = true;

        if (microClusterSelect.value) {
            loadOptions(`api_helper.php?action=get_sites&micro_cluster_id=${microClusterSelect.value}`, siteSelect, '-- Pilih Site --');
        }
    });

    // Initial load logic for edit mode
    const initialBrand = '<?php echo htmlspecialchars($outlet_data['brand']); ?>';
    const initialBranchId = '<?php echo htmlspecialchars($outlet_data['branch_id']); ?>';
    const initialMicroClusterId = '<?php echo htmlspecialchars($outlet_data['micro_cluster_id']); ?>';
    const initialSiteId = '<?php echo htmlspecialchars($outlet_data['site_id']); ?>';

    if (initialBrand) {
        loadOptions(`api_helper.php?action=get_branches_by_brand&brand=${initialBrand}`, branchSelect, '-- Pilih Branch --', initialBranchId)
            .then(() => {
                if (initialBranchId) {
                    return loadOptions(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${initialBranchId}`, microClusterSelect, '-- Pilih Micro Cluster --', initialMicroClusterId);
                }
            })
            .then(() => {
                if (initialMicroClusterId) {
                    return loadOptions(`api_helper.php?action=get_sites&micro_cluster_id=${initialMicroClusterId}`, siteSelect, '-- Pilih Site --', initialSiteId);
                }
            });
    }
});
</script>
</body>
</html>
