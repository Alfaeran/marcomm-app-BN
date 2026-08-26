<?php
// user_edit_matpro_form.php
require_once 'config/database.php';

// Cek jika user tidak login atau bukan 'user'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php"); // Redirect ke login jika tidak berhak
    exit;
}

$user_id = (int)$_SESSION['id'];
$activity_id = (int)($_GET['id'] ?? 0); // Ambil ID aktivitas dari URL

if (empty($activity_id)) {
    $_SESSION['error_message'] = "ID Aktivitas Matpro tidak valid.";
    header("location: user_matpro_activities.php");
    exit;
}

// Inisialisasi pesan
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);
$warning_message = $_SESSION['warning_message'] ?? '';
unset($_SESSION['warning_message']);

// --- VERIFIKASI HAK AKSES EDIT OLEH USER ---
// 1. Ambil data aktivitas Matpro
$activity_data = null;
$stmt_activity = $mysqli->prepare("
    SELECT
        ma.*,
        -- Mengambil project_name dan type_name langsung dari matpro_activities
        -- Mengambil brand dari tabel branches, karena matpro_projects tidak ditemukan
        b.brand as project_brand,
        b.nama_branch,
        mc.nama_micro_cluster,
        s.site_name, s.site_id as site_code, s.kecamatan, s.kabupaten, s.area, s.region,
        o.id_outlet, o.nama_outlet, o.Id_Outlet_Nama_Outlet
    FROM matpro_activities ma
    JOIN branches b ON ma.branch_id = b.id
    LEFT JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
    JOIN sites s ON ma.site_id = s.id
    LEFT JOIN outlets o ON ma.outlet_id = o.id
    WHERE ma.id = ? AND ma.user_id = ?
");

// --- DEBUGGING TEMPORARY OUTPUT ---
echo "<!-- Debugging Info: -->";
echo "<!-- Activity ID from URL: " . htmlspecialchars($activity_id) . " -->";
echo "<!-- User ID from Session: " . htmlspecialchars($user_id) . " -->";
// --- END DEBUGGING TEMPORARY OUTPUT ---

if ($stmt_activity) {
    $stmt_activity->bind_param("ii", $activity_id, $user_id);
    $stmt_activity->execute();
    $result_activity = $stmt_activity->get_result();
    $activity_data = $result_activity->fetch_assoc();

    // --- DEBUGGING TEMPORARY OUTPUT ---
    echo "<!-- Rows found for activity: " . htmlspecialchars($result_activity->num_rows) . " -->";
    if ($result_activity->num_rows === 0) {
        echo "<!-- Activity not found with ID " . htmlspecialchars($activity_id) . " for User " . htmlspecialchars($user_id) . " -->";
    }
    // --- END DEBUGGING TEMPORARY OUTPUT ---

    $stmt_activity->close();
} else {
    // --- DEBUGGING TEMPORARY OUTPUT ---
    echo "<!-- Error preparing activity query: " . htmlspecialchars($mysqli->error) . " -->";
    // --- END DEBUGGING TEMPORARY OUTPUT ---
    $_SESSION['error_message'] = "Gagal menyiapkan query aktivitas: " . $mysqli->error;
    header("location: user_matpro_activities.php");
    exit;
}

if (!$activity_data) {
    $_SESSION['error_message'] = "Aktivitas Matpro tidak ditemukan atau Anda tidak memiliki izin.";
    header("location: user_matpro_activities.php");
    exit;
}

// 2. Cek apakah ada permintaan edit yang disetujui untuk aktivitas ini
$is_edit_approved = false;
$request_id_for_completion = 0;
$stmt_check_request = $mysqli->prepare("SELECT id, status FROM matpro_edit_requests WHERE activity_id = ? AND user_id = ? AND status = 'approved' ORDER BY requested_at DESC LIMIT 1");
if ($stmt_check_request) {
    $stmt_check_request->bind_param("ii", $activity_id, $user_id);
    $stmt_check_request->execute();
    $result_check_request = $stmt_check_request->get_result();
    if ($row_req = $result_check_request->fetch_assoc()) {
        $is_edit_approved = true;
        $request_id_for_completion = $row_req['id'];
    }
    $stmt_check_request->close();
}

if (!$is_edit_approved) {
    $_SESSION['error_message'] = "Anda tidak memiliki izin untuk mengedit aktivitas ini. Mohon ajukan permintaan edit terlebih dahulu dan tunggu persetujuan admin.";
    header("location: user_matpro_activities.php");
    exit;
}

// Ambil data untuk dropdown yang akan diisi ulang oleh JS
// Ambil daftar proyek unik dari matpro_stocks yang dialokasikan untuk user ini
$projects_list = null;
$stmt_projects = $mysqli->prepare(
    "SELECT DISTINCT project_name, project_brand FROM matpro_stocks
     WHERE branch_id = ? AND (project_brand = ? OR project_brand = 'BOTH') AND is_active = 1 AND stock_quantity > 0
     ORDER BY project_name"
);
if ($stmt_projects) {
    // Menggunakan branch_id dari aktivitas dan project_brand dari aktivitas
    $stmt_projects->bind_param("is", $activity_data['branch_id'], $activity_data['project_brand']);
    $stmt_projects->execute();
    $projects_list = $stmt_projects->get_result();
    $stmt_projects->close();
} else {
    $error_message .= " Gagal menyiapkan query proyek untuk dropdown: " . $mysqli->error;
}


// Tentukan level input berdasarkan apakah ada micro_cluster_id
$current_input_level = !empty($activity_data['micro_cluster_id']) ? 'outlet' : 'branch';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Aktivitas Matpro</title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100 p-4 md:p-8">
    <div class="max-w-6xl mx-auto bg-white p-6 md:p-8 rounded-lg shadow-lg">

        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Formulir Edit Aktivitas Matpro</h1>
            <a href="user_matpro_activities.php" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg transition duration-300">
                Kembali ke Daftar Aktivitas
            </a>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Sukses!</strong>
                <span class="block sm:inline"><?php echo $success_message; ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($warning_message)): ?>
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Peringatan!</strong>
                <span class="block sm:inline"><?php echo $warning_message; ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Error!</strong>
                <span class="block sm:inline"><?php echo $error_message; ?></span>
            </div>
        <?php endif; ?>

        <form id="matproActivityEditForm" action="process/user_matpro_edit_process.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_matpro_activity">
            <input type="hidden" name="activity_id" value="<?php echo htmlspecialchars($activity_data['id']); ?>">
            <input type="hidden" name="request_id" value="<?php echo $request_id_for_completion; ?>">
            <p class="text-sm text-gray-600 mb-4">Kolom dengan tanda <span class="text-red-500 font-bold">*</span> wajib diisi.</p>

            <fieldset class="border p-4 rounded-lg mb-6">
                <legend class="text-xl font-semibold px-2">Informasi Umum & Lokasi</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-4">
                    <div class="mb-4">
                        <label class="block text-gray-700 font-bold mb-2">Waktu Aktivitas</label>
                        <input type="text" id="activity_datetime_display" name="activity_datetime" value="<?php echo date('d/m/Y, H:i:s', strtotime($activity_data['activity_datetime'])); ?>" class="w-full px-3 py-2 border rounded-lg bg-gray-200">
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 font-bold mb-2">Branch</label>
                        <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($activity_data['branch_id']); ?>">
                        <input type="text" value="<?php echo htmlspecialchars($activity_data['nama_branch']); ?>" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-200">
                    </div>
                    <div class="mb-4">
                        <label for="input_level" class="block text-gray-700 font-bold mb-2">Level Input <span class="text-red-500">*</span></label>
                        <select name="input_level" id="input_level" required class="w-full px-3 py-2 border rounded-lg bg-white">
                            <option value="">-- Pilih Level --</option>
                            <option value="outlet" <?php echo ($current_input_level == 'outlet') ? 'selected' : ''; ?>>Micro Cluster Level</option>
                            <option value="branch" <?php echo ($current_input_level == 'branch') ? 'selected' : ''; ?>>Branch Level</option>
                        </select>
                    </div>
                    <div class="mb-4 <?php echo ($current_input_level == 'branch') ? 'hidden' : ''; ?>" id="micro_cluster_div">
                        <label for="micro_cluster_id" class="block text-gray-700 font-bold mb-2">Micro Cluster <span class="text-red-500">*</span></label>
                        <select id="micro_cluster_id" name="micro_cluster_id" class="w-full px-3 py-2 border rounded-lg bg-white" <?php echo ($current_input_level == 'branch') ? 'disabled' : ''; ?>>
                            <option value="">-- Pilih Level Input dulu --</option>
                            <?php if ($current_input_level == 'outlet' && !empty($activity_data['micro_cluster_id'])): ?>
                                <option value="<?php echo htmlspecialchars($activity_data['micro_cluster_id']); ?>" selected><?php echo htmlspecialchars($activity_data['nama_micro_cluster']); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="site_id" class="block text-gray-700 font-bold mb-2">Site Name <span class="text-red-500">*</span></label>
                        <input type="text" id="search_site_name" placeholder="Cari Site Name..." class="w-full px-3 py-2 border rounded-lg mb-1">
                        <select id="site_id" name="site_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                            <option value="">-- Pilih Level Input dulu --</option>
                            <?php if (!empty($activity_data['site_id'])): ?>
                                <option value="<?php echo htmlspecialchars($activity_data['site_id']); ?>" selected><?php echo htmlspecialchars($activity_data['site_name'] . ' (' . $activity_data['site_code'] . ')'); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="outlet_id" class="block text-gray-700 font-bold mb-2">ID Outlet | Nama Outlet <span class="text-red-500">*</span></label>
                        <input type="text" id="search_outlet_name" placeholder="Cari ID/Nama Outlet..." class="w-full px-3 py-2 border rounded-lg mb-1">
                        <select id="outlet_id" name="outlet_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                            <option value="">-- Pilih Site dulu --</option>
                            <?php if (!empty($activity_data['outlet_id'])): ?>
                                <option value="<?php echo htmlspecialchars($activity_data['outlet_id']); ?>" selected><?php echo htmlspecialchars($activity_data['Id_Outlet_Nama_Outlet']); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="kecamatan_kabupaten" class="block text-gray-700 font-bold mb-2">Kecamatan | Kabupaten</label>
                        <input type="text" id="kecamatan_kabupaten" value="<?php echo htmlspecialchars($activity_data['kecamatan'] . ' | ' . $activity_data['kabupaten']); ?>" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-200">
                    </div>
                    <div class="mb-4">
                        <label for="latitude" class="block text-gray-700 font-bold mb-2">Latitude <span class="text-red-500">*</span></label>
                        <input type="text" id="latitude" name="location_latitude" value="<?php echo htmlspecialchars($activity_data['location_latitude']); ?>" required class="w-full px-3 py-2 border rounded-lg" placeholder="-8.650000">
                    </div>
                    <div class="mb-4">
                        <label for="longitude" class="block text-gray-700 font-bold mb-2">Longitude <span class="text-red-500">*</span></label>
                        <input type="text" id="longitude" name="location_longitude" value="<?php echo htmlspecialchars($activity_data['location_longitude']); ?>" required class="w-full px-3 py-2 border rounded-lg" placeholder="115.210000">
                    </div>
                    <div class="mb-4 self-end">
                        <button type="button" id="getLocationBtn" class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-map-marker-alt"></i> Gunakan Lokasi Saat Ini
                        </button>
                    </div>
                </div>
            </fieldset>

            <fieldset class="border p-4 rounded-lg mb-6">
                <legend class="text-xl font-semibold px-2">Detail Matpro</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                    <div class="mb-4">
                        <label for="project_id" class="block text-gray-700 font-bold mb-2">Proyek Matpro <span class="text-red-500">*</span></label>
                        <select id="project_id" name="project_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                            <option value="">-- Pilih Proyek --</option>
                            <?php
                            if ($projects_list && $projects_list->num_rows > 0) {
                                mysqli_data_seek($projects_list, 0);
                                while($project = $projects_list->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($project['project_name']); ?>" <?php echo ($activity_data['project_name'] == $project['project_name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($project['project_name']); ?></option>
                                <?php endwhile;
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="type_id" class="block text-gray-700 font-bold mb-2">Jenis Matpro <span class="text-red-500">*</span></label>
                        <select id="type_id" name="type_id" required class="w-full px-3 py-2 border rounded-lg bg-white">
                            <option value="">Pilih Proyek dulu</option>
                            <?php
                            // Initial types based on current project (from matpro_stocks)
                            $stmt_initial_types = $mysqli->prepare(
                                "SELECT DISTINCT type_name FROM matpro_stocks
                                 WHERE project_name = ? AND branch_id = ? AND (micro_cluster_id = ? OR micro_cluster_id IS NULL OR micro_cluster_id = 0)
                                 AND is_active = 1 AND stock_quantity > 0 ORDER BY type_name"
                            );
                            if ($stmt_initial_types) {
                                $mc_id_for_type_query = $activity_data['micro_cluster_id'] ?? 0; // Use 0 if null for consistency
                                $stmt_initial_types->bind_param("sii", $activity_data['project_name'], $activity_data['branch_id'], $mc_id_for_type_query);
                                $stmt_initial_types->execute();
                                $initial_types_result = $stmt_initial_types->get_result();
                                while($type = $initial_types_result->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($type['type_name']); ?>" <?php echo ($activity_data['type_name'] == $type['type_name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['type_name']); ?></option>
                                <?php endwhile;
                                $stmt_initial_types->close();
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="qty_used" class="block text-gray-700 font-bold mb-2">QTY Digunakan <span class="text-red-500">*</span></label>
                        <input type="number" name="qty_used" id="qty_used" value="<?php echo htmlspecialchars($activity_data['qty_used']); ?>" min="1" required class="w-full px-3 py-2 border rounded-lg">
                        <p class="text-sm text-gray-600 mt-1">Sisa Stok Tersedia: <span id="available_stock" class="font-semibold">0</span></p>
                    </div>
                    <div class="mb-4">
                        <label for="photo_before" class="block text-gray-700 font-bold mb-2">Foto Sebelum Pemasangan (Biarkan kosong jika tidak berubah)</label>
                        <input type="file" name="photo_before" id="photo_before" accept="image/*" class="w-full px-3 py-2 border rounded-lg">
                        <?php if (!empty($activity_data['photo_before_url'])): ?>
                            <p class="text-sm text-gray-600 mt-1">Foto saat ini: <a href="<?php echo htmlspecialchars($activity_data['photo_before_url']); ?>" target="_blank" class="text-blue-500 hover:underline">Lihat Foto</a></p>
                            <input type="hidden" name="existing_photo_before_url" value="<?php echo htmlspecialchars($activity_data['photo_before_url']); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-4">
                        <label for="photo_after" class="block text-gray-700 font-bold mb-2">Foto Sesudah Pemasangan (Biarkan kosong jika tidak berubah)</label>
                        <input type="file" name="photo_after" id="photo_after" accept="image/*" class="w-full px-3 py-2 border rounded-lg">
                        <?php if (!empty($activity_data['photo_after_url'])): ?>
                            <p class="text-sm text-gray-600 mt-1">Foto saat ini: <a href="<?php echo htmlspecialchars($activity_data['photo_after_url']); ?>" target="_blank" class="text-blue-500 hover:underline">Lihat Foto</a></p>
                            <input type="hidden" name="existing_photo_after_url" value="<?php echo htmlspecialchars($activity_data['photo_after_url']); ?>">
                        <?php endif; ?>
                    </div>
                </div>
            </fieldset>

            <div class="mt-8">
                <button type="submit" id="submitBtn" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg text-xl">
                    Simpan Perubahan Aktivitas Matpro
                </button>
            </div>
        </form>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('matproActivityEditForm');
    const activityDatetimeDisplay = document.getElementById('activity_datetime_display');
    const getLocationBtn = document.getElementById('getLocationBtn');
    const latInput = document.getElementById('latitude');
    const lonInput = document.getElementById('longitude');
    const inputLevelSelect = document.getElementById('input_level');
    const microClusterDiv = document.getElementById('micro_cluster_div');
    const microClusterSelect = document.getElementById('micro_cluster_id');
    const siteSelect = document.getElementById('site_id');
    const outletSelect = document.getElementById('outlet_id');
    const kecamatanKabupatenInput = document.getElementById('kecamatan_kabupaten');
    const projectSelect = document.getElementById('project_id');
    const typeSelect = document.getElementById('type_id');
    const quantityInput = document.getElementById('qty_used');
    const availableStockSpan = document.getElementById('available_stock');
    const searchSiteNameInput = document.getElementById('search_site_name');
    const searchOutletNameInput = document.getElementById('search_outlet_name');
    const photoBeforeInput = document.getElementById('photo_before');
    const photoAfterInput = document.getElementById('photo_after');

    let allSiteOptions = [];
    let allOutletOptions = [];

    const userBranchId = '<?php echo (int)$activity_data['branch_id']; ?>'; // Mengambil branch_id dari data aktivitas
    const userHasSpecificMc = <?php echo (int)$activity_data['micro_cluster_id'] > 0 ? 'true' : 'false'; ?>; // Menggunakan data aktivitas untuk menentukan ini

    // Fungsi untuk menampilkan pesan box kustom
    function showMessageBox(message) {
        const existingBox = document.getElementById('customMessageBox');
        if(existingBox) existingBox.remove();

        const messageBox = document.createElement('div');
        messageBox.id = 'customMessageBox';
        messageBox.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        messageBox.innerHTML = `
            <div class="bg-white p-6 rounded-lg shadow-xl text-center max-w-sm">
                <p class="text-lg font-semibold mb-4">${message}</p>
                <button id="closeMessageBoxBtn" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-lg">Tutup</button>
            </div>
        `;
        document.body.appendChild(messageBox);
        document.getElementById('closeMessageBoxBtn').addEventListener('click', () => {
            messageBox.remove();
        });
    }

    // Fungsi untuk memuat opsi dropdown dari API
    async function loadOptions(action, params, selectElement, prompt, selectedValue = null) {
        selectElement.innerHTML = `<option value="">Memuat...</option>`;
        selectElement.disabled = true;
        if (selectElement === siteSelect) { searchSiteNameInput.disabled = true; searchSiteNameInput.value = ''; allSiteOptions = []; }
        if (selectElement === outletSelect) { searchOutletNameInput.disabled = true; searchOutletNameInput.value = ''; allOutletOptions = []; }

        try {
            const url = `api_helper.php?action=${action}&${params}`;
            const response = await fetch(url);

            if (!response.ok) {
                const errorText = await response.text();
                let errorMessage = `Network response was not ok. Status: ${response.status}`;
                try {
                    const errorJson = JSON.parse(errorText);
                    if (errorJson.error) {
                        errorMessage = `API Error (${response.status}): ${errorJson.error}`;
                    } else if (errorJson.message) {
                        errorMessage = `API Error (${response.status}): ${errorJson.message}`;
                    }
                } catch (e) {
                    errorMessage = `Network response was not ok. Status: ${response.status}. Response: ${errorText.substring(0, 100)}...`;
                }
                throw new Error(errorMessage);
            }
            const data = await response.json();

            if (!Array.isArray(data)) {
                if (data && data.error) throw new Error(`API Error: ${data.error}`);
                throw new Error('Format data tidak valid.');
            }

            selectElement.innerHTML = `<option value="">${prompt}</option>`;
            let currentAllOptions = [];
            if (data.length > 0) {
                data.forEach(item => {
                    let optionText;
                    let optionValue;

                    // Determine optionText and optionValue based on the specific select element
                    if (selectElement === microClusterSelect) {
                        optionText = item.nama_micro_cluster;
                        optionValue = item.id;
                    } else if (selectElement === siteSelect) {
                        optionText = item.site_name;
                        optionValue = item.id;
                    } else if (selectElement === outletSelect) {
                        optionText = item.outlet_name; // Assuming API returns outlet_name
                        optionValue = item.id; // Assuming API returns outlet_id
                    } else if (selectElement === projectSelect) { // For project dropdown
                        optionText = item.project_name;
                        optionValue = item.project_name; // Value is project_name
                    } else if (selectElement === typeSelect) { // For type dropdown
                        optionText = item.type_name;
                        optionValue = item.type_name; // Value is type_name
                    } else {
                        // Fallback for any other generic dropdowns, if applicable
                        optionText = item.text || item.name || item.value; // Try common names
                        optionValue = item.id || item.value || item.text; // Try common IDs
                    }

                    if (optionText !== undefined && optionValue !== undefined) {
                        const option = new Option(optionText, optionValue);
                        selectElement.add(option);
                        currentAllOptions.push({ id: optionValue, text: optionText }); // Store value as ID for filtering
                    }
                });
                selectElement.disabled = false;
                if (selectElement === siteSelect) { searchSiteNameInput.disabled = false; }
                if (selectElement === outletSelect) { searchOutletNameInput.disabled = false; }
            } else {
                selectElement.innerHTML = `<option value="">Tidak ada ${prompt.toLowerCase().replace('-- pilih ', '').replace('-- ', '')} tersedia</option>`;
                selectElement.disabled = true;
            }

            if (selectElement === siteSelect) { allSiteOptions = currentAllOptions; }
            if (selectElement === outletSelect) { allOutletOptions = currentAllOptions; }

            // Set selected value after options are loaded
            if (selectedValue) {
                selectElement.value = selectedValue;
            }

        } catch (error) {
            console.error(`Error loading ${action}:`, error);
            selectElement.innerHTML = `<option value="">Gagal memuat</option>`;
            selectElement.disabled = true;
            if (selectElement === siteSelect) { searchSiteNameInput.disabled = true; }
            if (selectElement === outletSelect) { searchOutletNameInput.disabled = true; }
            showMessageBox(`Gagal memuat data untuk ${prompt.toLowerCase().replace('-- pilih ', '').replace('-- ', '')}: ${error.message}`);
        }
    }

    // Fungsi untuk memfilter dropdown berdasarkan input pencarian
    function filterDropdown(searchInput, selectElement, allOptions) {
        const searchTerm = searchInput.value.toLowerCase();
        const currentSelectedValue = selectElement.value; // Simpan nilai yang sedang dipilih
        selectElement.innerHTML = '';

        const filteredOptions = allOptions.filter(option =>
            option.text.toLowerCase().includes(searchTerm)
        );

        if (filteredOptions.length === 0) {
            selectElement.add(new Option('Tidak ada hasil', ''));
        } else {
            selectElement.add(new Option(`-- ${filteredOptions.length} hasil ditemukan --`, ''));
            filteredOptions.forEach(item => {
                selectElement.add(new Option(item.text, item.id));
            });
        }
        // Kembalikan pilihan sebelumnya jika masih ada di daftar yang difilter
        if (filteredOptions.some(o => o.id == currentSelectedValue)) {
            selectElement.value = currentSelectedValue;
        } else {
            selectElement.value = ''; // Reset jika pilihan lama tidak ada
        }
    }

    searchSiteNameInput.addEventListener('input', () => filterDropdown(searchSiteNameInput, siteSelect, allSiteOptions));
    searchOutletNameInput.addEventListener('input', () => filterDropdown(searchOutletNameInput, outletSelect, allOutletOptions));

    // --- Logika Geolocation ---
    getLocationBtn.addEventListener('click', function() {
        if (!navigator.geolocation) {
            showMessageBox('Geolocation tidak didukung oleh browser ini.');
            return;
        }

        getLocationBtn.disabled = true;
        getLocationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mencari...';

        navigator.geolocation.getCurrentPosition(
            (position) => {
                latInput.value = position.coords.latitude.toFixed(8);
                lonInput.value = position.coords.longitude.toFixed(8);
                getLocationBtn.disabled = false;
                getLocationBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Gunakan Lokasi Saat Ini';
            },
            (error) => {
                let message = 'Gagal mendapatkan lokasi. ';
                switch(error.code) {
                    case error.PERMISSION_DENIED:
                        message += "Anda menolak permintaan izin lokasi.";
                        break;
                    case error.POSITION_UNAVAILABLE:
                        message += "Informasi lokasi tidak tersedia.";
                        break;
                    case error.TIMEOUT:
                        message += "Permintaan lokasi timeout.";
                        break;
                    case error.UNKNOWN_ERROR:
                        message += "Terjadi kesalahan yang tidak diketahui.";
                        break;
                }
                showMessageBox(message);
                getLocationBtn.disabled = false;
                getLocationBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Gunakan Lokasi Saat Ini';
            }
        );
    });

    // --- Logika Dropdown Berjenjang dan Pre-fill ---
    const initialBranchId = '<?php echo (int)$activity_data['branch_id']; ?>';
    const initialMicroClusterId = '<?php echo (int)$activity_data['micro_cluster_id']; ?>';
    const initialSiteId = '<?php echo (int)$activity_data['site_id']; ?>';
    const initialOutletId = '<?php echo (int)$activity_data['outlet_id']; ?>';
    // Menggunakan project_name dan type_name dari activity_data
    const initialProjectId = '<?php echo htmlspecialchars($activity_data['project_name']); ?>';
    const initialTypeId = '<?php echo htmlspecialchars($activity_data['type_name']); ?>';

    // Fungsi untuk memperbarui jenis Matpro yang tersedia berdasarkan proyek dan lokasi
    async function updateAvailableTypes() {
        typeSelect.innerHTML = '<option value="">-- Pilih Jenis Matpro --</option>';
        typeSelect.disabled = true;
        availableStockSpan.textContent = '0';
        quantityInput.value = '1';
        quantityInput.max = '0'; // Reset max quantity

        const projectName = projectSelect.value; // Menggunakan nama proyek
        const level = inputLevelSelect.value;
        let currentMicroClusterId = '';

        if (level === 'outlet' && microClusterSelect.value) {
            currentMicroClusterId = microClusterSelect.value;
        } else if (level === 'branch') {
            currentMicroClusterId = ''; // Explicitly empty for branch level
        } else {
            return;
        }

        if (projectName) {
            // Mengambil jenis dari matpro_stocks berdasarkan project_name
            const params = `project_name=${encodeURIComponent(projectName)}&branch_id=${userBranchId}&mc_id=${currentMicroClusterId}&min_stock=1`;
            await loadOptions('get_matpro_types_with_stock', params, typeSelect, '-- Pilih Jenis Matpro --', initialTypeId);
            // Setelah jenis dimuat, panggil updateAvailableStock untuk mengisi stok
            updateAvailableStock();
        }
    }

    // Fungsi untuk memperbarui stok yang tersedia berdasarkan proyek, jenis, dan lokasi
    async function updateAvailableStock() {
        availableStockSpan.textContent = '0';
        quantityInput.value = '1';
        quantityInput.max = '0'; // Reset max quantity
        const projectName = projectSelect.value; // Menggunakan nama proyek
        const typeName = typeSelect.value;     // Menggunakan nama jenis
        const level = inputLevelSelect.value;
        let currentMicroClusterId = '';

        if (level === 'outlet' && microClusterSelect.value) {
            currentMicroClusterId = microClusterSelect.value;
        } else if (level === 'branch') {
            currentMicroClusterId = '';
        } else {
            return;
        }

        if (projectName && typeName) {
            const params = `project_name=${encodeURIComponent(projectName)}&type_name=${encodeURIComponent(typeName)}&branch_id=${userBranchId}`;
            if (currentMicroClusterId) {
                params += `&mc_id=${currentMicroClusterId}`;
            }

            try {
                const response = await fetch(`api_helper.php?action=get_matpro_stock_quantity&${params}`);
                const data = await response.json();
                if (data && data.stock_quantity !== undefined) {
                    const stock = parseInt(data.stock_quantity);
                    availableStockSpan.textContent = stock;
                    quantityInput.max = stock;
                    if (stock <= 0) {
                        showMessageBox('Stok untuk jenis matpro ini sudah habis.');
                        quantityInput.value = '0';
                    } else {
                        // Jika ada stok, dan qty_used dari aktivitas sebelumnya lebih besar dari stok saat ini,
                        // set qty_used ke stok maksimum yang tersedia.
                        const currentQtyUsed = parseInt('<?php echo htmlspecialchars($activity_data['qty_used']); ?>');
                        if (currentQtyUsed > stock) {
                            quantityInput.value = stock;
                            showMessageBox('Kuantitas aktivitas disesuaikan karena stok saat ini lebih rendah.');
                        } else {
                            quantityInput.value = currentQtyUsed; // Pertahankan nilai lama jika valid
                        }
                    }
                } else {
                    availableStockSpan.textContent = '0';
                    quantityInput.max = '0';
                    showMessageBox('Stok tidak ditemukan atau data tidak valid.');
                }
            } catch (error) {
                console.error('Error fetching stock quantity:', error);
                showMessageBox(`Gagal memuat jumlah stok: ${error.message}`);
            }
        }
    }

    // Event listener untuk perubahan level input
    inputLevelSelect.addEventListener('change', async () => {
        const level = inputLevelSelect.value;
        // Reset semua dropdown terkait lokasi dan stok
        const fieldsToReset = [microClusterSelect, siteSelect, outletSelect, typeSelect];
        fieldsToReset.forEach(field => {
            field.innerHTML = '<option value="">-- Pilih --</option>';
            field.disabled = true;
        });
        kecamatanKabupatenInput.value = '';
        availableStockSpan.textContent = '0';
        quantityInput.value = '1';
        quantityInput.max = '0';
        projectSelect.value = '';
        searchSiteNameInput.value = ''; searchSiteNameInput.disabled = true; allSiteOptions = [];
        searchOutletNameInput.value = ''; searchOutletNameInput.disabled = true; allOutletOptions = [];

        if (level === 'outlet') {
            microClusterDiv.classList.remove('hidden');
            microClusterSelect.required = true;
            siteSelect.required = true;
            outletSelect.required = true;
            const action = userHasSpecificMc ? 'get_user_micro_clusters' : 'get_micro_clusters_by_branch';
            const params = userHasSpecificMc ? `user_id=<?php echo $user_id; ?>` : `branch_id=${userBranchId}`;
            await loadOptions(action, params, microClusterSelect, '-- Pilih Micro Cluster --');
            // Jika ada initialMicroClusterId dan levelnya outlet, pre-select MC
            if (initialMicroClusterId && initialMicroClusterId !== 0) {
                microClusterSelect.value = initialMicroClusterId;
                await loadOptions('get_sites', `micro_cluster_id=${initialMicroClusterId}`, siteSelect, '-- Pilih Site --', initialSiteId);
                if (initialSiteId) {
                    await loadOptions('get_outlets_by_site', `site_id=${initialSiteId}`, outletSelect, '-- Pilih Outlet --', initialOutletId);
                }
            }
        } else if (level === 'branch') {
            microClusterDiv.classList.add('hidden');
            microClusterSelect.required = false;
            microClusterSelect.value = '';
            siteSelect.required = true;
            outletSelect.required = true;
            await loadOptions('get_sites_by_branch', `branch_id=${userBranchId}`, siteSelect, '-- Pilih Site --', initialSiteId);
            if (initialSiteId) {
                await loadOptions('get_outlets_by_site', `site_id=${initialSiteId}`, outletSelect, '-- Pilih Outlet --', initialOutletId);
            }
        } else {
            microClusterDiv.classList.add('hidden');
            microClusterSelect.required = false;
            microClusterSelect.value = '';
            siteSelect.required = false;
            outletSelect.required = false;
        }
        updateAvailableTypes();
    });

    // Event listener untuk perubahan Micro Cluster
    microClusterSelect.addEventListener('change', async () => {
        siteSelect.innerHTML = '<option value="">-- Pilih --</option>'; siteSelect.disabled = true;
        outletSelect.innerHTML = '<option value="">-- Pilih --</option>'; outletSelect.disabled = true;
        searchSiteNameInput.value = ''; searchSiteNameInput.disabled = true; allSiteOptions = [];
        searchOutletNameInput.value = ''; searchOutletNameInput.disabled = true; allOutletOptions = [];
        kecamatanKabupatenInput.value = '';
        if (microClusterSelect.value) {
            await loadOptions('get_sites', `micro_cluster_id=${microClusterSelect.value}`, siteSelect, '-- Pilih Site --');
        }
        updateAvailableTypes();
    });

    // Event listener untuk perubahan Site
    siteSelect.addEventListener('change', async () => {
        outletSelect.innerHTML = '<option value="">-- Pilih --</option>'; outletSelect.disabled = true;
        searchOutletNameInput.value = ''; searchOutletNameInput.disabled = true; allOutletOptions = [];
        kecamatanKabupatenInput.value = '';
        if (siteSelect.value) {
            await loadOptions('get_outlets_by_site', `site_id=${siteSelect.value}`, outletSelect, '-- Pilih Outlet --');
            try {
                const response = await fetch(`api_helper.php?action=get_site_details&site_id=${siteSelect.value}`);
                const data = await response.json();
                if (data) {
                    kecamatanKabupatenInput.value = `${data.kecamatan || 'N/A'} | ${data.kabupaten || 'N/A'}`;
                }
            } catch (error) { console.error('Error fetching site details:', error); }
        }
    });

    // Event listener untuk perubahan Proyek
    projectSelect.addEventListener('change', updateAvailableTypes);
    // Event listener untuk perubahan Jenis Matpro
    typeSelect.addEventListener('change', updateAvailableStock);

    // --- Validasi Form Saat Submit ---
    form.addEventListener('submit', function(event) {
        const qty = parseInt(quantityInput.value);
        const maxQty = parseInt(quantityInput.max);

        if (isNaN(qty) || isNaN(maxQty) || qty <= 0 || qty > maxQty) {
            showMessageBox(`Kuantitas tidak valid. Pastikan diisi antara 1 dan ${maxQty} (Stok tersedia).`);
            event.preventDefault();
            return;
        }

        const level = inputLevelSelect.value;
        if (level === 'outlet' && !microClusterSelect.value) {
            showMessageBox('Mohon pilih Micro Cluster.');
            event.preventDefault();
            return;
        }
        if (!siteSelect.value) {
            showMessageBox('Mohon pilih Site Name.');
            event.preventDefault();
            return;
        }
        if (!outletSelect.value) {
            showMessageBox('Mohon pilih ID Outlet | Nama Outlet.');
            event.preventDefault();
            return;
        }
        if (!projectSelect.value) {
            showMessageBox('Mohon pilih Proyek Matpro.');
            event.preventDefault();
            return;
        }
        if (!typeSelect.value) {
            showMessageBox('Mohon pilih Jenis Matpro.');
            event.preventDefault();
            return;
        }
        if (!latInput.value || !lonInput.value) {
            showMessageBox('Latitude dan Longitude wajib diisi.');
            event.preventDefault();
            return;
        }

        // Validasi file foto hanya jika tidak ada foto eksisting DAN tidak ada file baru diupload
        const existingPhotoBefore = document.querySelector('input[name="existing_photo_before_url"]');
        const existingPhotoAfter = document.querySelector('input[name="existing_photo_after_url"]');

        if (!existingPhotoBefore && !photoBeforeInput.files.length) {
            showMessageBox('Foto Sebelum Pemasangan wajib diunggah.');
            event.preventDefault();
            return;
        }
        if (!existingPhotoAfter && !photoAfterInput.files.length) {
            showMessageBox('Foto Sesudah Pemasangan wajib diunggah.');
            event.preventDefault();
            return;
        }
    });

    // --- Inisialisasi Awal Halaman ---
    async function initializeForm() {
        // Pre-fill level input
        inputLevelSelect.value = '<?php echo $current_input_level; ?>';

        // Trigger change event for input_level to load initial cascading dropdowns
        const event = new Event('change');
        inputLevelSelect.dispatchEvent(event);

        // Load initial data for Project and Type, then stock
        if (initialProjectId) {
            projectSelect.value = initialProjectId;
            await updateAvailableTypes(); // This will load types and then call updateAvailableStock
            // No need to call updateAvailableStock separately here, as it's chained.
        }
    }

    initializeForm();
});
</script>
