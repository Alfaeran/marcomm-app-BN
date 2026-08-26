    <?php
    // user_edit_event_form.php

    require_once 'config/database.php';
    require_once 'vendor/autoload.php';

    // Cek jika user tidak login atau bukan 'user'
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
        header("location: login.php"); // Redirect ke login jika tidak berhak
        exit;
    }

    $user_id = (int)$_SESSION['id'];
    $event_id = (int)($_GET['id'] ?? 0); // Ambil ID event dari URL

    if (empty($event_id)) {
        $_SESSION['error_message'] = "Event ID tidak valid.";
        header("location: dashboard_user.php");
        exit;
    }

    // --- VERIFIKASI HAK AKSES EDIT OLEH USER ---
    // 1. Ambil data event
    // 2. Cek apakah ada request edit untuk event ini dari user yang sedang login dengan status 'approved'
    $event_data = null;
    // Memperbaiki query SQL: Menambahkan JOIN ke tabel branches dan micro_clusters
    $stmt_event = $mysqli->prepare("
        SELECT
            es.*,
            s.site_name,
            s.site_id as site_code,
            ec.nama_kategori,
            b.nama_branch,          -- Mengambil nama_branch dari tabel branches
            mc.nama_micro_cluster,   -- Mengambil nama_micro_cluster dari tabel micro_clusters
            s.micro_cluster_id      -- Tambahkan micro_cluster_id agar terpilih di dropdown
        FROM
            event_submissions es
        LEFT JOIN
            sites s ON es.site_id = s.id
        LEFT JOIN
            event_categories ec ON es.kategori_event_id = ec.id
        LEFT JOIN
            branches b ON s.branch_id = b.id          -- Join ke tabel branches
        LEFT JOIN
            micro_clusters mc ON s.micro_cluster_id = mc.id  -- Join ke tabel micro_clusters
        WHERE
            es.unique_id = ? AND es.user_id = ?
    ");

    // --- START PERBAIKAN ---
    // Tambahkan pengecekan error prepare untuk query utama
    if ($stmt_event === false) {
        error_log("SQL Prepare Error (user_edit_event_form.php): " . $mysqli->error); // Log error ke file log PHP
        $_SESSION['error_message'] = "Terjadi kesalahan sistem saat memuat data event. Mohon hubungi administrator. (Error Code: " . $mysqli->errno . ")";
        header("location: dashboard_user.php");
        exit;
    }
    // --- END PERBAIKAN ---

    $stmt_event->bind_param("si", $event_id, $user_id);
    $stmt_event->execute();
    $result_event = $stmt_event->get_result();

    if ($result_event->num_rows === 0) {
        $_SESSION['error_message'] = "Event tidak ditemukan atau Anda tidak memiliki izin untuk mengedit event ini.";
        header("location: dashboard_user.php");
        exit;
    }
    $event_data = $result_event->fetch_assoc();
    $stmt_event->close();

    // Cek apakah ada request edit yang disetujui untuk event ini
    $stmt_check_request = $mysqli->prepare("
        SELECT id FROM event_requests 
        WHERE event_id = ? AND user_id = ? AND request_type = 'edit' AND status = 'approved'
    ");
    $stmt_check_request->bind_param("si", $event_id, $user_id);
    $stmt_check_request->execute();
    $result_check_request = $stmt_check_request->get_result();

    if ($result_check_request->num_rows === 0) {
        $_SESSION['error_message'] = "Anda belum memiliki izin untuk mengedit event ini. Silakan ajukan permintaan edit terlebih dahulu.";
        header("location: dashboard_user.php");
        exit;
    }
    $request_id_for_completion = $result_check_request->fetch_assoc()['id']; // Simpan ID request untuk update status nanti
    $stmt_check_request->close();

    // Inisialisasi variabel pesan (untuk form ini, bukan dari sesi)
    $success_message = '';
    $error_message = '';

    // Ambil data untuk dropdowns dan checkboxes
    $categories_result = $mysqli->query("SELECT id, nama_kategori FROM event_categories ORDER BY nama_kategori");
    $provider_digunakan_arr = explode(', ', $event_data['provider_digunakan']);
    $provider_terbaik_arr = explode(', ', $event_data['provider_terbaik']);

    // Ambil data branch user
    $user_branch_id = $_SESSION['branch_id'] ?? null;
    $stmt_branch_name = $mysqli->prepare("SELECT nama_branch FROM branches WHERE id = ?");
    $stmt_branch_name->bind_param("i", $user_branch_id);
    $stmt_branch_name->execute();
    $user_branch_name = $stmt_branch_name->get_result()->fetch_assoc()['nama_branch'] ?? 'Branch Tidak Ditemukan';
    $stmt_branch_name->close();

    // Ambil Micro Clusters yang bisa diakses user (sama seperti di input_form.php)
    $has_specific_mc = false;
    $stmt_check_mc = $mysqli->prepare("SELECT COUNT(user_id) as total FROM user_micro_clusters WHERE user_id = ?");
    $stmt_check_mc->bind_param("i", $user_id);
    $stmt_check_mc->execute();
    $has_specific_mc = $stmt_check_mc->get_result()->fetch_assoc()['total'] > 0;
    $stmt_check_mc->close();

    $micro_clusters_result = null; // Inisialisasi null
    if ($has_specific_mc) {
        $stmt_mc = $mysqli->prepare(
            "SELECT mc.id, mc.nama_micro_cluster
            FROM user_micro_clusters umc
            JOIN micro_clusters mc ON umc.micro_cluster_id = mc.id
            WHERE umc.user_id = ?
            ORDER BY mc.nama_micro_cluster"
        );
        if ($stmt_mc) { // Perbaikan: Cek apakah prepare berhasil
            $stmt_mc->bind_param("i", $user_id);
            $stmt_mc->execute();
            $micro_clusters_result = $stmt_mc->get_result();
            $stmt_mc->close();
        } else {
            $error_message = "Gagal menyiapkan query micro cluster spesifik: " . $mysqli->error;
        }
    } else {
        // Perbaikan: Pastikan $user_branch_id adalah integer yang valid
        $user_branch_id_int = (int)$user_branch_id; 
        if ($user_branch_id_int > 0) { // Hanya jalankan query jika branch_id valid
            $stmt_mc = $mysqli->prepare(
                "SELECT id, nama_micro_cluster
                FROM micro_clusters
                WHERE branch_id = ?
                ORDER BY nama_micro_cluster"
            );
            if ($stmt_mc) { // Perbaikan: Cek apakah prepare berhasil
                $stmt_mc->bind_param("i", $user_branch_id_int);
                $stmt_mc->execute();
                $micro_clusters_result = $stmt_mc->get_result();
                $stmt_mc->close();
            } else {
                $error_message = "Gagal menyiapkan query micro cluster berdasarkan branch: " . $mysqli->error;
            }
        } else {
            $error_message = "ID Branch pengguna tidak valid untuk mengambil Micro Cluster.";
        }
    }


    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Edit Event: <?php echo htmlspecialchars($event_data['event_name']); ?></title>
        <link href="assets/css/tailwind.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    </head>
    <body class="bg-gray-100 p-4 md:p-8">
    <div class="max-w-6xl mx-auto bg-white p-6 md:p-8 rounded-lg shadow-lg">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Edit Event: <?php echo htmlspecialchars($event_data['event_name']); ?></h1>
            <a href="dashboard_user.php" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg transition duration-300">
                Kembali ke Dashboard
            </a>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Sukses!</strong>
                <span class="block sm:inline"><?php echo $success_message; ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Error!</strong>
                <span class="block sm:inline"><?php echo $error_message; ?></span>
            </div>
        <?php endif; ?>
        
        <form id="eventForm" action="process/user_event_edit_process.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_event">
            <input type="hidden" name="event_unique_id" value="<?php echo $event_data['unique_id']; ?>">
            <input type="hidden" name="request_id" value="<?php echo $request_id_for_completion; ?>">
            <p class="text-sm text-gray-600 mb-4">Kolom dengan tanda <span class="text-red-500 font-bold">*</span> wajib diisi.</p>

            <fieldset class="border p-4 rounded-lg mb-6">
                <legend class="text-xl font-semibold px-2">Informasi Event & Lokasi</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-4">
                    <div class="mb-4">
                        <label for="event_name" class="block text-gray-700 font-bold mb-2">Nama Event <span class="text-red-500">*</span></label>
                        <input type="text" id="event_name" name="event_name" value="<?php echo htmlspecialchars($event_data['event_name']); ?>" required class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <div class="mb-4">
                        <label for="kategori_event_id" class="block text-gray-700 font-bold mb-2">Kategori Event <span class="text-red-500">*</span></label>
                        <select id="kategori_event_id" name="kategori_event_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                            <option value="">-- Pilih Kategori --</option>
                            <?php mysqli_data_seek($categories_result, 0); // Reset pointer
                            while($category = $categories_result->fetch_assoc()): ?>
                                <option value="<?php echo $category['id']; ?>" <?php if($event_data['kategori_event_id'] == $category['id']) echo 'selected'; ?>><?php echo htmlspecialchars($category['nama_kategori']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 font-bold mb-2">Branch</label>
                        <input type="text" value="<?php echo htmlspecialchars($user_branch_name); ?>" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-200">
                    </div>
                    <div class="mb-4">
                        <label for="micro_cluster_id" class="block text-gray-700 font-bold mb-2">Micro Cluster <span class="text-red-500">*</span></label>
                        <select id="micro_cluster_id" name="micro_cluster_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                            <option value="">-- Pilih Micro Cluster --</option>
                            <?php 
                            if ($micro_clusters_result && $micro_clusters_result->num_rows > 0) {
                                mysqli_data_seek($micro_clusters_result, 0); // Reset pointer
                                while($mc = $micro_clusters_result->fetch_assoc()): ?>
                                    <option value="<?php echo $mc['id']; ?>" <?php if($event_data['micro_cluster_id'] == $mc['id']) echo 'selected'; ?>><?php echo htmlspecialchars($mc['nama_micro_cluster']); ?></option>
                                <?php endwhile;
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="site_id" class="block text-gray-700 font-bold mb-2">Site Name <span class="text-red-500">*</span></label>
                        <select id="site_id" name="site_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                            <!-- Options for sites will be loaded via JavaScript -->
                            <option value="<?php echo htmlspecialchars((string)($event_data['site_id'] ?? '')); ?>" selected><?php echo htmlspecialchars((string)($event_data['site_name'] ?? '')); ?></option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="latitude" class="block text-gray-700 font-bold mb-2">Latitude <span class="text-red-500">*</span></label>
                        <input type="text" id="latitude" name="latitude" value="<?php echo htmlspecialchars($event_data['location_latitude']); ?>" required class="w-full px-3 py-2 border rounded-lg" placeholder="-8.650000">
                    </div>
                    <div class="mb-4">
                        <label for="longitude" class="block text-gray-700 font-bold mb-2">Longitude <span class="text-red-500">*</span></label>
                        <input type="text" id="longitude" name="longitude" value="<?php echo htmlspecialchars($event_data['location_longitude']); ?>" required class="w-full px-3 py-2 border rounded-lg" placeholder="115.210000">
                    </div>
                    <div class="mb-4 self-end">
                        <button type="button" id="getLocationBtn" class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-map-marker-alt"></i> Gunakan Lokasi Saat Ini
                        </button>
                    </div>
                </div>
            </fieldset>

            <fieldset class="border p-4 rounded-lg mb-6">
                <legend class="text-xl font-semibold px-2">Data Penjualan, Audience & Biaya</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mt-4">
                    <div class="mb-4"><label for="sp_0k" class="block text-gray-700 mb-2">SP 0K <span class="text-red-500">*</span></label><input type="number" name="sp_0k" value="<?php echo (int)($event_data['sp_0k'] ?? 0); ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                    <div class="mb-4"><label for="sp_3gb" class="block text-gray-700 mb-2">SP 3GB <span class="text-red-500">*</span></label><input type="number" name="sp_3gb" value="<?php echo (int)($event_data['sp_3gb'] ?? 0); ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                    <div class="mb-4"><label for="sp_5gb" class="block text-gray-700 mb-2">SP 5GB <span class="text-red-500">*</span></label><input type="number" name="sp_5gb" value="<?php echo (int)($event_data['sp_5gb'] ?? 0); ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                    <div class="mb-4"><label for="sp_7gb" class="block text-gray-700 mb-2">SP 7GB <span class="text-red-500">*</span></label><input type="number" name="sp_7gb" value="<?php echo (int)($event_data['sp_7gb'] ?? 0); ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                    <div class="mb-4"><label for="sp_100gb" class="block text-gray-700 mb-2">SP 100GB (Opsional)</label><input type="number" name="sp_100gb" value="<?php echo (int)($event_data['sp_100gb'] ?? 0); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
                    <div class="mb-4"><label for="fwa" class="block text-gray-700 mb-2">FWA (Opsional)</label><input type="number" name="fwa" value="<?php echo (int)($event_data['fwa'] ?? 0); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
                    <div class="mb-4"><label for="fwa_5g" class="block text-gray-700 mb-2">FWA 5G (Opsional)</label><input type="number" name="fwa_5g" value="<?php echo (int)($event_data['fwa_5g'] ?? 0); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
                    <div class="mb-4"><label for="hit_haji_umroh" class="block text-gray-700 mb-2">HIT Haji/Umroh (Opsional)</label><input type="number" name="hit_haji_umroh" value="<?php echo (int)($event_data['hit_haji_umroh'] ?? 0); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
                    <div class="mb-4"><label for="reload" class="block text-gray-700 mb-2">Reload <span class="text-red-500">*</span></label><input type="number" name="reload" value="<?php echo (int)($event_data['reload'] ?? 0); ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                    <div class="mb-4"><label for="mobo_paket" class="block text-gray-700 mb-2">Mobo/Paket <span class="text-red-500">*</span></label><input type="number" name="mobo_paket" value="<?php echo (int)($event_data['mobo_paket'] ?? 0); ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                    <div class="mb-4"><label for="cost" class="block text-gray-700 mb-2">Cost <span class="text-red-500">*</span></label><input type="number" name="cost" value="<?php echo (int)($event_data['cost'] ?? 0); ?>" required class="w-full px-3 py-2 border rounded-lg"></div>
                </div>
            </fieldset>

            <fieldset class="border p-4 rounded-lg mb-6 bg-blue-50">
                <legend class="text-xl font-semibold px-2 text-blue-800">Hasil Kalkulasi (Otomatis)</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mt-4">
                    <div class="mb-4"><label class="block text-gray-700 font-bold mb-2">Jumlah QSC</label><input type="text" id="calc_jumlah_qsc" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-200 font-bold text-lg text-center"></div>
                    <div class="mb-4"><label class="block text-gray-700 font-bold mb-2">Benefit SP</label><input type="text" id="calc_benefit_sp" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-200 font-bold text-lg text-center"></div>
                    <div class="mb-4"><label class="block text-gray-700 font-bold mb-2">Benefit FWA</label><input type="text" id="calc_benefit_fwa" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-200 font-bold text-lg text-center"></div>
                    <div class="mb-4"><label class="block text-gray-700 font-bold mb-2">Benefit Total</label><input type="text" id="calc_benefit_total" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-200 font-bold text-lg text-center"></div>
                    <div class="mb-4"><label class="block text-gray-700 font-bold mb-2">Ratio Cost/Benefit</label><input type="text" id="calc_ratio" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-200 font-bold text-lg text-center"></div>
                </div>
                <input type="hidden" id="hidden_jumlah_qsc" name="jumlah_qsc">
                <input type="hidden" id="hidden_benefit_sp" name="benefit_sp">
                <input type="hidden" id="hidden_benefit_fwa" name="benefit_fwa">
                <input type="hidden" id="hidden_benefit_total" name="benefit_total">
                <input type="hidden" id="hidden_ratio" name="ratio">
            </fieldset>

            <fieldset class="border p-4 rounded-lg mb-6">
                <legend class="text-xl font-semibold px-2">Dokumentasi & Feedback</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                    <div class="mb-4">
                        <label class="block text-gray-700 font-bold mb-2">Foto Event Saat Ini</label>
                        <?php if (!empty($event_data['foto_event_url'])): ?>
                            <img src="<?php echo htmlspecialchars($event_data['foto_event_url']); ?>" alt="Foto Event" class="w-32 h-32 object-cover rounded-lg mb-2 border">
                        <?php else: ?>
                            <p class="text-gray-500">Tidak ada foto terunggah.</p>
                        <?php endif; ?>
                        <label for="foto_event" class="block text-gray-700 font-bold mb-2">Ganti Foto Event (Opsional)</label>
                        <input type="file" id="foto_event" name="foto_event" accept="image/*" class="w-full px-3 py-2 border rounded-lg">
                        <p id="file-size-error" class="text-red-500 text-sm mt-1 hidden">Ukuran file melebihi 7MB!</p>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 font-bold mb-2">File MSISDN Saat Ini</label>
                        <p class="text-gray-500 mb-2">MSISDN yang sudah terinput akan dihapus dan diganti dengan yang baru jika Anda mengunggah file baru.</p>
                        <div class="flex justify-between items-center">
                            <label for="msisdn_file" class="block text-gray-700 font-bold mb-2">Ganti File MSISDN (Opsional)</label>
                            <a href="process/download_msisdn_template.php" class="text-sm text-blue-600 hover:underline"><i class="fas fa-download mr-1"></i> Unduh Template</a>
                        </div>
                        <input type="file" id="msisdn_file" name="msisdn_file" accept=".xlsx, .xls" class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <div class="mb-4 md:col-span-2"><label for="alasan" class="block text-gray-700 mb-2">Alasan / Feedback</label><textarea name="alasan" rows="3" class="w-full px-3 py-2 border rounded-lg"><?php echo htmlspecialchars($event_data['alasan']); ?></textarea></div>
                </div>
            </fieldset>

            <fieldset class="border p-4 rounded-lg mb-6">
                <legend class="text-xl font-semibold px-2">Survey Pelanggan</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-4">
                    <div class="mb-4">
                        <label class="block text-gray-700 mb-2">Provider yang digunakan? <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2" id="provider_digunakan_checkboxes">
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_digunakan[]" value="XL" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('XL', $provider_digunakan_arr)) echo 'checked'; ?>><span class="ml-2">XL</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_digunakan[]" value="Telkomsel" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('Telkomsel', $provider_terbaik_arr)) echo 'checked'; ?>><span class="ml-2">Telkomsel</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_digunakan[]" value="Axis" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('Axis', $provider_digunakan_arr)) echo 'checked'; ?>><span class="ml-2">Axis</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_digunakan[]" value="Three" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('Three', $provider_digunakan_arr)) echo 'checked'; ?>><span class="ml-2">Three</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_digunakan[]" value="IM3" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('IM3', $provider_digunakan_arr)) echo 'checked'; ?>><span class="ml-2">IM3</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_digunakan[]" value="Smartfren" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('Smartfren', $provider_digunakan_arr)) echo 'checked'; ?>><span class="ml-2">Smartfren</span></label></div>
                        </div>
                        <p id="provider_digunakan_error" class="text-red-500 text-sm mt-1 hidden">Pilih setidaknya satu provider.</p>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 mb-2">Provider sinyal terbaik? <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2" id="provider_terbaik_checkboxes">
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_terbaik[]" value="XL" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('XL', $provider_terbaik_arr)) echo 'checked'; ?>><span class="ml-2">XL</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_terbaik[]" value="Telkomsel" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('Telkomsel', $provider_terbaik_arr)) echo 'checked'; ?>><span class="ml-2">Telkomsel</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_terbaik[]" value="Axis" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('Axis', $provider_terbaik_arr)) echo 'checked'; ?>><span class="ml-2">Axis</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_terbaik[]" value="Three" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('Three', $provider_terbaik_arr)) echo 'checked'; ?>><span class="ml-2">Three</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_terbaik[]" value="IM3" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('IM3', $provider_terbaik_arr)) echo 'checked'; ?>><span class="ml-2">IM3</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="provider_terbaik[]" value="Smartfren" class="form-checkbox h-5 w-5 text-blue-600" <?php if(in_array('Smartfren', $provider_terbaik_arr)) echo 'checked'; ?>><span class="ml-2">Smartfren</span></label></div>
                        </div>
                        <p id="provider_terbaik_error" class="text-red-500 text-sm mt-1 hidden">Pilih setidaknya satu provider.</p>
                    </div>
                    <div class="mb-4"><label class="block text-gray-700 mb-2">Mengenal Provider IM3/3ID? <span class="text-red-500">*</span></label><select name="kenal_im3" required class="w-full px-3 py-2 border rounded-lg bg-white"><option value="Ya" <?php if($event_data['kenal_im3'] == 'Ya') echo 'selected'; ?>>Ya</option><option value="Tidak" <?php if($event_data['kenal_im3'] == 'Tidak') echo 'selected'; ?>>Tidak</option></select></div>
                    <div class="mb-4"><label class="block text-gray-700 mb-2">Sudah membeli kartu IM3/3ID? <span class="text-red-500">*</span></label><select name="sudah_beli_im3" id="sudah_beli_im3" required class="w-full px-3 py-2 border rounded-lg bg-white"><option value="Tidak" <?php if($event_data['sudah_beli_im3'] == 'Tidak') echo 'selected'; ?>>Tidak</option><option value="Ya" <?php if($event_data['sudah_beli_im3'] == 'Ya') echo 'selected'; ?>>Ya</option></select></div>
                    <div id="lokasi_beli_div" class="mb-4 <?php echo ($event_data['sudah_beli_im3'] == 'Ya') ? '' : 'hidden'; ?>"><label for="lokasi_beli" class="block text-gray-700 mb-2">Jika sudah, di mana membeli? <span class="text-red-500">*</span></label><input type="text" name="lokasi_beli" id="lokasi_beli" value="<?php echo htmlspecialchars($event_data['lokasi_beli']); ?>" class="w-full px-3 py-2 border rounded-lg"></div>
                    <div id="tertarik_beli_div" class="mb-4 <?php echo ($event_data['sudah_beli_im3'] == 'Tidak') ? '' : 'hidden'; ?>"><label class="block text-gray-700 mb-2">Jika belum, apakah tertarik? <span class="text-red-500">*</span></label><select name="tertarik_beli_im3" id="tertarik_beli_im3" required class="w-full px-3 py-2 border rounded-lg bg-white"><option value="Ya" <?php if($event_data['tertarik_beli_im3'] == 'Ya') echo 'selected'; ?>>Ya</option><option value="Tidak" <?php if($event_data['tertarik_beli_im3'] == 'Tidak') echo 'selected'; ?>>Tidak</option></select></div>
                </div>
            </fieldset>

            <div class="mt-8">
                <button type="submit" id="submitBtn" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg text-xl">
                    Simpan Perubahan Event
                </button>
            </div>
        </form>
    </div>

    <script>
    // JavaScript ini sebagian besar salinan dari input_form.php, dengan penyesuaian kecil
    document.addEventListener('DOMContentLoaded', function() {
        const eventForm = document.getElementById('eventForm');
        const getLocationBtn = document.getElementById('getLocationBtn');
        const latInput = document.getElementById('latitude');
        const lonInput = document.getElementById('longitude');
        const microClusterSelect = document.getElementById('micro_cluster_id');
        const siteSelect = document.getElementById('site_id');
        const sudahBeliSelect = document.getElementById('sudah_beli_im3');
        const lokasiBeliInput = document.getElementById('lokasi_beli');
        const lokasiBeliDiv = document.getElementById('lokasi_beli_div');
        const tertarikBeliDiv = document.getElementById('tertarik_beli_div');
        const fotoInput = document.getElementById('foto_event');
        const fileSizeError = document.getElementById('file-size-error');
        
        const providerDigunakanCheckboxes = document.querySelectorAll('input[name="provider_digunakan[]"]');
        const providerDigunakanError = document.getElementById('provider_digunakan_error');
        const providerTerbaikCheckboxes = document.querySelectorAll('input[name="provider_terbaik[]"]');
        const providerTerbaikError = document.getElementById('provider_terbaik_error');

        function showMessageBox(message) {
            const messageBox = document.createElement('div');
            messageBox.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            messageBox.innerHTML = `
                <div class="bg-white p-6 rounded-lg shadow-xl text-center">
                    <p class="text-lg font-semibold mb-4">${message}</p>
                    <button class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-lg" onclick="this.parentNode.parentNode.remove()">Tutup</button>
                </div>
            `;
            document.body.appendChild(messageBox);
        }

        getLocationBtn.addEventListener('click', function() {
            if (navigator.geolocation) {
                getLocationBtn.disabled = true;
                getLocationBtn.innerHTML = 'Mencari...';
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        latInput.value = position.coords.latitude.toFixed(6);
                        lonInput.value = position.coords.longitude.toFixed(6);
                        getLocationBtn.disabled = false;
                        getLocationBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Gunakan Lokasi Saat Ini';
                    },
                    () => {
                        showMessageBox('Gagal mendapatkan lokasi. Pastikan Anda sudah memberikan izin akses lokasi pada browser.');
                        getLocationBtn.disabled = false;
                        getLocationBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Gunakan Lokasi Saat Ini';
                    }
                );
            } else {
                showMessageBox('Geolocation tidak didukung oleh browser ini.');
            }
        });

        fotoInput.addEventListener('change', function() {
            if (this.files[0] && this.files[0].size > 7 * 1024 * 1024) { // Batas 7MB
                fileSizeError.classList.remove('hidden');
                this.value = ''; // Mengosongkan input file jika ukuran melebihi batas
                showMessageBox('Ukuran file foto melebihi 7MB. Harap pilih file yang lebih kecil.');
            } else {
                fileSizeError.classList.add('hidden');
            }
        });

        microClusterSelect.addEventListener('change', function() {
            const mcId = this.value;
            siteSelect.innerHTML = '<option value="">-- Memuat... --</option>';
            siteSelect.disabled = true;
            if (!mcId) {
                siteSelect.innerHTML = '<option value="">-- Pilih Micro Cluster dulu --</option>';
                return;
            }
            fetch(`api_helper.php?action=get_sites&micro_cluster_id=${mcId}`)
                .then(response => {
                    if (!response.ok) { throw new Error('Network response was not ok'); }
                    return response.json();
                })
                .then(data => {
                    siteSelect.innerHTML = '<option value="">-- Pilih Site --</option>';
                    data.forEach(site => {
                        const option = new Option(site.site_name, site.id);
                        siteSelect.add(option);
                    });
                    siteSelect.disabled = false;
                })
                .catch(error => {
                    console.error('Error fetching sites:', error);
                    siteSelect.innerHTML = '<option value="">-- Gagal memuat site --</option>';
                    showMessageBox('Gagal memuat data Site. Silakan coba lagi atau hubungi administrator.');
                });
        });

        sudahBeliSelect.addEventListener('change', function() {
            if (this.value === 'Ya') {
                lokasiBeliDiv.classList.remove('hidden');
                tertarikBeliDiv.classList.add('hidden');
                lokasiBeliInput.required = true;
            } else {
                lokasiBeliDiv.classList.add('hidden');
                tertarikBeliDiv.classList.remove('hidden');
                lokasiBeliInput.required = false;
                lokasiBeliInput.value = '';
            }
        });
        // Panggil saat halaman dimuat untuk menyesuaikan tampilan awal
        sudahBeliSelect.dispatchEvent(new Event('change'));
        
        // Auto-load sites based on existing micro_cluster_id on page load
        const initialMicroClusterId = '<?php echo (int)($event_data['micro_cluster_id'] ?? 0); ?>'; // Ensure it's an int
        const initialSiteId = '<?php echo (int)($event_data['site_id'] ?? 0); ?>'; // Ensure it's an int
        if (initialMicroClusterId > 0) { // Only fetch if a valid MC ID exists
            fetch(`api_helper.php?action=get_sites&micro_cluster_id=${initialMicroClusterId}`)
                .then(response => response.json())
                .then(data => {
                    siteSelect.innerHTML = '<option value="">-- Pilih Site --</option>';
                    data.forEach(site => {
                        const option = new Option(site.site_name, site.id);
                        siteSelect.add(option);
                    });
                    // Set the previously selected site
                    siteSelect.value = initialSiteId;
                    siteSelect.disabled = false;
                })
                .catch(error => {
                    console.error('Error re-fetching sites on load:', error);
                });
        }

        // --- Logika Kalkulasi Real-time ---
        const spInputs = {
            sp_0k: document.querySelector('input[name="sp_0k"]'),
            sp_3gb: document.querySelector('input[name="sp_3gb"]'),
            sp_5gb: document.querySelector('input[name="sp_5gb"]'),
            sp_7gb: document.querySelector('input[name="sp_7gb"]'),
            sp_100gb: document.querySelector('input[name="sp_100gb"]'),
        };
        const fwaInput = document.querySelector('input[name="fwa"]');
        const fwa_5g = document.querySelector('input[name="fwa_5g"]');
        const moboPaketInput = document.querySelector('input[name="mobo_paket"]');
        const costInput = document.querySelector('input[name="cost"]');

        const displayQSC = document.getElementById('calc_jumlah_qsc');
        const displayBenefitSP = document.getElementById('calc_benefit_sp');
        const displayBenefitFWA = document.getElementById('calc_benefit_fwa');
        const displayBenefitTotal = document.getElementById('calc_benefit_total');
        const displayRatio = document.getElementById('calc_ratio');

        const hiddenJumlahQSC = document.getElementById('hidden_jumlah_qsc');
        const hiddenBenefitSP = document.getElementById('hidden_benefit_sp');
        const hiddenBenefitFWA = document.getElementById('hidden_benefit_fwa');
        const hiddenBenefitTotal = document.getElementById('hidden_benefit_total');
        const hiddenRatio = document.getElementById('hidden_ratio');

        const hajiInput = document.querySelector('input[name="hit_haji_umroh"]');
        const reloadInput = document.querySelector('input[name="reload"]');

        const calculationInputs = [...Object.values(spInputs), fwaInput, fwa_5g, hajiInput, reloadInput, moboPaketInput, costInput];

        function updateCalculations() {
            const getVal = (input) => parseInt(input.value) || 0;
            
            const jumlahQSC = getVal(spInputs.sp_0k) + getVal(spInputs.sp_3gb) + getVal(spInputs.sp_5gb) + getVal(spInputs.sp_7gb) + getVal(spInputs.sp_100gb);
            const benefitSP = 
                (getVal(spInputs.sp_0k) * 10000) + 
                (getVal(spInputs.sp_3gb) * 27000) + 
                (getVal(spInputs.sp_5gb) * 35000) + 
                (getVal(spInputs.sp_7gb) * 39000);
            
            const benefitFWA = getVal(fwaInput) * 150000;
            const benefitFWA_5G = getVal(fwa_5g) * 750000;
            
            const benefitTotal = 
                benefitSP + 
                benefitFWA + 
                benefitFWA_5G + 
                getVal(moboPaketInput);
                
            const totalCost = getVal(costInput);
            const ratio = benefitTotal > 0 ? (totalCost / benefitTotal) * 100 : 0;

            displayQSC.value = jumlahQSC.toLocaleString('id-ID');
            displayBenefitSP.value = 'Rp ' + benefitSP.toLocaleString('id-ID');
            displayBenefitFWA.value = 'Rp ' + (benefitFWA + benefitFWA_5G).toLocaleString('id-ID');
            displayBenefitTotal.value = 'Rp ' + benefitTotal.toLocaleString('id-ID');
            displayRatio.value = ratio.toFixed(2) + ' %';

            hiddenJumlahQSC.value = jumlahQSC;
            hiddenBenefitSP.value = benefitSP;
            hiddenBenefitFWA.value = benefitFWA + benefitFWA_5G;
            hiddenBenefitTotal.value = benefitTotal;
            hiddenRatio.value = ratio.toFixed(2);
        }

        calculationInputs.forEach(input => {
            input.addEventListener('input', updateCalculations);
        });

        updateCalculations(); // Panggil saat halaman pertama kali dimuat

        // --- Custom Validation for Checkbox Groups ---
        function validateCheckboxGroup(checkboxes, errorElement) {
            const isChecked = Array.from(checkboxes).some(checkbox => checkbox.checked);
            if (!isChecked) {
                errorElement.classList.remove('hidden');
                return false;
            } else {
                errorElement.classList.add('hidden');
                return true;
            }
        }

        providerDigunakanCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => validateCheckboxGroup(providerDigunakanCheckboxes, providerDigunakanError));
        });
        providerTerbaikCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => validateCheckboxGroup(providerTerbaikCheckboxes, providerTerbaikError));
        });

        // --- Form Submission Validation ---
        eventForm.addEventListener('submit', function(event) {
            let isValid = true;

            const isProviderDigunakanValid = validateCheckboxGroup(providerDigunakanCheckboxes, providerDigunakanError);
            const isProviderTerbaikValid = validateCheckboxGroup(providerTerbaikCheckboxes, providerTerbaikError);

            if (!isProviderDigunakanValid || !isProviderTerbaikValid) {
                isValid = false;
            }
            
            // Cek apakah file foto di-upload jika sebelumnya tidak ada, atau jika user mengubahnya
            // Jika sebelumnya ada foto, dan user tidak mengupload ulang, itu tidak masalah.
            // Jika sebelumnya tidak ada foto, dan user tidak mengupload, maka error.
            // Perbaiki di sini:
            const hasExistingPhoto = '<?php echo !empty($event_data['foto_event_url']); ?>';
            if (!hasExistingPhoto && fotoInput.files.length === 0) {
                showMessageBox('File foto event wajib diunggah.');
                isValid = false;
            } else if (fotoInput.files.length > 0 && fotoInput.files[0].size > 7 * 1024 * 1024) {
                showMessageBox('Ukuran file foto melebihi 7MB!');
                isValid = false;
            }


            if (!isValid) {
                event.preventDefault(); // Prevent form submission if validation fails
            }
        });
    });
    </script>
    </body>
    </html>
