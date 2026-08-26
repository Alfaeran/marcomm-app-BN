<?php
// user_import_matpro.php
ob_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(900);

require_once 'config/database.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function deleteDir($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDir($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

function extract_archive($archivePath, $destinationPath) {
    $zip = new ZipArchive;
    if ($zip->open($archivePath) === TRUE) {
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
        return false;
    }

    if (!$image) return false;

    if ($info['mime'] == 'image/png') {
        $quality = (int)(($quality / 100) * 9);
        return imagepng($image, $destination, $quality);
    } else {
        return imagejpeg($image, $destination, $quality);
    }
}

// Cek hak akses user
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}

$user_id_session = $_SESSION["id"];

// Enforce Restrictions
$restriction = is_bulk_import_allowed($mysqli);
if (!$restriction['allowed']) {
    $_SESSION['error_message'] = $restriction['message'];
    header("location: dashboard_user.php"); // Atau ke home user
    exit;
}

$success_message = '';
$error_message = '';
$processed_rows_count = 0;
$error_rows_details = [];

// Jadwal Bulky
$start_time = get_setting($mysqli, 'bulk_import_start_time') ?: '00:00';
$end_time   = get_setting($mysqli, 'bulk_import_end_time') ?: '23:59';
$start_day  = (int)(get_setting($mysqli, 'bulk_import_start_day') ?: 1);
$end_day    = (int)(get_setting($mysqli, 'bulk_import_end_day') ?: 31);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['archive_file'])) {
    $archive_file = $_FILES['archive_file']['tmp_name'];
    $archive_name = $_FILES['archive_file']['name'];
    $archive_ext = strtolower(pathinfo($archive_name, PATHINFO_EXTENSION));

    $temp_dir_base = 'temp_uploads/';
    $unique_temp_folder = $temp_dir_base . uniqid('matpro_user_import_');
    $upload_dir_matpro_activities = 'uploads/matpro_activities/';

    if (!is_dir($upload_dir_matpro_activities)) { mkdir($upload_dir_matpro_activities, 0755, true); }
    if (!is_dir($temp_dir_base)) { mkdir($temp_dir_base, 0755, true); }

    $mysqli->begin_transaction();

    try {
        if ($_FILES['archive_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Kesalahan upload: " . $_FILES['archive_file']['error']);
        }
        if ($archive_ext !== 'zip') {
            throw new Exception("Hanya file .ZIP yang diizinkan.");
        }

        if (!extract_archive($archive_file, $unique_temp_folder)) {
            throw new Exception("Gagal mengekstrak ZIP.");
        }

        $excel_file_path = $unique_temp_folder . '/data_aktivitas_matpro.xlsx';
        if (!file_exists($excel_file_path)) {
            throw new Exception("File 'data_aktivitas_matpro.xlsx' tidak ditemukan dalam ZIP.");
        }

        $reader = IOFactory::createReaderForFile($excel_file_path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($excel_file_path);
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($sheet->getRowIterator(2) as $row) {
            $rowIndex = $row->getRowIndex();
            try {
                $project_name_excel = trim($sheet->getCell('A' . $rowIndex)->getValue());
                $type_name_excel = trim($sheet->getCell('B' . $rowIndex)->getValue());
                $branch_name_excel = trim($sheet->getCell('C' . $rowIndex)->getValue());
                $micro_cluster_name_excel = trim($sheet->getCell('D' . $rowIndex)->getValue());
                $site_id_code_excel = trim($sheet->getCell('E' . $rowIndex)->getValue());
                $outlet_id_code_excel = trim($sheet->getCell('F' . $rowIndex)->getValue());
                $quantity_excel = (int)trim($sheet->getCell('G' . $rowIndex)->getValue());
                $latitude_excel = (float)trim($sheet->getCell('H' . $rowIndex)->getValue());
                $longitude_excel = (float)trim($sheet->getCell('I' . $rowIndex)->getValue());
                $photo_before_filename = trim($sheet->getCell('J' . $rowIndex)->getValue());
                $photo_after_filename = trim($sheet->getCell('K' . $rowIndex)->getValue());

                if (empty($project_name_excel) && empty($type_name_excel) && empty($site_id_code_excel)) continue;

                if (empty($project_name_excel) || empty($type_name_excel) || empty($branch_name_excel) || empty($site_id_code_excel) || empty($outlet_id_code_excel) || empty($quantity_excel) || empty($latitude_excel) || empty($longitude_excel) || empty($photo_before_filename) || empty($photo_after_filename)) {
                    throw new Exception("Data tidak lengkap di baris $rowIndex.");
                }

                // Lookup Project & Type (Master tables missing, use null for IDs)
                $project_id_db = null;
                $type_id_db = null;

                // Lookup Branch
                $stmt_branch = $mysqli->prepare("SELECT id FROM branches WHERE nama_branch = ?");
                $stmt_branch->bind_param("s", $branch_name_excel);
                $stmt_branch->execute();
                $res_branch = $stmt_branch->get_result();
                if ($res_branch->num_rows === 0) throw new Exception("Branch '$branch_name_excel' tidak ditemukan.");
                $branch_id_db = $res_branch->fetch_assoc()['id'];

                // Lookup Micro Cluster
                $micro_cluster_id_db = null;
                if (!empty($micro_cluster_name_excel)) {
                    $stmt_mc = $mysqli->prepare("SELECT id FROM micro_clusters WHERE nama_micro_cluster = ? AND branch_id = ?");
                    $stmt_mc->bind_param("si", $micro_cluster_name_excel, $branch_id_db);
                    $stmt_mc->execute();
                    $res_mc = $stmt_mc->get_result();
                    if ($res_mc->num_rows === 0) throw new Exception("Micro Cluster '$micro_cluster_name_excel' tidak ditemukan di branch ini.");
                    $micro_cluster_id_db = $res_mc->fetch_assoc()['id'];
                }

                // Lookup Site
                $stmt_site = $mysqli->prepare("SELECT id FROM sites WHERE site_id = ?");
                $stmt_site->bind_param("s", $site_id_code_excel);
                $stmt_site->execute();
                $res_site = $stmt_site->get_result();
                if ($res_site->num_rows === 0) throw new Exception("Site ID '$site_id_code_excel' tidak ditemukan.");
                $site_id_db = $res_site->fetch_assoc()['id'];

                // Lookup Outlet
                $stmt_outlet = $mysqli->prepare("SELECT id, nama_outlet FROM outlets WHERE id_outlet = ? AND site_id = ?");
                $stmt_outlet->bind_param("si", $outlet_id_code_excel, $site_id_db);
                $stmt_outlet->execute();
                $res_outlet = $stmt_outlet->get_result();
                if ($res_outlet->num_rows === 0) throw new Exception("Outlet '$outlet_id_code_excel' tidak ditemukan di site ini.");
                $outlet_data = $res_outlet->fetch_assoc();
                $outlet_id_db = $outlet_data['id'];
                $outlet_snapshot_name = $outlet_data['nama_outlet'];

                // Photos
                $photo_before_path = $unique_temp_folder . '/' . $photo_before_filename;
                $photo_after_path = $unique_temp_folder . '/' . $photo_after_filename;
                if (!file_exists($photo_before_path)) throw new Exception("Foto sebelum '$photo_before_filename' tidak ada.");
                if (!file_exists($photo_after_path)) throw new Exception("Foto sesudah '$photo_after_filename' tidak ada.");

                $pb_name = 'uploads/matpro_activities/' . uniqid('matpro_b_') . '.' . pathinfo($photo_before_filename, PATHINFO_EXTENSION);
                $pa_name = 'uploads/matpro_activities/' . uniqid('matpro_a_') . '.' . pathinfo($photo_after_filename, PATHINFO_EXTENSION);

                if (!compress_image($photo_before_path, $pb_name, 75)) throw new Exception("Gagal kompres foto sebelum.");
                if (!compress_image($photo_after_path, $pa_name, 75)) throw new Exception("Gagal kompres foto sesudah.");

                // Stock Check & Update (User-specific, branch-agnostic)
                $sql_stock = "SELECT id, stock_quantity FROM matpro_stocks 
                             WHERE project_name = ? AND type_name = ? AND user_id = ? 
                             AND is_active = 1 LIMIT 1";

                $stmt_stock = $mysqli->prepare($sql_stock);
                $stmt_stock->bind_param("ssi", $project_name_excel, $type_name_excel, $_SESSION['id']);
                $stmt_stock->execute();
                $stock_res = $stmt_stock->get_result();
                if ($stock_res->num_rows === 0) throw new Exception("Stok tidak ditemukan untuk kombinasi ini.");
                $stock = $stock_res->fetch_assoc();
                if ($stock['stock_quantity'] < $quantity_excel) throw new Exception("Stok tidak cukup (Sisa: {$stock['stock_quantity']}).");

                $new_qty = $stock['stock_quantity'] - $quantity_excel;
                $is_active_new = ($new_qty <= 0) ? 0 : 1;
                $stmt_up_stock = $mysqli->prepare("UPDATE matpro_stocks SET stock_quantity = ?, is_active = ?, last_updated_by = ?, last_updated_at = NOW() WHERE id = ?");
                $stmt_up_stock->bind_param("iiii", $new_qty, $is_active_new, $user_id_session, $stock['id']);
                $stmt_up_stock->execute();

                // Activity Insert
                $unique_id = 'MATPRO-' . strtoupper(uniqid());
                $sql_ins = "INSERT INTO matpro_activities 
                            (unique_id, user_id, activity_datetime, location_latitude, location_longitude, 
                             branch_id, micro_cluster_id, site_id, outlet_id, outlet_snapshot_name, 
                             project_name, type_name, project_id, type_id, qty_used, photo_before_url, photo_after_url) 
                            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_ins = $mysqli->prepare($sql_ins);
                $stmt_ins->bind_param("siddiiiisssiiiss", 
                    $unique_id, $user_id_session, $latitude_excel, $longitude_excel, 
                    $branch_id_db, $micro_cluster_id_db, $site_id_db, $outlet_id_db, $outlet_snapshot_name,
                    $project_name_excel, $type_name_excel, $project_id_db, $type_id_db, 
                    $quantity_excel, $pb_name, $pa_name);
                $stmt_ins->execute();

                $processed_rows_count++;
            } catch (Exception $row_e) {
                $error_rows_details[] = "Baris $rowIndex: " . $row_e->getMessage();
            }
        }

        if (!empty($error_rows_details)) {
            $mysqli->rollback();
            $total_errors = count($error_rows_details);
            $display_errors = array_slice($error_rows_details, 0, 50);
            $error_message = "Import gagal ($total_errors kesalahan):<br>" . implode("<br>", $display_errors);
            if ($total_errors > 50) {
                $error_message .= "<br>... dan " . ($total_errors - 50) . " kesalahan lainnya. Mohon cek file Excel Anda.";
            }
        } else {
            $mysqli->commit();
            $_SESSION['success_message'] = "Sukses! $processed_rows_count aktivitas berhasil diimpor.";
            header("location: user_import_matpro.php");
            exit();
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $error_message = "Gagal total: " . $e->getMessage();
    } finally {
        deleteDir($unique_temp_folder);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Bulk Import Matpro - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
</head>
<body class="min-h-screen">
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>

    <div class="flex h-screen overflow-hidden">
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>
        <?php include 'components/sidebar_user.php'; ?>

        <main class="flex-grow p-4 lg:p-10 lg:ml-72 overflow-y-auto">
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 text-slate-600 bg-white shadow-sm border rounded-lg active:scale-95 transition-transform"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Bulk Import Matpro</h2>
                        <p class="text-slate-500 font-medium text-sm">Upload banyak aktivitas sekaligus via ZIP.</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a href="guide_bulk_import_matpro.php" class="px-5 py-2.5 bg-blue-600 text-white font-bold text-xs rounded-xl shadow-lg shadow-blue-200 flex items-center gap-2">
                        <i class="fas fa-question-circle"></i> Lihat Tutorial
                    </a>
                </div>
            </header>

            <div class="max-w-4xl mx-auto space-y-8">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="p-4 bg-emerald-100 text-emerald-700 rounded-2xl font-bold mb-4">
                        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="p-4 bg-red-100 text-red-700 rounded-2xl font-bold mb-4">
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <div class="glass-card rounded-3xl p-10 border border-white/50 shadow-xl">
                    <form action="user_import_matpro.php" method="POST" enctype="multipart/form-data" class="space-y-8">
                        <div class="space-y-4">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Pilih File ZIP</label>
                            <div class="relative group">
                                <input type="file" name="archive_file" id="archive_file" required accept=".zip" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                                <div class="w-full p-16 border-2 border-dashed border-slate-200 rounded-3xl bg-slate-50/50 group-hover:bg-blue-50/50 transition-all flex flex-col items-center justify-center gap-5 text-center">
                                    <div class="h-20 w-20 bg-white border border-slate-100 rounded-3xl flex items-center justify-center text-slate-400 group-hover:text-blue-500 transition-all">
                                        <i class="fas fa-file-archive text-4xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-lg font-bold text-slate-700">Klik untuk upload ZIP</p>
                                        <p class="text-xs text-slate-400 mt-2">Pastikan struktur folder sesuai tutorial.</p>
                                    </div>
                                    <div id="file-name" class="hidden px-4 py-1 bg-blue-50 text-blue-700 text-[10px] font-bold rounded-full"></div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col md:flex-row items-center justify-between gap-6 pt-8 border-t border-slate-100">
                            <a href="process/download_matpro_activity_template.php" class="text-xs font-bold text-blue-600 hover:underline flex items-center gap-2">
                                <i class="fas fa-download"></i> Unduh Template Excel
                            </a>
                            <button type="submit" class="w-full md:w-auto px-10 py-4 bg-slate-800 text-white font-bold text-sm rounded-2xl hover:bg-slate-900 transition-all">
                                <i class="fas fa-upload mr-2"></i> Start Import
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
                display.textContent = '📦 ' + fileName;
                display.classList.remove('hidden');
            }
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Jadwal Operasional Import',
                    html: `Fitur Bulk Import Matpro beroperasi pada:<br><br>
                           <div class='bg-blue-50 p-4 rounded-xl border border-blue-100 text-sm'>
                           <b>Tanggal:</b> <?php echo $start_day; ?> s/d <?php echo $end_day; ?> tiap bulan<br>
                           <b>Jam:</b> <?php echo $start_time; ?> - <?php echo $end_time; ?> WIB
                           </div><br>
                           <span class='text-xs text-slate-500 font-medium'>Pastikan Anda mengunggah data sesuai dengan jadwal. Di luar jadwal fitur ini tidak dapat diakses.</span>`,
                    icon: 'info',
                    confirmButtonColor: '#3b82f6',
                    confirmButtonText: 'Mengerti',
                    customClass: {
                        popup: 'rounded-3xl',
                        title: 'text-xl font-black text-slate-800',
                        confirmButton: 'rounded-xl font-bold px-8'
                    }
                });
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
