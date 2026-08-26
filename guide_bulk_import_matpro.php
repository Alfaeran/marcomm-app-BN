<?php
// guide_bulk_import_matpro.php
require_once 'config/database.php';

// Cek hak akses user
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Tutorial Bulk Import - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
    <style>
        .step-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 1.5rem;
            padding: 2rem;
            transition: all 0.3s ease;
        }
        .step-number {
            width: 3rem;
            height: 3rem;
            background: #2563eb;
            color: white;
            border-radius: 0.75rem;
            display: flex;
            items-center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
    </style>
</head>
<body class="min-h-screen">
    <div class="bg-blob blob-1"></div>

    <div class="flex h-screen overflow-hidden">
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>
        <?php include 'components/sidebar_user.php'; ?>

        <main class="flex-grow p-4 lg:p-10 lg:ml-72 overflow-y-auto">
            <header class="flex items-center gap-4 mb-10">
                <button onclick="toggleSidebar()" class="lg:hidden p-2 text-slate-600 bg-white shadow-sm border rounded-lg active:scale-95 transition-transform"><i class="fas fa-bars"></i></button>
                <div>
                    <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Tutorial Bulk Import</h2>
                    <p class="text-slate-500 font-medium text-sm">Ikuti langkah di bawah untuk upload data banyak sekaligus.</p>
                </div>
            </header>

            <div class="max-w-4xl mx-auto space-y-12 pb-20">
                <!-- Step 1 -->
                <div class="step-card">
                    <div class="step-number">01</div>
                    <h3 class="text-xl font-bold text-slate-800 mb-3">Unduh Template Excel</h3>
                    <p class="text-slate-600 mb-6 font-medium">Langkah pertama adalah memiliki format yang benar. Buka halaman import dan klik link <strong>"Unduh Template Excel"</strong> di bagian bawah form.</p>
                    <div class="bg-blue-50/50 p-6 rounded-2xl border border-blue-100 flex items-center justify-center">
                        <i class="fas fa-file-excel text-5xl text-emerald-600"></i>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="step-card">
                    <div class="step-number">02</div>
                    <h3 class="text-xl font-bold text-slate-800 mb-3">Isi Data dengan Benar</h3>
                    <p class="text-slate-600 mb-6 font-medium">Buka file Excel lalu isi kolom-kolomnya. <strong>PENTING:</strong> Pastikan Nama Proyek, Branch, dan ID Outlet sama persis dengan yang ada di aplikasi.</p>
                    <div class="overflow-x-auto rounded-xl border border-slate-200 shadow-sm">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-800 text-white">
                                <tr>
                                    <th class="p-3">Nama Proyek</th>
                                    <th class="p-3">Site ID</th>
                                    <th class="p-3">QTY</th>
                                    <th class="p-3">Foto Sebelum</th>
                                    <th class="p-3">Foto Sesudah</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                <tr>
                                    <td class="p-3 border-b">Project A</td>
                                    <td class="p-3 border-b">SITE-001</td>
                                    <td class="p-3 border-b">1</td>
                                    <td class="p-3 border-b">foto1.jpg</td>
                                    <td class="p-3 border-b">foto2.jpg</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="step-card">
                    <div class="step-number">03</div>
                    <h3 class="text-xl font-bold text-slate-800 mb-3">Pilih File & Bungkus Jadi .ZIP</h3>
                    <p class="text-slate-600 mb-4 font-medium"><strong>PENTING:</strong> Jangan jadikan folder sebagai ZIP. Melainkan, <strong>pilih (blok)</strong> file Excel `data_aktivitas_matpro.xlsx` dan semua foto buktinya secara langsung, lalu klik kanan dan jadikan <strong>.ZIP</strong>.</p>
                    <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200 font-mono text-xs">
                        <span class="text-emerald-600 font-bold">✔️ CARA YANG BENAR:</span><br>
                        Blok file berikut secara bersamaan, lalu Compress to ZIP:<br>
                        ├── 📄 data_aktivitas_matpro.xlsx<br>
                        ├── 🖼️ foto1.jpg<br>
                        └── 🖼️ foto2.jpg<br>
                        <hr class="my-3 border-slate-200">
                        <span class="text-red-600 font-bold">❌ CARA YANG SALAH:</span><br>
                        Memasukkan file ke dalam sebuah Folder baru, lalu Folder tersebut yang di-ZIP.
                    </div>
                </div>

                <!-- Step 4 -->
                <div class="step-card">
                    <div class="step-number">04</div>
                    <h3 class="text-xl font-bold text-slate-800 mb-3">Upload & Cek Hasil</h3>
                    <p class="text-slate-600 mb-6 font-medium">Upload file ZIP tadi di halaman import. Sistem akan mengekstrak otomatis, memproses data, dan memotong stok Anda.</p>
                    <div class="p-4 bg-amber-50 text-amber-700 rounded-xl flex items-start gap-3">
                        <i class="fas fa-exclamation-circle mt-1"></i>
                        <p class="text-xs font-bold leading-relaxed">Catatan: Jika ada 1 baris saja yang error (misal Site ID salah), maka SELURUH import akan dibatalkan untuk menjaga integritas data.</p>
                    </div>
                </div>

                <div class="flex justify-center">
                    <a href="user_import_matpro.php" class="px-12 py-5 bg-blue-600 text-white font-black rounded-2xl hover:scale-105 transition-all shadow-xl shadow-blue-200">
                        SAYA MENGERTI, GAS IMPORT!
                    </a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
