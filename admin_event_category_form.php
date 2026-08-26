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
$category_data = ['id' => '', 'nama_kategori' => ''];

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $mode = 'edit';
    $id = (int)$_GET['id'];
    $stmt = $mysqli->prepare("SELECT * FROM event_categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $category_data = $result->fetch_assoc();
    } else {
        header("location: admin_manage_event_categories.php");
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
    <title><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Kategori Event</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <h1 class="text-2xl font-bold text-gray-800 mb-6"><?php echo ($mode == 'edit') ? 'Edit' : 'Tambah'; ?> Kategori Event</h1>
    
    <form action="process/admin_event_category_process.php" method="POST">
        <input type="hidden" name="action" value="<?php echo $mode; ?>">
        <input type="hidden" name="id" value="<?php echo $category_data['id']; ?>">

        <div class="mb-6">
            <label for="nama_kategori" class="block text-gray-700 font-bold mb-2">Nama Kategori</label>
            <input type="text" name="nama_kategori" id="nama_kategori" value="<?php echo htmlspecialchars($category_data['nama_kategori']); ?>" required class="w-full px-3 py-2 border rounded-lg">
        </div>

        <div class="mt-6 flex justify-end">
            <a href="admin_manage_event_categories.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">Batal</a>
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Simpan</button>
        </div>
    </form>
</div>
</body>
</html>
