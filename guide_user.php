<?php require_once 'config/database.php'; ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buku Panduan - User</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4 sm:p-8">
        <div class="max-w-4xl mx-auto bg-white p-6 sm:p-8 rounded-lg shadow-lg">
            <div class="flex justify-between items-center mb-6 border-b pb-4">
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">Buku Panduan (User)</h1>
                <a href="dashboard_user.php" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg">Kembali</a>
            </div>

            <div class="prose max-w-none">
                <p>Selamat datang di Marcomm Apps! Panduan ini akan membantu Anda menggunakan fitur-fitur yang tersedia.</p>
                
                <h2 class="text-xl font-semibold mt-6">Input Data Event</h2>
                <p>Anda bisa menginput data event melalui dua cara:</p>
                <ul class="list-disc list-inside space-y-2">
                    <li><strong>Input Event Tunggal:</strong> Mengisi formulir satu per satu untuk setiap event.</li>
                    <li><strong>Import Event Massal:</strong> Mengunggah file Excel dan ZIP berisi foto dan MSISDN untuk menginput banyak event sekaligus.</li>
                </ul>
                <p><strong>Catatan Penting untuk Input Event:</strong></p>
                <ul class="list-disc list-inside space-y-2">
                    <li><strong>Lokasi Otomatis:</strong> Gunakan tombol "Gunakan Lokasi Saat Ini" untuk mengisi Latitude dan Longitude secara otomatis.</li>
                    <li><strong>Pemilihan Area:</strong> Pilihan Micro Cluster dan Site Name akan secara otomatis disesuaikan berdasarkan area yang sudah ditugaskan kepada Anda oleh admin.</li>
                    <li><strong>MSISDN Duplikat:</strong> Jika Anda mengunggah file MSISDN yang berisi nomor yang sudah ada di database, sistem akan tetap menyimpan data event Anda. MSISDN yang unik akan dimasukkan, dan Anda akan mendapat notifikasi peringatan tentang nomor mana saja yang duplikat.</li>
                </ul>

                <h2 class="text-xl font-semibold mt-6">Input Data Aktivitas Material Promosi (Matpro)</h2>
                <p>Anda dapat mencatat aktivitas pemasangan material promosi (Matpro) melalui formulir khusus:</p>
                <ul class="list-disc list-inside space-y-2">
                    <li><strong>Input Aktivitas Matpro:</strong> Mengisi formulir untuk setiap pemasangan Matpro. Anda dapat memilih level input (Outlet Level atau Branch Level).</li>
                    <li><strong>Pemilihan Lokasi:</strong> Sistem akan memfilter Micro Cluster, Site Name, dan Outlet berdasarkan Branch Anda dan Micro Cluster yang ditugaskan. Anda dapat mencari Site dan Outlet langsung di dropdown.</li>
                    <li><strong>Filter Stok Otomatis:</strong> Saat memilih Proyek dan Jenis Matpro, sistem akan menampilkan sisa stok yang tersedia untuk lokasi Anda, dan Anda tidak bisa menginput kuantitas melebihi stok yang ada.</li>
                    <li><strong>Foto Wajib:</strong> Anda wajib mengunggah foto sebelum dan sesudah pemasangan Matpro.</li>
                </ul>

                <h2 class="text-xl font-semibold mt-6">Fitur Lainnya</h2>
                <ul class="list-disc list-inside space-y-2">
                    <li><strong>Dashboard:</strong> Halaman utama Anda yang menampilkan ringkasan performa (jumlah event dan benefit, serta aktivitas Matpro dan total kuantitas) per hari, bulan, kuartal, dan tahun. Anda juga bisa melihat riwayat event yang pernah Anda input.</li>
                    <li><strong>Daftar Aktivitas Matpro Saya:</strong> Melihat semua aktivitas pemasangan Material Promosi (Matpro) yang pernah Anda input.</li>
                    <li><strong>Sisa Stok Matpro Saya:</strong> Melihat sisa stok material promosi yang tersedia untuk Branch dan Micro Cluster Anda.</li>
                    <li><strong>Leaderboard:</strong> Lihat peringkat Anda dan pengguna lain berdasarkan performa bulanan.</li>
                    <li><strong>Peta Event Saya:</strong> Lihat visualisasi semua event yang pernah Anda input dalam bentuk pin di peta.</li>
                    <li><strong>Ganti Password:</strong> Jika Anda adalah pengguna baru atau password Anda baru saja direset oleh admin, Anda akan diminta untuk membuat password baru saat pertama kali login untuk menjaga keamanan akun.</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
