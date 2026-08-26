<?php
// admin_matpro_type_form.php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

$mode = 'add';
$type_data = ['id' => '', 'type_name' => '', 'project_id' => '', 'is_active' => 1]; // Default is_active to 1 (active)

// Ambil semua proyek untuk dropdown
$projects_result = $mysqli->query("SELECT id, project_name, brand FROM matpro_projects ORDER BY project_name ASC");

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $mode = 'edit';
    $id = (int)$_GET['id'];
    $stmt = $mysqli->prepare("SELECT * FROM matpro_types WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $type_data = $result->fetch_assoc();
        } else {
            $_SESSION['error_message'] = "Jenis Matpro tidak ditemukan.";
            header("location: admin_manage_matpro_types.php");
            exit;
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Gagal menyiapkan query: " . $mysqli->error;
        header("location: admin_manage_matpro_types.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Jenis Matpro</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <h1 class="text-2xl font-bold text-gray-800 mb-6"><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Jenis Matpro</h1>
    
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
        <p><?php echo $_SESSION['error_message']; ?></p>
    </div>
    <?php unset($_SESSION['error_message']); endif; ?>

    <form action="process/admin_matpro_type_process.php" method="POST">
        <input type="hidden" name="action" value="<?php echo $mode; ?>">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($type_data['id']); ?>">

        <div class="mb-4">
            <label for="project_id" class="block text-gray-700 font-bold mb-2">Proyek Matpro</label>
            <select name="project_id" id="project_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="">-- Pilih Proyek --</option>
                <?php mysqli_data_seek($projects_result, 0); // Reset pointer
                while($project = $projects_result->fetch_assoc()): ?>
                    <option
                        value="<?php echo $project['id']; ?>"
                        data-brand="<?php echo htmlspecialchars($project['brand']); ?>"
                        <?php if ($type_data['project_id'] == $project['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($project['project_name'] . ' (' . $project['brand'] . ')'); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-4">
            <label for="type_name" class="block text-gray-700 font-bold mb-2">Nama Jenis Matpro</label>
            <input type="text" name="type_name" id="type_name" value="<?php echo htmlspecialchars($type_data['type_name']); ?>" required class="w-full px-3 py-2 border rounded-lg">
        </div>
        
        <div class="mb-6">
            <label for="is_active" class="block text-gray-700 font-bold mb-2">Status</label>
            <select name="is_active" id="is_active" required class="w-full px-3 py-2 border rounded-lg bg-white">
                <option value="1" <?php if ($type_data['is_active'] == 1) echo 'selected'; ?>>Aktif</option>
                <option value="0" <?php if ($type_data['is_active'] == 0) echo 'selected'; ?>>Tidak Aktif</option>
            </select>
        </div>

        <div class="mt-6 flex justify-end">
            <a href="admin_manage_matpro_types.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">Batal</a>
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Simpan</button>
        </div>
    </form>
</div>
</body>
</html>
