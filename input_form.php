<?php
require_once 'config/database.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function compress_image($source, $destination, $quality) {
    $info = getimagesize($source);
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
    } else {
        return false;
    }

    if ($info['mime'] == 'image/png') {
        $quality = (int)(($quality / 100) * 9);
        return imagepng($image, $destination, $quality);
    } else {
        return imagejpeg($image, $destination, $quality);
    }
}

function ref_values($arr){
    $refs = array();
    foreach($arr as $key => $value)
        $refs[$key] = &$arr[$key];
    return $refs;
}

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}

$success_message = '';
$error_message = '';
$warning_message = '';

$user_id = $_SESSION['id'];
$user_branch_id = $_SESSION['branch_id'] ?? null;

if (empty($user_branch_id)) {
    $error_message = "Error: Akun Anda tidak terhubung ke Branch manapun. Silakan hubungi Admin.";
}

$stmt_branch_name = $mysqli->prepare("SELECT nama_branch FROM branches WHERE id = ?");
$stmt_branch_name->bind_param("i", $user_branch_id);
$stmt_branch_name->execute();
$user_branch_name = $stmt_branch_name->get_result()->fetch_assoc()['nama_branch'] ?? 'Branch Tidak Ditemukan';
$stmt_branch_name->close();

$stmt_check_mc = $mysqli->prepare("SELECT COUNT(user_id) as total FROM user_micro_clusters WHERE user_id = ?");
$stmt_check_mc->bind_param("i", $user_id);
$stmt_check_mc->execute();
$has_specific_mc = $stmt_check_mc->get_result()->fetch_assoc()['total'] > 0;
$stmt_check_mc->close();

if ($has_specific_mc) {
    $stmt_mc = $mysqli->prepare(
        "SELECT mc.id, mc.nama_micro_cluster 
         FROM user_micro_clusters umc
         JOIN micro_clusters mc ON umc.micro_cluster_id = mc.id
         WHERE umc.user_id = ? 
         ORDER BY mc.nama_micro_cluster"
    );
    $stmt_mc->bind_param("i", $user_id);
} else {
    $stmt_mc = $mysqli->prepare(
        "SELECT id, nama_micro_cluster
         FROM micro_clusters
         WHERE branch_id = ?
         ORDER BY nama_micro_cluster"
    );
    $stmt_mc->bind_param("i", $user_branch_id);
}
$stmt_mc->execute();
$micro_clusters_result = $stmt_mc->get_result();

if ($micro_clusters_result->num_rows === 0 && empty($error_message)) {
    $error_message = "Error: Tidak ada Micro Cluster yang bisa diakses. Silakan hubungi Admin.";
}

$categories_result = $mysqli->query("SELECT id, nama_kategori FROM event_categories ORDER BY nama_kategori");


if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $mysqli->begin_transaction();

    try {
        $data_event = [
            'user_id' => $_SESSION['id'],
            'event_name' => trim($_POST['event_name'] ?? ''),
            'site_id' => (int)($_POST['site_id'] ?? 0),
            'kategori_event_id' => (int)($_POST['kategori_event_id'] ?? 0),
            'location_latitude' => (float)($_POST['latitude'] ?? 0.0),
            'location_longitude' => (float)($_POST['longitude'] ?? 0.0),
            'foto_event_url' => '',
            'sp_0k' => (int)($_POST['sp_0k'] ?? 0),
            'sp_3gb' => (int)($_POST['sp_3gb'] ?? 0),
            'sp_5gb' => (int)($_POST['sp_5gb'] ?? 0),
            'sp_7gb' => (int)($_POST['sp_7gb'] ?? 0),
            'sp_100gb' => (int)($_POST['sp_100gb'] ?? 0),
            'fwa' => (int)($_POST['fwa'] ?? 0),
            'fwa_5g' => (int)($_POST['fwa_5g'] ?? 0),
            'hit_haji_umroh' => (int)($_POST['hit_haji_umroh'] ?? 0),
            'reload' => (int)($_POST['reload'] ?? 0),
            'mobo_paket' => (int)($_POST['mobo_paket'] ?? 0),
            'cost' => (int)($_POST['cost'] ?? 0),
            'alasan' => trim($_POST['alasan'] ?? '')
        ];

        if (isset($_FILES['foto_event']) && $_FILES['foto_event']['error'] == 0) {
            $target_dir = "../marcomm_bn/uploads/";
            if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
            
            $file_extension = strtolower(pathinfo($_FILES["foto_event"]["name"], PATHINFO_EXTENSION));
            $unique_filename = uniqid('event_', true) . '.' . $file_extension;
            $target_file = $target_dir . $unique_filename;
            
            if ($_FILES['foto_event']['size'] > 7 * 1024 * 1024) {
                throw new Exception("Error: Ukuran file foto melebihi 7MB.");
            }

            if (compress_image($_FILES['foto_event']['tmp_name'], $target_file, 75)) {
                $data_event['foto_event_url'] = '../marcomm_bn/uploads/' . $unique_filename;
            } else {
                throw new Exception("Error: Gagal mengompres dan mengunggah file foto.");
            }
        } else {
            throw new Exception("Error: File foto event wajib diunggah.");
        }

        $new_unique_msisdns = [];
        $found_duplicates_with_details = [];
        
        if (isset($_FILES['msisdn_file']) && $_FILES['msisdn_file']['error'] == 0) {
            $excel_password = $_POST['excel_password'] ?? null;
            $msisdn_temp_path = $_FILES['msisdn_file']['tmp_name'];
            
            // Handle password-protected Excel
            $reader = IOFactory::createReaderForFile($msisdn_temp_path);
            if (!empty($excel_password)) {
                $reader->setPassword($excel_password);
            }
            $spreadsheet = $reader->load($msisdn_temp_path);
            $msisdns_from_file = [];
            
            foreach ($spreadsheet->getActiveSheet()->getRowIterator(1) as $row) {
                $value = trim($spreadsheet->getActiveSheet()->getCell('A' . $row->getRowIndex())->getValue() ?? '');
                $type = strtolower(trim($spreadsheet->getActiveSheet()->getCell('B' . $row->getRowIndex())->getValue() ?? 'new'));

                if (!in_array($type, ['existing', 'new'])) {
                    $type = 'new';
                }
                
                if (substr($value, 0, 2) === '08') { $value = '62' . substr($value, 1); } 
                elseif (substr($value, 0, 1) === '8') { $value = '62' . $value; }

                if (is_numeric($value) && !empty($value)) { 
                    $msisdns_from_file[] = ['msisdn' => $value, 'type' => $type]; 
                }
            }

            $unique_msisdns_only = array_unique(array_column($msisdns_from_file, 'msisdn'));
            $final_msisdn_data = [];
            foreach($msisdns_from_file as $item) {
                $final_msisdn_data[$item['msisdn']] = $item['type'];
            }

            if (!empty($unique_msisdns_only)) {
                $placeholders = implode(',', array_fill(0, count($unique_msisdns_only), '?'));
                $stmt_check = $mysqli->prepare("SELECT msisdn, submission_id FROM msisdn_data WHERE msisdn IN ($placeholders)");
                $stmt_check->bind_param(str_repeat('s', count($unique_msisdns_only)), ...$unique_msisdns_only);
                $stmt_check->execute();
                $result = $stmt_check->get_result();
                $existing_in_db = $result->fetch_all(MYSQLI_ASSOC);
                $existing_msisdns_map = array_column($existing_in_db, 'submission_id', 'msisdn');
                
                $new_unique_keys = array_diff($unique_msisdns_only, array_keys($existing_msisdns_map));
                
                foreach($new_unique_keys as $key) {
                    $new_unique_msisdns[] = ['msisdn' => $key, 'type' => $final_msisdn_data[$key]];
                }
                
                $found_duplicates_with_details = array_intersect_key($existing_msisdns_map, array_flip($unique_msisdns_only));
            }
        } else {
            throw new Exception("File MSISDN wajib diunggah.");
        }

        $data_event['jumlah_qsc'] = $data_event['sp_0k'] + $data_event['sp_3gb'] + $data_event['sp_5gb'] + $data_event['sp_7gb'] + $data_event['sp_100gb'];
        
        $data_event['benefit_sp'] = ($data_event['sp_0k'] * 10000) + ($data_event['sp_3gb'] * 27000) + ($data_event['sp_5gb'] * 35000) + ($data_event['sp_7gb'] * 39000);

        $data_event['benefit_fwa'] = ($data_event['fwa'] * 150000) + ($data_event['fwa_5g'] * 750000); 

        $data_event['benefit_total'] = $data_event['benefit_sp'] + $data_event['benefit_fwa'] + $data_event['mobo_paket'];
            
        $data_event['ratio_cost_benefit'] = ($data_event['benefit_total'] > 0) ? ($data_event['cost'] / $data_event['benefit_total']) * 100 : 0;

        $stmt_cols = $mysqli->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'event_submissions' ORDER BY ORDINAL_POSITION");
        $db_name = $mysqli->query("SELECT DATABASE()")->fetch_row()[0];
        $stmt_cols->bind_param("s", $db_name);
        $stmt_cols->execute();
        $result_cols = $stmt_cols->get_result();
        
        $db_columns_info = [];
        while ($row = $result_cols->fetch_assoc()) {
            $db_columns_info[$row['COLUMN_NAME']] = $row['DATA_TYPE'];
        }
        $stmt_cols->close();

        $columns_to_insert = [];
        $placeholders = [];
        $bind_values = [];
        $types_string = "";

        foreach ($data_event as $key => $value) {
            if (array_key_exists($key, $db_columns_info)) {
                $columns_to_insert[] = "`" . $key . "`";
                $placeholders[] = "?";
                $bind_values[] = $value;
                
                switch ($db_columns_info[$key]) {
                    case 'int': case 'bigint': case 'tinyint': case 'mediumint':
                        $types_string .= 'i'; break;
                    case 'double': case 'float': case 'decimal':
                        $types_string .= 'd'; break;
                    default:
                        $types_string .= 's'; break;
                }
            } else {
                error_log("WARNING: Kolom '$key' dari form tidak ditemukan di tabel 'event_submissions'.");
            }
        }
        
        $sql_event = "INSERT INTO event_submissions (" . implode(", ", $columns_to_insert) . ") VALUES (" . implode(", ", $placeholders) . ")";
        $stmt_event = $mysqli->prepare($sql_event);
        if ($stmt_event === false) throw new Exception("Gagal menyiapkan statement SQL untuk event: " . $mysqli->error);

        $bind_params = array_merge([$types_string], $bind_values);
        if (!call_user_func_array([$stmt_event, 'bind_param'], ref_values($bind_params))) {
            throw new Exception("Gagal mengikat parameter: " . $stmt_event->error);
        }
        
        if (!$stmt_event->execute()) {
            error_log("Error saat menyimpan event: " . $stmt_event->error);
            throw new Exception("Gagal menyimpan event: " . $stmt_event->error);
        }
        
        $submission_id = $mysqli->insert_id;
        if ($submission_id == 0) throw new Exception("Gagal mendapatkan ID event setelah insert.");
        
        if (!empty($new_unique_msisdns)) {
            $stmt_msisdn = $mysqli->prepare("INSERT INTO msisdn_data (submission_id, msisdn, type) VALUES (?, ?, ?)");
            foreach ($new_unique_msisdns as $item) {
                $stmt_msisdn->bind_param("iss", $submission_id, $item['msisdn'], $item['type']);
                $stmt_msisdn->execute();
            }
            $stmt_msisdn->close();
        }
        
        if (!empty($found_duplicates_with_details)) {
            $stmt_log = $mysqli->prepare("INSERT INTO duplicate_msisdn_log (new_submission_id, duplicate_msisdn, original_submission_id) VALUES (?, ?, ?)");
            foreach ($found_duplicates_with_details as $msisdn => $original_submission_id) {
                $stmt_log->bind_param("isi", $submission_id, $msisdn, $original_submission_id);
                $stmt_log->execute();
            }
            $stmt_log->close();
        }
        
        $mysqli->commit();
        $success_message = "Data event berhasil disimpan. " . count($new_unique_msisdns) . " MSISDN baru ditambahkan.";
        if (!empty($found_duplicates_with_details)) {
            $duplicate_list = implode(', ', array_keys($found_duplicates_with_details));
            $warning_message = "Peringatan: MSISDN berikut sudah ada di database dan telah dicatat sebagai duplikat: " . htmlspecialchars($duplicate_list);
        }

    } catch (Exception $e) {
        $mysqli->rollback();
        $error_message .= "Error: " . $e->getMessage();
        error_log("Fatal Error in input_form.php (submission logic): " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Input Event - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container--default .select2-selection--single {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            height: 48px;
            display: flex;
            align-items: center;
            font-size: 14px;
            font-weight: 500;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 48px;
            padding-left: 16px;
            color: #1e293b;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 46px;
        }
        .select2-dropdown {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .form-section-title {
            position: relative;
            padding-left: 1.5rem;
            margin-bottom: 2rem;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: -0.025em;
        }
        .form-section-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 100%;
            background: #3b82f6;
            border-radius: 2px;
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Aurora Background Blobs -->
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>

    <div class="flex">
        <!-- Sidebar -->
        <?php include 'components/sidebar_user.php'; ?>

        <!-- Main Content -->
        <main class="flex-grow p-4 lg:p-10 lg:ml-64 min-w-0">
            <!-- Header -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-3 text-slate-600 glass-card relative z-[60] active:scale-95 transition-transform"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Form Input Event</h2>
                        <p class="text-slate-500 font-medium">Lengkapi data aktivitas event Anda dengan akurat.</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <a href="bulk_import_event.php" class="px-5 py-3 bg-white/50 backdrop-blur-md border border-slate-200 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-50 transition-all flex items-center gap-2">
                        <i class="fas fa-star text-amber-500"></i> Super Import (Bulky)
                    </a>
                </div>
            </header>

            <div class="max-w-5xl">
                <?php if ($success_message): ?>
                    <div class="glass-card bg-emerald-50/50 border-emerald-200 p-4 mb-8 flex items-center gap-3">
                        <div class="h-10 w-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle"></i></div>
                        <p class="text-emerald-800 font-bold"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="glass-card bg-red-50/50 border-red-200 p-4 mb-8 flex items-center gap-3">
                        <div class="h-10 w-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center"><i class="fas fa-exclamation-triangle"></i></div>
                        <p class="text-red-800 font-bold"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>

                <form id="eventForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data" class="space-y-8">

                <!-- Section 1: Informasi Event -->
                <div class="glass-card p-8">
                    <h3 class="form-section-title text-lg uppercase tracking-widest">Informasi Event & Lokasi</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-4">
                        <div class="space-y-2">
                            <label for="event_name" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Nama Event <span class="text-red-500">*</span></label>
                            <input type="text" id="event_name" name="event_name" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all" placeholder="Contoh: Bazaar Ramadhan">
                        </div>
                        <div class="space-y-2">
                            <label for="kategori_event_id" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Kategori Event <span class="text-red-500">*</span></label>
                            <select id="kategori_event_id" name="kategori_event_id" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                <option value="">-- Pilih Kategori --</option>
                                <?php mysqli_data_seek($categories_result, 0);
                                        while($category = $categories_result->fetch_assoc()): ?>
                                    <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['nama_kategori']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Branch</label>
                            <input type="text" value="<?php echo htmlspecialchars($user_branch_name); ?>" readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-500 cursor-not-allowed">
                        </div>
                        <div class="space-y-2">
                            <label for="micro_cluster_id" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Micro Cluster <span class="text-red-500">*</span></label>
                            <select id="micro_cluster_id" name="micro_cluster_id" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                <option value="">-- Pilih Micro Cluster --</option>
                                <?php mysqli_data_seek($micro_clusters_result, 0);
                                        while($mc = $micro_clusters_result->fetch_assoc()): ?>
                                    <option value="<?php echo $mc['id']; ?>"><?php echo htmlspecialchars($mc['nama_micro_cluster']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label for="site_id" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Site Name <span class="text-red-500">*</span></label>
                            <select id="site_id" name="site_id" required class="w-full" disabled>
                                <option value="">-- Pilih Micro Cluster dulu --</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-4 lg:col-span-1">
                            <div class="space-y-2">
                                <label for="latitude" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Lat <span class="text-red-500">*</span></label>
                                <input type="text" id="latitude" name="latitude" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all" placeholder="-8.65">
                            </div>
                            <div class="space-y-2">
                                <label for="longitude" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Lon <span class="text-red-500">*</span></label>
                                <input type="text" id="longitude" name="longitude" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all" placeholder="115.21">
                            </div>
                        </div>
                        <div class="lg:col-span-3 flex justify-end">
                            <button type="button" id="getLocationBtn" class="px-6 py-2.5 bg-blue-50 text-blue-600 font-bold text-xs rounded-xl hover:bg-blue-600 hover:text-white transition-all flex items-center gap-2 border border-blue-100">
                                <i class="fas fa-map-marker-alt"></i> Gunakan Lokasi Saat Ini
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Penjualan -->
                <div class="glass-card p-8">
                    <h3 class="form-section-title text-lg uppercase tracking-widest">Data Penjualan</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-6 mt-4">
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">SP 0K</label>
                            <input type="number" name="sp_0k" value="0" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">SP 3GB</label>
                            <input type="number" name="sp_3gb" value="0" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">SP 5GB</label>
                            <input type="number" name="sp_5gb" value="0" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">SP 7GB</label>
                            <input type="number" name="sp_7gb" value="0" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">SP 100GB</label>
                            <input type="number" name="sp_100gb" value="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">FWA</label>
                            <input type="number" name="fwa" value="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">FWA 5G</label>
                            <input type="number" name="fwa_5g" value="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Reload</label>
                            <input type="number" name="reload" value="0" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Mobo/Paket</label>
                            <input type="number" name="mobo_paket" value="0" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Cost</label>
                            <input type="number" name="cost" value="0" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Hit Haji/Umroh</label>
                            <input type="number" name="hit_haji_umroh" value="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                    </div>
                </div>

                <!-- Section 3: Hasil Kalkulasi -->
                <div class="glass-card p-8 bg-blue-50/30 border-blue-100">
                    <h3 class="form-section-title text-lg uppercase tracking-widest text-blue-800">Hasil Kalkulasi (Otomatis)</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6 mt-4">
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-blue-400 uppercase tracking-widest px-1">Jumlah QSC</label>
                            <input type="text" id="calc_jumlah_qsc" readonly class="w-full bg-white border border-blue-100 rounded-xl px-4 py-3 text-lg font-black text-blue-600 text-center shadow-inner cursor-default">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-blue-400 uppercase tracking-widest px-1">Benefit SP</label>
                            <input type="text" id="calc_benefit_sp" readonly class="w-full bg-white border border-blue-100 rounded-xl px-4 py-3 text-lg font-black text-blue-600 text-center shadow-inner cursor-default">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-blue-400 uppercase tracking-widest px-1">Benefit FWA</label>
                            <input type="text" id="calc_benefit_fwa" readonly class="w-full bg-white border border-blue-100 rounded-xl px-4 py-3 text-lg font-black text-blue-600 text-center shadow-inner cursor-default">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-blue-400 uppercase tracking-widest px-1">Benefit Total</label>
                            <input type="text" id="calc_benefit_total" readonly class="w-full bg-white border border-blue-100 rounded-xl px-4 py-3 text-lg font-black text-blue-600 text-center shadow-inner cursor-default">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-[10px] font-black text-blue-400 uppercase tracking-widest px-1">Ratio Cost/Benefit</label>
                            <input type="text" id="calc_ratio" readonly class="w-full bg-white border border-blue-100 rounded-xl px-4 py-3 text-lg font-black text-blue-600 text-center shadow-inner cursor-default">
                        </div>
                    </div>
                </div>

                <!-- Section 4: Dokumentasi -->
                <div class="glass-card p-8">
                    <h3 class="form-section-title text-lg uppercase tracking-widest">Dokumentasi & MSISDN</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mt-4">
                        <div class="space-y-3">
                            <label for="foto_event" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Foto Event (Maks 7MB) <span class="text-red-500">*</span></label>
                            <div class="relative group">
                                <input type="file" id="foto_event" name="foto_event" required accept="image/*" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all cursor-pointer">
                            </div>
                            <p id="file-size-error" class="text-red-500 text-[10px] font-bold mt-1 hidden animate-pulse">⚠️ Ukuran file foto melebihi limit 7MB!</p>
                        </div>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center px-1">
                                <label for="msisdn_file" class="block text-xs font-black text-slate-400 uppercase tracking-widest">File MSISDN <span class="text-red-500">*</span></label>
                                <a href="process/download_msisdn_template.php" class="text-[10px] font-black text-blue-600 hover:underline uppercase tracking-widest transition-all">
                                    <i class="fas fa-download mr-1"></i> Unduh Template
                                </a>
                            </div>
                            <input type="file" id="msisdn_file" name="msisdn_file" required accept=".xlsx, .xls" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 transition-all cursor-pointer">
                            <div class="mt-2 relative group">
                                <label for="excel_password" class="text-[9px] font-black text-slate-400 uppercase tracking-widest px-1 block mb-1">Pass Excel (Opsional - Jika Diproteksi)</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-lock text-[10px] text-slate-400"></i>
                                    </div>
                                    <input type="password" name="excel_password" id="excel_password" placeholder="Masukkan password jika file diproteksi" 
                                           class="w-full pl-8 pr-4 py-2 bg-slate-50/50 border border-slate-200 rounded-lg text-[11px] font-bold focus:outline-none focus:ring-2 focus:ring-blue-100 transition-all">
                                </div>
                            </div>
                        </div>
                        <div class="md:col-span-2 space-y-2">
                            <label for="alasan" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Feedback / Catatan</label>
                            <textarea name="alasan" rows="3" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-4 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all placeholder:text-slate-400" placeholder="Tambahkan catatan jika diperlukan..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="pb-20">
                    <button type="submit" id="submitBtn" class="w-full py-4 bg-blue-600 text-white font-extrabold text-lg rounded-2xl hover:bg-blue-700 shadow-xl shadow-blue-200 active:scale-95 transition-all flex items-center justify-center gap-3">
                        <i class="fas fa-paper-plane"></i> Kirim Data Aktivitas
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const eventForm = document.getElementById('eventForm');
    const getLocationBtn = document.getElementById('getLocationBtn');
    const latInput = document.getElementById('latitude');
    const lonInput = document.getElementById('longitude');
    const microClusterSelect = document.getElementById('micro_cluster_id');
    const siteSelect = document.getElementById('site_id');
    const fotoInput = document.getElementById('foto_event');
    const fileSizeError = document.getElementById('file-size-error');
    

    function showMessageBox(message, type = 'blue') {
        const existingBox = document.querySelector('.message-box-overlay');
        if (existingBox) existingBox.remove();

        const colorMap = {
            blue: { bg: 'bg-blue-50/90', text: 'text-blue-800', icon: 'fa-info-circle', btn: 'bg-blue-600' },
            red: { bg: 'bg-red-50/90', text: 'text-red-800', icon: 'fa-exclamation-triangle', btn: 'bg-red-600' },
            green: { bg: 'bg-emerald-50/90', text: 'text-emerald-800', icon: 'fa-check-circle', btn: 'bg-emerald-600' }
        };
        const theme = colorMap[type] || colorMap.blue;

        const messageBox = document.createElement('div');
        messageBox.className = 'message-box-overlay fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-[200] p-4 animate-in fade-in duration-300';
        messageBox.innerHTML = `
            <div class="glass-card max-w-sm w-full p-8 text-center transform animate-in zoom-in-95 duration-300 shadow-2xl">
                <div class="h-16 w-16 ${theme.bg} ${theme.text} rounded-2xl flex items-center justify-center text-2xl mx-auto mb-6">
                    <i class="fas ${theme.icon}"></i>
                </div>
                <h3 class="text-lg font-extrabold text-slate-800 mb-2">Pemberitahuan</h3>
                <p class="text-sm font-medium text-slate-600 mb-8 leading-relaxed">${message}</p>
                <button class="w-full ${theme.btn} text-white font-bold py-3 rounded-xl shadow-lg active:scale-95 transition-all" onclick="this.closest('.message-box-overlay').remove()">Mengerti</button>
            </div>
        `;
        document.body.appendChild(messageBox);
    }

    getLocationBtn.addEventListener('click', function() {
        if (navigator.geolocation) {
            getLocationBtn.disabled = true;
            getLocationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mencari...';
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    latInput.value = position.coords.latitude.toFixed(6);
                    lonInput.value = position.coords.longitude.toFixed(6);
                    getLocationBtn.disabled = false;
                    getLocationBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Gunakan Lokasi Saat Ini';
                },
                () => {
                    showMessageBox('Gagal mendapatkan lokasi. Pastikan Anda sudah memberikan izin akses lokasi pada browser.', 'red');
                    getLocationBtn.disabled = false;
                    getLocationBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Gunakan Lokasi Saat Ini';
                }
            );
        } else {
            showMessageBox('Geolocation tidak didukung oleh browser ini.', 'red');
        }
    });

    fotoInput.addEventListener('change', function() {
        if (this.files[0] && this.files[0].size > 7 * 1024 * 1024) {
            fileSizeError.classList.remove('hidden');
            this.value = '';
        } else {
            fileSizeError.classList.add('hidden');
        }
    });

    microClusterSelect.addEventListener('change', function() {
        const mcId = this.value;
        siteSelect.innerHTML = '<option value="">-- Memuat... --</option>';
        siteSelect.disabled = true;

        if ($(siteSelect).data('select2')) {
            $(siteSelect).select2('destroy');
        }

        if (!mcId) {
            siteSelect.innerHTML = '<option value="">-- Pilih Micro Cluster dulu --</option>';
            $(siteSelect).select2();
            return;
        }
        fetch(`api_helper.php?action=get_sites&micro_cluster_id=${mcId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                siteSelect.innerHTML = '<option value="">-- Pilih Site --</option>';
                if (data.length > 0) {
                    data.forEach(site => {
                        const option = new Option(site.site_name, site.id);
                        siteSelect.add(option);
                    });
                } else {
                    siteSelect.innerHTML = '<option value="">-- Tidak ada site --</option>';
                }
                siteSelect.disabled = false;
                $(siteSelect).select2();
            })
            .catch(error => {
                console.error('Error fetching sites:', error);
                siteSelect.innerHTML = '<option value="">-- Gagal memuat site --</option>';
                showMessageBox('Gagal memuat data Site. Silakan coba lagi atau hubungi administrator.');
                siteSelect.disabled = false;
                $(siteSelect).select2();
            });
    });

    $(siteSelect).select2();

    const spInputs = {
        sp_0k: document.querySelector('input[name="sp_0k"]'),
        sp_3gb: document.querySelector('input[name="sp_3gb"]'),
        sp_5gb: document.querySelector('input[name="sp_5gb"]'),
        sp_7gb: document.querySelector('input[name="sp_7gb"]'),
        sp_100gb: document.querySelector('input[name="sp_100gb"]')
    };
    const fwaInput = document.querySelector('input[name="fwa"]');
    const fwa5gInput = document.querySelector('input[name="fwa_5g"]');
    const moboPaketInput = document.querySelector('input[name="mobo_paket"]');
    const costInput = document.querySelector('input[name="cost"]');
    const hajiInput = document.querySelector('input[name="hit_haji_umroh"]');
    const reloadInput = document.querySelector('input[name="reload"]');

    const displayQSC = document.getElementById('calc_jumlah_qsc');
    const displayBenefitSP = document.getElementById('calc_benefit_sp');
    const displayBenefitFWA = document.getElementById('calc_benefit_fwa');
    const displayBenefitTotal = document.getElementById('calc_benefit_total');
    const displayRatio = document.getElementById('calc_ratio');

    const calculationInputs = [...Object.values(spInputs), fwaInput, fwa5gInput, moboPaketInput, costInput, hajiInput, reloadInput];

    function updateCalculations() {
        const getVal = (input) => parseInt(input.value) || 0;
        
        const jumlahQSC = 
            getVal(spInputs.sp_0k) +
            getVal(spInputs.sp_3gb) +
            getVal(spInputs.sp_5gb) +
            getVal(spInputs.sp_7gb) +
            getVal(spInputs.sp_100gb);

        const benefitSP = 
            (getVal(spInputs.sp_0k) * 10000) +
            (getVal(spInputs.sp_3gb) * 27000) +
            (getVal(spInputs.sp_5gb) * 35000) +
            (getVal(spInputs.sp_7gb) * 39000);

        const benefitFWA = getVal(fwaInput) * 150000;
        const benefitFWA5G = getVal(fwa5gInput) * 750000;

        const benefitTotal = 
            benefitSP + 
            benefitFWA + 
            benefitFWA5G + 
            getVal(moboPaketInput);
            
        const totalCost = getVal(costInput);
        const ratio = benefitTotal > 0 ? (totalCost / benefitTotal) * 100 : 0;

        displayQSC.value = jumlahQSC.toLocaleString('id-ID');
        displayBenefitSP.value = 'Rp ' + benefitSP.toLocaleString('id-ID');
        displayBenefitFWA.value = 'Rp ' + (benefitFWA + benefitFWA5G).toLocaleString('id-ID');
        displayBenefitTotal.value = 'Rp ' + benefitTotal.toLocaleString('id-ID');
        displayRatio.value = ratio.toFixed(2) + ' %';
    }

    calculationInputs.forEach(input => {
        input.addEventListener('input', updateCalculations);
    });

    updateCalculations();

    eventForm.addEventListener('submit', function(event) {
        let isFormValid = true;

        const requiredFields = eventForm.querySelectorAll('[required]');
        requiredFields.forEach(field => {
            if (field.offsetParent !== null && !field.disabled && !field.value.trim()) {
                isFormValid = false;
                field.classList.add('border-red-500');
            } else {
                field.classList.remove('border-red-500');
            }
        });

        if (siteSelect.required && !siteSelect.value) {
            isFormValid = false;
        }


        if (!isFormValid) {
            event.preventDefault();
            showMessageBox('Harap lengkapi semua kolom yang wajib diisi (ditandai dengan *) dan pastikan pilihan checkbox sudah terisi.');
        } else {
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengirim...';
        }
    });
});
</script>

</body>
</html>
