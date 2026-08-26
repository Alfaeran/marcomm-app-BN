<?php
// admin_import_matpro_activities.php
// Menggabungkan form import dan proses import aktivitas Matpro ke dalam satu file.

// Mengaktifkan output buffering untuk mencegah header error jika ada output sebelum header()
ob_start();

// Mengatur tampilan error untuk debugging.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Mengatur batas waktu eksekusi skrip (misal 15 menit untuk import besar dengan foto)
set_time_limit(900);

require_once 'config/database.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Fungsi untuk membersihkan direktori sementara.
 */
function deleteDir($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDir($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

/**
 * Fungsi untuk mengekstrak file arsip.
 */
function extract_archive($archivePath, $destinationPath) {
    $zip = new ZipArchive;
    if ($zip->open($archivePath) === TRUE) {
        // Buat folder tujuan jika belum ada
        if (!is_dir($destinationPath)) {
            mkdir($destinationPath, 0777, true);
        }
        $zip->extractTo($destinationPath);
        $zip->close();
        return true;
    } else {
        return false;
    }
}

/**
 * Fungsi untuk mengompres gambar (sama seperti di input_form.php)
 */
function compress_image($source, $destination, $quality) {
    $info = getimagesize($source);
    if ($info === false) return false;

    $image = null;
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        if ($image) {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }
    } else {
        return false; // Tipe file tidak didukung
    }

    if (!$image) return false;

    if ($info['mime'] == 'image/png') {
        $quality = (int)(($quality / 100) * 9); // Kualitas untuk PNG (0-9)
        return imagepng($image, $destination, $quality);
    } else {
        return imagejpeg($image, $destination, $quality);
    }
}


// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

// Inisialisasi pesan
$success_message = '';
$error_message = '';
$warning_message = '';
$processed_rows_count = 0;
$skipped_rows_count = 0;
$error_rows_details = [];

// --- LOGIKA PEMROSESAN FORM SUBMISSION (POST REQUEST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['archive_file'])) {
    $archive_file = $_FILES['archive_file']['tmp_name'];
    $archive_name = $_FILES['archive_file']['name'];
    $archive_ext = strtolower(pathinfo($archive_name, PATHINFO_EXTENSION));

    $temp_dir_base = '../temp_uploads/';
    $unique_temp_folder = $temp_dir_base . uniqid('matpro_import_');
    $upload_dir_matpro_activities = '../uploads/matpro_activities/';

    // Pastikan direktori upload ada
    if (!is_dir($upload_dir_matpro_activities)) { mkdir($upload_dir_matpro_activities, 0755, true); }
    if (!is_dir($temp_dir_base)) { mkdir($temp_dir_base, 0755, true); }

    $mysqli->begin_transaction();

    try {
        // 1. Validasi File Arsip
        if ($_FILES['archive_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Kesalahan upload file arsip: " . $_FILES['archive_file']['error']);
        }
        if ($archive_ext !== 'zip') {
            throw new Exception("Hanya file .ZIP yang diizinkan untuk diunggah.");
        }

        // 2. Ekstrak Arsip
        if (!extract_archive($archive_file, $unique_temp_folder)) {
            throw new Exception("Gagal mengekstrak file ZIP. Pastikan file tidak rusak.");
        }

        // 3. Cari file Excel utama (asumsi namanya 'data_aktivitas_matpro.xlsx')
        $excel_file_path = $unique_temp_folder . '/data_aktivitas_matpro.xlsx';
        if (!file_exists($excel_file_path)) {
            throw new Exception("File Excel 'data_aktivitas_matpro.xlsx' tidak ditemukan di dalam arsip ZIP.");
        }

        $spreadsheet = IOFactory::load($excel_file_path);
        $sheet = $spreadsheet->getActiveSheet();

        // 4. Proses setiap baris di Excel
        foreach ($sheet->getRowIterator(2) as $row) { // Mulai dari baris ke-2 (setelah header)
            $rowIndex = $row->getRowIndex();
            try {
                $username_excel = trim($sheet->getCell('A' . $rowIndex)->getValue());
                $project_name_excel = trim($sheet->getCell('B' . $rowIndex)->getValue());
                $type_name_excel = trim($sheet->getCell('C' . $rowIndex)->getValue());
                $branch_name_excel = trim($sheet->getCell('D' . $rowIndex)->getValue());
                $micro_cluster_name_excel = trim($sheet->getCell('E' . $rowIndex)->getValue());
                $site_id_code_excel = trim($sheet->getCell('F' . $rowIndex)->getValue());
                $outlet_id_code_excel = trim($sheet->getCell('G' . $rowIndex)->getValue());
                $quantity_excel = (int)trim($sheet->getCell('H' . $rowIndex)->getValue());
                $latitude_excel = (float)trim($sheet->getCell('I' . $rowIndex)->getValue());
                $longitude_excel = (float)trim($sheet->getCell('J' . $rowIndex)->getValue());
                $photo_before_filename = trim($sheet->getCell('K' . $rowIndex)->getValue());
                $photo_after_filename = trim($sheet->getCell('L' . $rowIndex)->getValue());

                // Lewati baris kosong total
                if (empty($username_excel) && empty($project_name_excel) && empty($type_name_excel) && empty($branch_name_excel) && empty($site_id_code_excel) && empty($outlet_id_code_excel)) {
                    $skipped_rows_count++;
                    continue;
                }

                // Validasi data wajib
                if (empty($username_excel) || empty($project_name_excel) || empty($type_name_excel) || empty($branch_name_excel) || empty($site_id_code_excel) || empty($outlet_id_code_excel) || empty($quantity_excel) || empty($latitude_excel) || empty($longitude_excel) || empty($photo_before_filename) || empty($photo_after_filename)) {
                    throw new Exception("Data tidak lengkap. Pastikan semua kolom wajib diisi.");
                }
                if ($quantity_excel <= 0) {
                    throw new Exception("Kuantitas harus lebih besar dari 0.");
                }

                // Lookup IDs
                $user_id_db = null;
                $stmt_user = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND role = 'user'");
                if (!$stmt_user) { throw new Exception("Gagal menyiapkan query user: " . $mysqli->error); }
                $stmt_user->bind_param("s", $username_excel);
                $stmt_user->execute();
                $res_user = $stmt_user->get_result();
                if ($res_user->num_rows === 0) { throw new Exception("User '" . htmlspecialchars($username_excel) . "' tidak ditemukan atau bukan user."); }
                $user_id_db = $res_user->fetch_assoc()['id'];
                $stmt_user->close();

                $project_id_db = null;
                $type_id_db = null;
                
                $branch_id_db = null;
                $stmt_branch = $mysqli->prepare("SELECT id FROM branches WHERE nama_branch = ?");
                if (!$stmt_branch) { throw new Exception("Gagal menyiapkan query branch: " . $mysqli->error); }
                $stmt_branch->bind_param("s", $branch_name_excel);
                $stmt_branch->execute();
                $res_branch = $stmt_branch->get_result();
                if ($res_branch->num_rows === 0) { throw new Exception("Branch '" . htmlspecialchars($branch_name_excel) . "' tidak ditemukan."); }
                $branch_id_db = $res_branch->fetch_assoc()['id'];
                $stmt_branch->close();

                $micro_cluster_id_db = null;
                if (!empty($micro_cluster_name_excel)) {
                    $stmt_mc = $mysqli->prepare("SELECT id FROM micro_clusters WHERE nama_micro_cluster = ? AND branch_id = ?");
                    if (!$stmt_mc) { throw new Exception("Gagal menyiapkan query micro cluster: " . $mysqli->error); }
                    $stmt_mc->bind_param("si", $micro_cluster_name_excel, $branch_id_db);
                    $stmt_mc->execute();
                    $res_mc = $stmt_mc->get_result();
                    if ($res_mc->num_rows === 0) { throw new Exception("Micro Cluster '" . htmlspecialchars($micro_cluster_name_excel) . "' tidak ditemukan di Branch '" . htmlspecialchars($branch_name_excel) . "'. Kosongkan kolom jika aktivitas level Branch."); }
                    $micro_cluster_id_db = $res_mc->fetch_assoc()['id'];
                    $stmt_mc->close();
                }

                $site_id_db = null;
                $stmt_site = $mysqli->prepare("SELECT id FROM sites WHERE site_id = ?");
                if (!$stmt_site) { throw new Exception("Gagal menyiapkan query site: " . $mysqli->error); }
                $stmt_site->bind_param("s", $site_id_code_excel);
                $stmt_site->execute();
                $res_site = $stmt_site->get_result();
                if ($res_site->num_rows === 0) { throw new Exception("Site ID '" . htmlspecialchars($site_id_code_excel) . "' tidak ditemukan."); }
                $site_id_db = $res_site->fetch_assoc()['id'];
                $stmt_site->close();

                $outlet_id_db = null;
                $stmt_outlet = $mysqli->prepare("SELECT id, nama_outlet FROM outlets WHERE id_outlet = ? AND site_id = ?"); 
                if (!$stmt_outlet) { throw new Exception("Gagal menyiapkan query outlet: " . $mysqli->error); }
                $stmt_outlet->bind_param("si", $outlet_id_code_excel, $site_id_db);
                $stmt_outlet->execute();
                $res_outlet = $stmt_outlet->get_result();
                if ($res_outlet->num_rows === 0) { throw new Exception("Outlet ID '" . htmlspecialchars($outlet_id_code_excel) . "' tidak ditemukan di Site '" . htmlspecialchars($site_id_code_excel) . "'."); }
                $outlet_row = $res_outlet->fetch_assoc();
                $outlet_id_db = $outlet_row['id'];
                $outlet_snapshot_name = $outlet_row['nama_outlet'];
                $stmt_outlet->close();


                // Cek dan Proses Foto
                $photo_before_path = $unique_temp_folder . '/' . $photo_before_filename;
                $photo_after_path = $unique_temp_folder . '/' . $photo_after_filename;

                if (!file_exists($photo_before_path)) { throw new Exception("File foto sebelum '" . htmlspecialchars($photo_before_filename) . "' tidak ditemukan di arsip."); }
                if (!file_exists($photo_after_path)) { throw new Exception("File foto sesudah '" . htmlspecialchars($photo_after_filename) . "' tidak ditemukan di arsip."); }

                $photo_before_url_db = 'uploads/matpro_activities/' . uniqid('matpro_before_') . '.' . pathinfo($photo_before_filename, PATHINFO_EXTENSION);
                $photo_after_url_db = 'uploads/matpro_activities/' . uniqid('matpro_after_') . '.' . pathinfo($photo_after_filename, PATHINFO_EXTENSION);

                if (!compress_image($photo_before_path, '../' . $photo_before_url_db, 75)) { throw new Exception("Gagal memproses foto sebelum."); }
                if (!compress_image($photo_after_path, '../' . $photo_after_url_db, 75)) { throw new Exception("Gagal memproses foto sesudah."); }

                // Kurangi Stok Matpro (User-specific, branch-agnostic)
                $stock_stmt_sql = "SELECT id, stock_quantity FROM matpro_stocks 
                                   WHERE project_name = ? AND type_name = ? AND user_id = ? 
                                   AND is_active = 1 LIMIT 1";
                $stock_params = [$project_name_excel, $type_name_excel, $user_id_db];
                $stock_types = "ssi";

                $stmt_stock = $mysqli->prepare($stock_stmt_sql);
                if (!$stmt_stock) { throw new Exception("Gagal menyiapkan statement cek stok: " . $mysqli->error); }
                $stmt_stock->bind_param($stock_types, ...$stock_params);
                $stmt_stock->execute();
                $stock_result = $stmt_stock->get_result();

                if ($stock_result->num_rows === 0) {
                    throw new Exception("Stok Matpro tidak ditemukan untuk kombinasi Proyek, Jenis, Branch, dan Micro Cluster (jika ada) yang dipilih.");
                }
                $current_stock = $stock_result->fetch_assoc();
                $stock_id = $current_stock['id'];
                $available_quantity = $current_stock['stock_quantity'];
                $stmt_stock->close();

                if ($available_quantity < $quantity_excel) {
                    throw new Exception("Kuantitas yang diminta ($quantity_excel) melebihi stok yang tersedia ($available_quantity).");
                }

                $new_stock_quantity = $available_quantity - $quantity_excel;
                $is_active_new = ($new_stock_quantity <= 0) ? 0 : 1;
                $stmt_update_stock = $mysqli->prepare("UPDATE matpro_stocks SET stock_quantity = ?, is_active = ?, last_updated_by = ?, last_updated_at = NOW() WHERE id = ?");
                if (!$stmt_update_stock) { throw new Exception("Gagal menyiapkan statement update stok: " . $mysqli->error); }
                $stmt_update_stock->bind_param("iiii", $new_stock_quantity, $is_active_new, $_SESSION['id'], $stock_id);
                $stmt_update_stock->execute();
                $stmt_update_stock->close();

                // Simpan Aktivitas Matpro
                $unique_id_activity = 'MATPRO-' . uniqid();
                $activity_datetime = date('Y-m-d H:i:s'); // Menggunakan waktu server saat import

                $sql_insert_activity = "INSERT INTO matpro_activities 
                                        (unique_id, user_id, activity_datetime, location_latitude, location_longitude, 
                                         branch_id, micro_cluster_id, site_id, outlet_id, outlet_snapshot_name,
                                         project_name, type_name, project_id, type_id, 
                                         qty_used, photo_before_url, photo_after_url) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_insert_activity = $mysqli->prepare($sql_insert_activity);
                if (!$stmt_insert_activity) { throw new Exception("Gagal menyiapkan statement insert aktivitas: " . $mysqli->error); }

                $bind_types_activity = "sisddiiiisssiiiss";
                $bind_params_activity = [
                    $unique_id_activity, $user_id_db, $activity_datetime, $latitude_excel, $longitude_excel,
                    $branch_id_db, $micro_cluster_id_db, $site_id_db, $outlet_id_db, $outlet_snapshot_name,
                    $project_name_excel, $type_name_excel, $project_id_db, $type_id_db,
                    $quantity_excel, $photo_before_url_db, $photo_after_url_db
                ];

                // Penyesuaian binding untuk micro_cluster_id yang bisa NULL
                if ($micro_cluster_id_db === null) {
                    $sql_insert_activity_no_mc = "INSERT INTO matpro_activities 
                                        (unique_id, user_id, activity_datetime, location_latitude, location_longitude, 
                                         branch_id, micro_cluster_id, site_id, outlet_id, outlet_snapshot_name,
                                         project_name, type_name, project_id, type_id, 
                                         qty_used, photo_before_url, photo_after_url) 
                                        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"; // NULL untuk micro_cluster_id
                    $stmt_insert_activity_no_mc = $mysqli->prepare($sql_insert_activity_no_mc);
                    if (!$stmt_insert_activity_no_mc) { throw new Exception("Gagal menyiapkan statement insert aktivitas (no MC): " . $mysqli->error); }
                    
                    // Sesuaikan parameter untuk query tanpa MC
                    $bind_params_activity_no_mc = [
                        $unique_id_activity, $user_id_db, $activity_datetime, $latitude_excel, $longitude_excel,
                        $branch_id_db, /* NULL */ $site_id_db, $outlet_id_db, $outlet_snapshot_name,
                        $project_name_excel, $type_name_excel, $project_id_db, $type_id_db,
                        $quantity_excel, $photo_before_url_db, $photo_after_url_db
                    ];
                    $stmt_insert_activity_no_mc->bind_param("sisddiiisssiiiss", ...$bind_params_activity_no_mc);
                    $stmt_insert_activity_no_mc->execute();
                    $stmt_insert_activity_no_mc->close();
                } else {
                    $stmt_insert_activity->bind_param($bind_types_activity, ...$bind_params_activity);
                    $stmt_insert_activity->execute();
                    $stmt_insert_activity->close();
                }

                $processed_rows_count++;

            } catch (Exception $row_e) {
                // Catat error per baris, tapi jangan hentikan transaksi utama dulu
                $error_rows_details[] = "Baris " . $rowIndex . ": " . $row_e->getMessage();
            }
        }

        if (!empty($error_rows_details)) {
            // Jika ada error di baris, rollback semua dan laporkan
            $mysqli->rollback();
            $error_message = "Import gagal karena beberapa kesalahan:<br>" . implode("<br>", $error_rows_details);
            // Hapus file yang mungkin sudah terupload sebagian jika terjadi rollback
            deleteDir($upload_dir_matpro_activities); // Hapus semua yang sudah diupload untuk aktivitas ini
        } else {
            // Jika semua baris berhasil diproses
            $mysqli->commit();
            $_SESSION['success_message'] = "Sukses! " . $processed_rows_count . " aktivitas Matpro berhasil diimpor.";
        }

    } catch (Exception $e) {
        // Tangani error umum (misal masalah ZIP, Excel tidak ditemukan)
        $mysqli->rollback();
        $error_message = "Proses import gagal total: " . $e->getMessage();
    } finally {
        // Selalu bersihkan folder sementara
        deleteDir($unique_temp_folder);
    }
    
    // Redirect kembali ke halaman ini untuk menampilkan pesan
    $_SESSION['success_message'] = $success_message; // Pastikan pesan sukses juga masuk sesi
    $_SESSION['error_message'] = $error_message;
    $_SESSION['warning_message'] = $warning_message; // Jika ada warning
    header("location: admin_import_matpro_activities.php");
    exit();
}
// --- AKHIR LOGIKA PEMROSESAN FORM SUBMISSION ---

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Import Aktivitas Matpro - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
</head>
<body class="min-h-screen">
    <!-- Aurora Background Blobs -->
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>

    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar Overlay -->
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>

        <!-- Sidebar -->
        <?php include 'components/sidebar_admin.php'; ?>

        <!-- Main Content -->
        <main class="flex-grow p-4 lg:p-10 lg:ml-72 overflow-y-auto min-w-0">
            <!-- Header -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 text-slate-600 bg-white shadow-sm border rounded-lg relative z-[60] active:scale-95 transition-transform"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Import Aktivitas Matpro</h2>
                        <p class="text-slate-500 font-medium text-sm">Unggah riwayat pemasangan matpro beserta foto bukti (Arsip ZIP).</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a href="admin_laporan_matpro.php" class="px-5 py-2.5 bg-white text-slate-600 font-bold text-xs rounded-xl border border-slate-200 hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </header>

            <div class="max-w-5xl mx-auto space-y-8">
                <!-- Status Messages -->
                <?php if (!empty($success_message)): ?>
                <div class="glass-card border-l-4 border-emerald-500 text-emerald-700 p-6 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                    <div class="h-10 w-10 rounded-full bg-emerald-100 flex items-center justify-center shrink-0">
                        <i class="fas fa-check"></i>
                    </div>
                    <p class="font-bold text-sm"><?php echo $success_message; ?></p>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                <div class="glass-card border-l-4 border-red-500 text-red-700 p-6 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                    <div class="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <p class="font-bold text-sm"><?php echo $error_message; ?></p>
                </div>
                <?php endif; ?>

                <!-- ZIP Requirement Info -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Instruction Card -->
                        <div class="glass-card rounded-3xl p-8 border border-white/50 bg-blue-50/30 h-full">
                            <div class="flex items-start gap-5">
                                <div class="h-12 w-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center shrink-0 shadow-sm">
                                    <i class="fas fa-file-archive text-xl"></i>
                                </div>
                                <div class="flex-grow">
                                    <h3 class="text-lg font-bold text-slate-800 mb-4">Struktur Arsip ZIP</h3>
                                    <div class="bg-white/60 rounded-2xl p-6 border border-blue-100 font-mono text-xs text-slate-700 space-y-2 mb-6 shadow-inner">
                                        <div class="flex items-center gap-3"><i class="fas fa-folder-open text-amber-400"></i> archive_name.zip</div>
                                        <div class="flex items-center gap-3 ml-6"><i class="fas fa-file-excel text-emerald-600"></i> data_aktivitas_matpro.xlsx <span class="text-[10px] text-slate-400 font-sans italic">(Wajib ada)</span></div>
                                        <div class="flex items-center gap-3 ml-6"><i class="fas fa-file-image text-blue-400"></i> foto_sebelum_1.jpg</div>
                                        <div class="flex items-center gap-3 ml-6"><i class="fas fa-file-image text-blue-400"></i> foto_sesudah_1.jpg</div>
                                        <div class="flex items-center gap-3 ml-6 text-slate-300">... (foto lainnya sesuai Excel)</div>
                                    </div>
                                    
                                    <ul class="space-y-3 text-sm text-slate-600 font-medium">
                                        <li class="flex items-start gap-2">
                                            <i class="fas fa-check-circle text-blue-500 text-[10px] mt-1.5"></i>
                                            <span>Pastikan nama file foto di Excel <strong class="text-slate-800 italic">sama persis</strong> dengan di dalam ZIP.</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <i class="fas fa-check-circle text-blue-500 text-[10px] mt-1.5"></i>
                                            <span>Site ID dan ID Outlet harus sudah terdaftar di sistem.</span>
                                        </li>
                                        <li class="flex items-start gap-2 text-red-600">
                                            <i class="fas fa-exclamation-circle text-[10px] mt-1.5"></i>
                                            <span>Satu baris gagal akan membatalkan <strong class="uppercase italic">Seluruh</strong> import.</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <!-- Quick Guide Card -->
                        <div class="glass-card rounded-3xl p-8 border border-white/50 bg-white/40 h-full">
                            <h4 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-6 border-b border-slate-100 pb-3">Format Kolom Excel</h4>
                            <div class="space-y-4">
                                <div class="p-3 bg-white/60 rounded-xl border border-slate-100">
                                    <p class="text-[10px] text-slate-400 font-black mb-1">A - C</p>
                                    <p class="text-xs font-bold text-slate-700">Username, Proyek, Jenis</p>
                                </div>
                                <div class="p-3 bg-white/60 rounded-xl border border-slate-100">
                                    <p class="text-[10px] text-slate-400 font-black mb-1">D - E</p>
                                    <p class="text-xs font-bold text-slate-700">Branch, Cluster (Ops)</p>
                                </div>
                                <div class="p-3 bg-white/60 rounded-xl border border-slate-100">
                                    <p class="text-[10px] text-slate-400 font-black mb-1">F - G</p>
                                    <p class="text-xs font-bold text-slate-700">Site ID, ID Outlet</p>
                                </div>
                                <div class="p-3 bg-white/60 rounded-xl border border-slate-100">
                                    <p class="text-[10px] text-slate-400 font-black mb-1">H - L</p>
                                    <p class="text-xs font-bold text-slate-700">QTY, Lat, Long, Foto FB, Foto FA</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Card -->
                <div class="glass-card rounded-3xl p-10 border border-white/50 shadow-xl shadow-blue-900/5">
                    <form action="admin_import_matpro_activities.php" method="POST" enctype="multipart/form-data" class="space-y-8">
                        <div class="space-y-4">
                            <label for="archive_file" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Unggah Arsip ZIP Aktivitas</label>
                            <div class="relative group">
                                <input type="file" name="archive_file" id="archive_file" required accept=".zip" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                                <div class="w-full p-16 border-2 border-dashed border-slate-200 rounded-3xl bg-slate-50/50 group-hover:bg-blue-50/50 group-hover:border-blue-200 transition-all flex flex-col items-center justify-center gap-5 text-center">
                                    <div class="h-24 w-24 bg-white border border-slate-100 rounded-3xl flex items-center justify-center text-slate-400 group-hover:text-amber-500 group-hover:scale-110 group-hover:shadow-lg transition-all">
                                        <i class="fas fa-file-archive text-5xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-lg font-bold text-slate-700">Klik atau seret file ZIP ke sini</p>
                                        <p class="text-sm text-slate-400 mt-2 max-w-sm mx-auto">Pastikan file berisi data_aktivitas_matpro.xlsx dan seluruh foto pendukung.</p>
                                    </div>
                                    <div id="file-name" class="mt-4 px-6 py-2 bg-amber-50 text-amber-700 text-xs font-black rounded-full ring-1 ring-amber-200 hidden"></div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col md:flex-row items-center justify-between gap-6 pt-8 border-t border-slate-100">
                            <a href="process/download_matpro_activity_template.php" class="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-2 group">
                                <i class="fas fa-download p-2 bg-blue-50 rounded-lg group-hover:bg-blue-100 transition-all"></i>
                                Unduh Template & Panduan
                            </a>
                            <button type="submit" class="w-full md:w-auto px-12 py-5 bg-slate-800 text-white font-black text-sm rounded-2xl hover:bg-slate-900 shadow-xl shadow-slate-200 active:scale-95 transition-all flex items-center justify-center gap-3">
                                <i class="fas fa-cloud-upload-alt"></i> Ekstrak & Import Data
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.getElementById('archive_file').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            const display = document.getElementById('file-name');
            if (fileName) {
                display.textContent = '📦 Arsip Terpilih: ' + fileName;
                display.classList.remove('hidden');
            } else {
                display.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>
