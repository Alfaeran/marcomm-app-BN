<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

$mode = 'add';
$mc_data = ['id' => '', 'nama_micro_cluster' => '', 'branch_id' => '', 'brand' => ''];

// Ambil semua branch untuk dropdown
$branches_result = $mysqli->query("SELECT id, nama_branch, brand FROM branches ORDER BY brand, nama_branch");

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $mode = 'edit';
    $id = (int)$_GET['id'];
    $stmt = $mysqli->prepare("SELECT * FROM micro_clusters WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $mc_data = $result->fetch_assoc();
    } else {
        header("location: admin_manage_micro_clusters.php");
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
    <title><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Micro Cluster</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <h1 class="text-2xl font-bold text-gray-800 mb-6"><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Micro Cluster</h1>
    
    <form action="process/admin_micro_cluster_process.php" method="POST">
        <input type="hidden" name="action" value="<?php echo $mode; ?>">
        <input type="hidden" name="id" value="<?php echo $mc_data['id']; ?>">
        <input type="hidden" name="brand" id="brand_hidden">

        <div class="mb-4">
            <label for="branch_id" class="block text-gray-700 font-bold mb-2">Parent Branch</label>
            <select name="branch_id" id="branch_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="">-- Pilih Branch --</option>
                <?php while($branch = $branches_result->fetch_assoc()): ?>
                    <option 
                        value="<?php echo $branch['id']; ?>" 
                        data-brand="<?php echo $branch['brand']; ?>"
                        <?php if ($mc_data['branch_id'] == $branch['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($branch['nama_branch'] . ' (' . $branch['brand'] . ')'); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-6">
            <label for="nama_micro_cluster" class="block text-gray-700 font-bold mb-2">Nama Micro Cluster</label>
            <input type="text" name="nama_micro_cluster" id="nama_micro_cluster" value="<?php echo htmlspecialchars($mc_data['nama_micro_cluster']); ?>" required class="w-full px-3 py-2 border rounded-lg">
        </div>

        <div class="mt-6 flex justify-end">
            <a href="admin_manage_micro_clusters.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">Batal</a>
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Simpan</button>
        </div>
    </form>
</div>
<script>
    // Script untuk mengambil brand dari branch yang dipilih dan memasukkannya ke hidden input
    document.getElementById('branch_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        document.getElementById('brand_hidden').value = selectedOption.dataset.brand || '';
    });

    // Trigger change saat halaman load jika dalam mode edit
    if ('<?php echo $mode; ?>' === 'edit') {
        document.getElementById('branch_id').dispatchEvent(new Event('change'));
    }
</script>
</body>
</html>
