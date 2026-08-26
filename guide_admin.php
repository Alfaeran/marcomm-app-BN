<?php require_once 'config/database.php'; ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buku Panduan - Admin</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4 sm:p-8">
        <div class="max-w-4xl mx-auto bg-white p-6 sm:p-8 rounded-lg shadow-lg">
            <div class="flex justify-between items-center mb-6 border-b pb-4">
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">Buku Panduan (Admin)</h1>
                <a href="dashboard_admin.php" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg">Kembali</a>
            </div>

            <div class="prose max-w-none">
                <p>Sebagai Admin, Anda memiliki akses penuh untuk mengelola seluruh data di dalam sistem.</p>
                
                <h2 class="text-xl font-semibold mt-6">Manajemen Data Master</h2>
                <p>Anda dapat menambah, melihat, mengubah, dan menghapus (CRUD) data-data inti aplikasi. Semua halaman manajemen ini dilengkapi dengan fitur pencarian, filter, dan paginasi.</p>
                <ul class="list-disc list-inside space-y-2">
                    <li><strong>Manajemen User:</strong> Mengelola akun pengguna (admin dan user), termasuk penugasan brand, branch, dan micro cluster.</li>
                    <li><strong>Manajemen Branch:</strong> Mengelola data cabang organisasi.</li>
                    <li><strong>Manajemen Micro Cluster:</strong> Mengelola data micro cluster yang terkait dengan branch.</li>
                    <li><strong>Manajemen Site:</strong> Mengelola data lokasi site.</li>
                    <li><strong>Manajemen Kategori Event:</strong> Mengelola kategori-kategori event.</li>
                    <li><strong>Manajemen Proyek Matpro:</strong> Mengelola daftar proyek untuk Material Promosi (Matpro). Anda dapat menambah, mengedit, dan menghapus proyek, serta mengaktifkan/menonaktifkan statusnya.</li>
                    <li><strong>Manajemen Jenis Matpro:</strong> Mengelola jenis-jenis Material Promosi (Matpro) yang terkait dengan setiap proyek. Anda dapat menambah, mengedit, dan menghapus jenis, serta mengaktifkan/menonaktifkan statusnya.</li>
                    <li><strong>Manajemen Outlet:</strong> Mengelola data master outlet. Fitur ini mendukung impor data massal melalui file Excel.</li>
                    <li><strong>Manajemen Stok Matpro:</strong> Mengelola jumlah stok Material Promosi (Matpro) per proyek, jenis, branch, dan micro cluster. Fitur ini mendukung impor data massal melalui file Excel.</li>
                </ul>

                <h2 class="text-xl font-semibold mt-6">Laporan & Analisis</h2>
                <ul class="list-disc list-inside space-y-2">
                    <li><strong>Laporan Event:</strong> Melihat semua data event yang diinput oleh seluruh user. Gunakan filter lengkap untuk mencari data berdasarkan kata kunci, tanggal, brand, branch, micro cluster, dan area. Hasil filter bisa diekspor ke Excel.</li>
                    <li><strong>Cek & Ekspor MSISDN:</strong> Halaman khusus untuk mencari MSISDN di seluruh database. Halaman ini juga dilengkapi filter lengkap dan paginasi dinamis.</li>
                    <li><strong>Leaderboard:</strong> Melihat peringkat user berdasarkan Total Benefit, Jumlah Event, atau Jumlah QSC pada bulan berjalan.</li>
                    <li><strong>Dashboard Visual:</strong> Menampilkan visualisasi data event dan metrik kunci dalam bentuk grafik.</li>
                    <li><strong>Peta Sebaran Event:</strong> Melihat visualisasi semua event dalam bentuk pin di peta interaktif.</li>
                    <li><strong>Laporan Aktivitas Matpro:</strong> Melihat semua data aktivitas pemasangan Material Promosi (Matpro) yang diinput oleh seluruh user. Dilengkapi filter lengkap dan opsi ekspor ke Excel.</li>
                </ul>

                <h2 class="text-xl font-semibold mt-6">Keamanan & Pengaturan</h2>
                <ul class="list-disc list-inside space-y-2">
                    <li><strong>Log Aktivitas:</strong> Melacak semua aktivitas penting di sistem, seperti siapa yang login, menambah, atau menghapus data.</li>
                    <li><strong>Ganti Password Paksa:</strong> Setiap user baru atau user yang passwordnya direset oleh Anda akan dipaksa untuk membuat password baru saat pertama kali login.</li>
                    <li><strong>Pengaturan Aplikasi:</strong> Mengubah nama dan logo aplikasi yang akan ditampilkan di seluruh halaman.</li>
                    <li><strong>Backup Database:</strong> Membuat salinan cadangan (backup) database aplikasi.</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
