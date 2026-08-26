<?php
// D:\laragon\www\marcomm_bn\admin\admin_import_site_form.php

// Mengaktifkan output buffering sebagai baris kode pertama
ob_start();

require_once 'config/database.php'; // Sesuaikan path jika file ini di subfolder lain
require_once 'vendor/autoload.php'; // Sesuaikan path jika file ini di subfolder lain

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php"); // Redirect ke login jika tidak ada akses
    exit;
}

// Ambil pesan sukses/error dari sesi jika ada
$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Site (Bulky)</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100 p-4 md:p-8">
    <div class="max-w-xl mx-auto bg-white p-6 md:p-8 rounded-lg shadow-lg">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Import Site (Bulky)</h1>
            <a href="admin_site.php" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg transition duration-300">
                Kembali ke Manajemen Site
            </a>
        </div>

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

        <form action="admin_site.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            <div class="mb-4">
                <label for="file_import" class="block text-gray-700 font-bold mb-2">Pilih File Excel (.xlsx atau .xls) <span class="text-red-500">*</span></label>
                <input type="file" id="file_import" name="file_import" accept=".xlsx, .xls" required class="w-full px-3 py-2 border rounded-lg">
                <p class="text-sm text-gray-600 mt-1">Sistem ini menggunakan <strong>Smart Mapping</strong>. Urutan kolom bebas, asalkan baris pertama berisi header yang jelas (misal: Site ID, Site Name, Brand, Nama Branch, Micro Cluster, dsb).</p>
                <a href="process/download_site_template.php" class="text-sm text-blue-600 hover:underline mt-2 inline-block"><i class="fas fa-download mr-1"></i> Unduh Template Excel</a>
            </div>
            <div class="mt-6">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                    <i class="fas fa-upload"></i> Import Data
                </button>
            </div>
        </form>
    </div>
</body>
</html>
<?php
// Mengirimkan semua output yang di-buffer ke browser
ob_end_flush();
?>
