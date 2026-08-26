<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}
$mode = 'add';
$branch_data = ['id' => '', 'nama_branch' => '', 'brand' => ''];

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $mode = 'edit';
    $id = (int)$_GET['id'];
    $stmt = $mysqli->prepare("SELECT * FROM branches WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $branch_data = $result->fetch_assoc();
    } else {
        header("location: admin_manage_branches.php");
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
    <title><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Branch</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <h1 class="text-2xl font-bold text-gray-800 mb-6"><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Branch</h1>
    
    <form action="process/admin_branch_process.php" method="POST">
        <input type="hidden" name="action" value="<?php echo $mode; ?>">
        <input type="hidden" name="id" value="<?php echo $branch_data['id']; ?>">

        <div class="mb-4">
            <label for="nama_branch" class="block text-gray-700 font-bold mb-2">Nama Branch</label>
            <input type="text" name="nama_branch" id="nama_branch" value="<?php echo htmlspecialchars($branch_data['nama_branch']); ?>" required class="w-full px-3 py-2 border rounded-lg">
        </div>
        
        <div class="mb-6">
            <label for="brand" class="block text-gray-700 font-bold mb-2">Brand</label>
            <select name="brand" id="brand" required class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="">-- Pilih Brand --</option>
                <option value="IM3" <?php if ($branch_data['brand'] == 'IM3') echo 'selected'; ?>>IM3</option>
                <option value="3ID" <?php if ($branch_data['brand'] == '3ID') echo 'selected'; ?>>3ID</option>
            </select>
        </div>

        <div class="mt-6 flex justify-end">
            <a href="admin_manage_branches.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">Batal</a>
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Simpan</button>
        </div>
    </form>
</div>
</body>
</html>
