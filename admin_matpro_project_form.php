<?php
// admin_matpro_project_form.php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

$mode = 'add';
$project_data = ['id' => '', 'project_name' => '', 'brand' => '', 'is_active' => 1]; // Default is_active to 1 (active)

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $mode = 'edit';
    $id = (int)$_GET['id'];
    $stmt = $mysqli->prepare("SELECT * FROM matpro_projects WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $project_data = $result->fetch_assoc();
        } else {
            $_SESSION['error_message'] = "Proyek tidak ditemukan.";
            header("location: admin_manage_matpro_projects.php");
            exit;
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Gagal menyiapkan query: " . $mysqli->error;
        header("location: admin_manage_matpro_projects.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Proyek Matpro</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <h1 class="text-2xl font-bold text-gray-800 mb-6"><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Proyek Matpro</h1>
    
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
        <p><?php echo $_SESSION['error_message']; ?></p>
    </div>
    <?php unset($_SESSION['error_message']); endif; ?>

    <form action="process/admin_matpro_project_process.php" method="POST">
        <input type="hidden" name="action" value="<?php echo $mode; ?>">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($project_data['id']); ?>">

        <div class="mb-4">
            <label for="project_name" class="block text-gray-700 font-bold mb-2">Nama Proyek</label>
            <input type="text" name="project_name" id="project_name" value="<?php echo htmlspecialchars($project_data['project_name']); ?>" required class="w-full px-3 py-2 border rounded-lg">
        </div>
        
        <div class="mb-4">
            <label for="brand" class="block text-gray-700 font-bold mb-2">Brand</label>
            <select name="brand" id="brand" required class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="">-- Pilih Brand --</option>
                <option value="IM3" <?php if ($project_data['brand'] == 'IM3') echo 'selected'; ?>>IM3</option>
                <option value="3ID" <?php if ($project_data['brand'] == '3ID') echo 'selected'; ?>>3ID</option>
                <option value="BOTH" <?php if ($project_data['brand'] == 'BOTH') echo 'selected'; ?>>BOTH (IM3 & 3ID)</option>
            </select>
        </div>

        <div class="mb-6">
            <label for="is_active" class="block text-gray-700 font-bold mb-2">Status</label>
            <select name="is_active" id="is_active" required class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="1" <?php if ($project_data['is_active'] == 1) echo 'selected'; ?>>Aktif</option>
                <option value="0" <?php if ($project_data['is_active'] == 0) echo 'selected'; ?>>Tidak Aktif</option>
            </select>
        </div>

        <div class="mt-6 flex justify-end">
            <a href="admin_manage_matpro_projects.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">Batal</a>
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Simpan</button>
        </div>
    </form>
</div>
</body>
</html>
