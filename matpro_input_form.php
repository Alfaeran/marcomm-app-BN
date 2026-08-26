<?php
// matpro_input_form.php
require_once 'config/database.php';

// Cek jika user tidak login atau bukan 'user'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}

// Ambil pesan dari session
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// Ambil data pengguna dari Session
$user_id = (int)$_SESSION['id'];
$user_branch_id = $_SESSION['branch_id'] ?? null;
$user_brand = $_SESSION['brand'] ?? null;

if (empty($user_branch_id)) {
    $_SESSION['error_message'] = "Error: Akun Anda tidak terhubung ke Branch manapun. Silakan hubungi Admin.";
    header("location: dashboard_user.php");
    exit;
}

// --- Logika Pengambilan Data Awal dari matpro_stocks ---
$projects = [];
$branches = [];

try {
    // Ambil daftar proyek unik dari matpro_stocks yang dialokasikan untuk user ini
    $stmt_projects = $mysqli->prepare(
        "SELECT DISTINCT project_name, project_brand FROM matpro_stocks
         WHERE user_id = ? AND is_active = 1 AND stock_quantity > 0
         ORDER BY project_name"
    );
    if (!$stmt_projects) {
        throw new Exception("Gagal menyiapkan query proyek: " . $mysqli->error);
    }
    $stmt_projects->bind_param("i", $user_id);
    $stmt_projects->execute();
    $projects_result = $stmt_projects->get_result();
    $projects = $projects_result->fetch_all(MYSQLI_ASSOC);
    $stmt_projects->close();

    // Ambil data branch
    $branches_result = $mysqli->query("SELECT id, nama_branch FROM branches ORDER BY nama_branch");
    if ($branches_result === false) {
        throw new Exception("Query untuk mengambil data branch gagal: " . $mysqli->error);
    }
    $branches = $branches_result->fetch_all(MYSQLI_ASSOC);

} catch (Exception $e) {
    $error_message = "Terjadi kesalahan saat memuat data awal. Pastikan tabel 'matpro_stocks' dan 'branches' ada dan dapat diakses.";
    error_log("Error in matpro_input_form.php: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Input Matpro - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
    <style>
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
                    <button onclick="toggleSidebar()" class="lg:hidden p-3 text-slate-600 glass-card"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Form Aktivitas Matpro</h2>
                        <p class="text-slate-500 font-medium">Lengkapi data pemasangan material promosi Anda.</p>
                    </div>
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

                <form id="matproActivityForm" action="process/matpro_activity_process.php" method="post" enctype="multipart/form-data" class="space-y-8">
                    <input type="hidden" name="activity_datetime" id="activity_datetime_hidden">
                    <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($user_branch_id); ?>">
                    <input type="hidden" name="project_name" id="project_name_hidden" value="0">
                    <input type="hidden" name="type_name" id="type_name_hidden" value="0">


                <!-- Section 1: Lokasi & Waktu -->
                <div class="glass-card p-8">
                    <h3 class="form-section-title text-lg uppercase tracking-widest text-slate-800">Informasi Lokasi & Waktu</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-4">
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Branch</label>
                            <input type="text" value="<?php foreach($branches as $b) { if($b['id'] == $user_branch_id) echo htmlspecialchars($b['nama_branch']); } ?>" readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-500 cursor-not-allowed">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Waktu Aktivitas</label>
                            <input type="text" id="activity_datetime_display" readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-500 cursor-not-allowed">
                        </div>
                        <div class="space-y-2">
                            <label for="input_level" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Level Input <span class="text-red-500">*</span></label>
                            <select name="input_level" id="input_level" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                <option value="">-- Pilih Level --</option>
                                <option value="branch">Branch Level</option>
                            </select>
                        </div>
                        <div id="micro_cluster_div" class="space-y-2 hidden">
                            <label for="micro_cluster_id" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Micro Cluster <span class="text-red-500">*</span></label>
                            <select id="micro_cluster_id" name="micro_cluster_id" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer" disabled>
                                <option value="">-- Pilih Level Input dulu --</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label for="search_site_name" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Cari Site <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-search text-xs"></i></span>
                                <input type="text" id="search_site_name" placeholder="Ketik nama site..." class="w-full bg-slate-50 border border-slate-200 rounded-t-xl pl-10 pr-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all" disabled>
                                <select id="site_id" name="site_id" required class="w-full bg-slate-50 border-t-0 border-slate-200 rounded-b-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer" disabled>
                                    <option value="">-- Hasil Pencarian --</option>
                                </select>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label for="search_outlet_name" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Cari Outlet <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-search text-xs"></i></span>
                                <input type="text" id="search_outlet_name" placeholder="ID/Nama Outlet..." class="w-full bg-slate-50 border border-slate-200 rounded-t-xl pl-10 pr-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all" disabled>
                                <select id="outlet_id" name="outlet_id" required class="w-full bg-slate-50 border-t-0 border-slate-200 rounded-b-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer" disabled>
                                    <option value="">-- Pilih Site dulu --</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <label for="latitude" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Lat <span class="text-red-500">*</span></label>
                                <input type="text" name="location_latitude" id="latitude" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all" placeholder="-8.65">
                            </div>
                            <div class="space-y-2">
                                <label for="longitude" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Lon <span class="text-red-500">*</span></label>
                                <input type="text" name="location_longitude" id="longitude" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all" placeholder="115.21">
                            </div>
                        </div>
                        <div class="lg:col-span-2 flex justify-end">
                            <button type="button" id="getLocationBtn" class="px-6 py-2.5 bg-blue-50 text-blue-600 font-bold text-xs rounded-xl hover:bg-blue-600 hover:text-white transition-all flex items-center gap-2 border border-blue-100 shadow-sm active:scale-95">
                                <i class="fas fa-map-marker-alt"></i> Dapatkan Lokasi Saat Ini
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Detail Matpro -->
                <div class="glass-card p-8">
                    <h3 class="form-section-title text-lg uppercase tracking-widest text-slate-800">Detail Material Promosi</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mt-4">
                        <div class="space-y-2">
                            <label for="project_name" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Proyek Matpro <span class="text-red-500">*</span></label>
                            <select id="project_name" name="project_name" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                <option value="">-- Pilih Proyek --</option>
                                <?php foreach ($projects as $project): ?>
                                    <?php if ($project['project_brand'] === 'BOTH' || $project['project_brand'] === $user_brand): ?>
                                        <option value="<?php echo htmlspecialchars($project['project_name']); ?>"><?php echo htmlspecialchars($project['project_name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label for="type_name" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Jenis Matpro <span class="text-red-500">*</span></label>
                            <select id="type_name" name="type_name" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer" disabled>
                                <option value="">-- Pilih Proyek dulu --</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label for="qty_used" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">QTY Digunakan <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="number" name="qty_used" id="qty_used" value="1" min="1" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-black focus:ring-4 focus:ring-blue-100 outline-none transition-all">
                                <div class="absolute right-4 top-1/2 -translate-y-1/2 flex flex-col items-end">
                                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Sisa Stok</span>
                                    <span id="available_stock" class="text-xs font-black text-blue-600">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Dokumentasi -->
                <div class="glass-card p-8">
                    <h3 class="form-section-title text-lg uppercase tracking-widest text-slate-800">Dokumentasi Pemasangan</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mt-4">
                        <div class="space-y-3">
                            <label for="photo_before" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Foto Sebelum <span class="text-red-500">*</span></label>
                            <input type="file" name="photo_before" id="photo_before" required accept="image/*" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all cursor-pointer">
                        </div>
                        <div class="space-y-3">
                            <label for="photo_after" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Foto Sesudah <span class="text-red-500">*</span></label>
                            <input type="file" name="photo_after" id="photo_after" required accept="image/*" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 transition-all cursor-pointer">
                        </div>
                    </div>
                </div>

                <div class="pb-20">
                    <button type="submit" id="submitBtn" class="w-full py-4 bg-blue-600 text-white font-extrabold text-lg rounded-2xl hover:bg-blue-700 shadow-xl shadow-blue-200 active:scale-95 transition-all flex items-center justify-center gap-3">
                        <i class="fas fa-save"></i> Simpan Aktivitas Matpro
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>
        </form>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('matproActivityForm');
    const latInput = document.getElementById('latitude');
    const lonInput = document.getElementById('longitude');
    const getLocationBtn = document.getElementById('getLocationBtn');
    const inputLevelSelect = document.getElementById('input_level');
    const microClusterDiv = document.getElementById('micro_cluster_div');
    const microClusterSelect = document.getElementById('micro_cluster_id');
    const siteSelect = document.getElementById('site_id');
    const outletSelect = document.getElementById('outlet_id');
    const projectSelect = document.getElementById('project_name');
    const typeSelect = document.getElementById('type_name');
    const quantityInput = document.getElementById('qty_used');
    const availableStockSpan = document.getElementById('available_stock');
    const activityTimeDisplay = document.getElementById('activity_datetime_display');
    const activityTimeHidden = document.getElementById('activity_datetime_hidden');

    // New search inputs
    const searchSiteNameInput = document.getElementById('search_site_name');
    const searchOutletNameInput = document.getElementById('search_outlet_name');

    let allSiteOptions = [];
    let allOutletOptions = [];

    const userBranchId = '<?php echo (int)$user_branch_id; ?>';

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

    // Function to update time display
    function updateTime() {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');

        const displayFormat = `${day}/${month}/${year} ${hours}:${minutes}:${seconds}`;
        const submitFormat = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;

        activityTimeDisplay.value = displayFormat;
        activityTimeHidden.value = submitFormat;
    }

    // Update time immediately and then every second
    updateTime();
    setInterval(updateTime, 1000);

    async function loadOptions(action, params, selectElement, prompt, selectedValue = null) {
        selectElement.innerHTML = `<option value="">Memuat...</option>`;
        selectElement.disabled = true;
        
        // Disable and clear search inputs when loading new options
        if (selectElement === siteSelect) {
            searchSiteNameInput.disabled = true;
            searchSiteNameInput.value = '';
            allSiteOptions = [];
        }
        if (selectElement === outletSelect) {
            searchOutletNameInput.disabled = true;
            searchOutletNameInput.value = '';
            allOutletOptions = [];
        }

        try {
            const url = `api_helper.php?action=${action}&${params}`;
            console.log(`Fetching: ${url}`); // Log URL
            const response = await fetch(url);

            if (!response.ok) {
                const errorText = await response.text();
                let errorMessage = `Gagal mengambil data: Server merespons dengan status ${response.status}.`;
                try {
                    const errorJson = JSON.parse(errorText);
                    if (errorJson.error) {
                        errorMessage = `Error dari API (${response.status}): ${errorJson.error}`;
                    } else if (errorJson.message) {
                        errorMessage = `Error dari API (${response.status}): ${errorJson.message}`;
                    }
                } catch (e) {
                    errorMessage += ` Respons: ${errorText.substring(0, 100)}...`;
                }
                throw new Error(errorMessage);
            }

            const data = await response.json();
            console.log(`Data diterima untuk ${action}:`, data); // Log data

            if (data.error) {
                throw new Error(`Error dari API: ${data.error}`);
            }

            if (!Array.isArray(data)) {
                throw new Error("Format data yang diterima tidak valid (bukan array).");
            }

            selectElement.innerHTML = `<option value="">${prompt}</option>`;
            let currentAllOptions = []; // To store all options for filtering

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
                        // FIX: Directly use item.text as the display text, as API now aliases to 'text'
                        optionText = item.text;
                        optionValue = item.id;
                    } else if (selectElement === typeSelect) {
                        // FIX: Directly use item.text as the display text, as API now aliases to 'text'
                        optionText = item.text;
                        optionValue = item.id;
                    } else {
                        // Fallback for any other generic dropdowns, if applicable
                        optionText = item.text || item.name || item.value;
                        optionValue = item.id || item.value || item.text;
                    }

                    // FIX: Add a check to ensure optionText and optionValue are not null/undefined before adding
                    if (optionText !== undefined && optionText !== null && optionValue !== undefined && optionValue !== null) {
                        const option = new Option(optionText, optionValue);
                        selectElement.add(option);
                        currentAllOptions.push({ id: optionValue, text: optionText }); // Store both for filtering
                    } else {
                        console.warn(`Skipping option due to undefined/null text or value for ${action}:`, item);
                    }
                });
                selectElement.disabled = false;
                // Enable search inputs after options are loaded
                if (selectElement === siteSelect) { searchSiteNameInput.disabled = false; }
                if (selectElement === outletSelect) { searchOutletNameInput.disabled = false; }

            } else {
                selectElement.innerHTML = `<option value="">-- Tidak ada data --</option>`;
            }

            // Store all options for client-side filtering
            if (selectElement === siteSelect) { allSiteOptions = currentAllOptions; }
            if (selectElement === outletSelect) { allOutletOptions = currentAllOptions; }

            // Set selected value after options are loaded
            if (selectedValue) {
                selectElement.value = selectedValue;
            }

        } catch (error) {
            console.error(`Error loading ${action}:`, error);
            selectElement.innerHTML = `<option value="">Gagal memuat data</option>`;
            showMessageBox(`Terjadi masalah saat memuat data untuk ${prompt}. Detail: ${error.message}`);
            // Ensure search inputs are disabled on error
            if (selectElement === siteSelect) { searchSiteNameInput.disabled = true; }
            if (selectElement === outletSelect) { searchOutletNameInput.disabled = true; }
        }
    }

    // Function to filter dropdown based on search input
    function filterDropdown(searchInput, selectElement, allOptions) {
        const searchTerm = searchInput.value.toLowerCase();
        const currentSelectedValue = selectElement.value; // Store current selection
        selectElement.innerHTML = ''; // Clear current options

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
        // Restore previous selection if it's still in the filtered list
        if (filteredOptions.some(o => o.id == currentSelectedValue)) {
            selectElement.value = currentSelectedValue;
        } else {
            selectElement.value = ''; // Reset if old selection is not available
        }
    }

    // Event listeners for search inputs
    searchSiteNameInput.addEventListener('input', () => filterDropdown(searchSiteNameInput, siteSelect, allSiteOptions));
    searchOutletNameInput.addEventListener('input', () => filterDropdown(searchOutletNameInput, outletSelect, allOutletOptions));


    getLocationBtn.addEventListener('click', function() {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    latInput.value = position.coords.latitude.toFixed(8);
                    lonInput.value = position.coords.longitude.toFixed(8);
                    showMessageBox('Lokasi berhasil didapatkan.');
                },
                (error) => {
                    showMessageBox('Gagal mendapatkan lokasi: ' + error.message);
                }
            );
        } else {
            showMessageBox('Geolocation tidak didukung oleh browser ini.');
        }
    });

    inputLevelSelect.addEventListener('change', () => {
        const level = inputLevelSelect.value;
        siteSelect.innerHTML = '<option value="">-- Pilih --</option>';
        siteSelect.disabled = true;
        outletSelect.innerHTML = '<option value="">-- Pilih --</option>';
        outletSelect.disabled = true;
        typeSelect.innerHTML = '<option value="">-- Pilih Proyek --</option>';
        typeSelect.disabled = true;
        availableStockSpan.textContent = '0';
        quantityInput.max = 0;

        // Disable and clear search inputs on level change
        searchSiteNameInput.disabled = true;
        searchSiteNameInput.value = '';
        allSiteOptions = [];
        searchOutletNameInput.disabled = true;
        searchOutletNameInput.value = '';
        allOutletOptions = [];


        if (level === 'outlet') {
            microClusterDiv.classList.remove('hidden');
            microClusterSelect.disabled = false;
            microClusterSelect.required = true;
            loadOptions('get_micro_clusters_by_branch', `branch_id=${userBranchId}`, microClusterSelect, '-- Pilih Micro Cluster --');
        } else if (level === 'branch') {
            microClusterDiv.classList.add('hidden');
            microClusterSelect.disabled = true;
            microClusterSelect.required = false;
            microClusterSelect.value = '';
            loadOptions('get_sites_by_branch', `branch_id=${userBranchId}`, siteSelect, '-- Pilih Site --');
        } else {
            microClusterDiv.classList.add('hidden');
            microClusterSelect.disabled = true;
            microClusterSelect.required = false;
        }
    });

    microClusterSelect.addEventListener('change', () => {
        const mcId = microClusterSelect.value;
        outletSelect.innerHTML = '<option value="">-- Pilih Site dulu --</option>';
        outletSelect.disabled = true;
        // Disable and clear outlet search input
        searchOutletNameInput.disabled = true;
        searchOutletNameInput.value = '';
        allOutletOptions = [];

        if (mcId) {
            loadOptions('get_sites', `micro_cluster_id=${mcId}`, siteSelect, '-- Pilih Site --');
        } else {
            siteSelect.innerHTML = '<option value="">-- Pilih MC dulu --</option>';
            siteSelect.disabled = true;
            // Disable and clear site search input
            searchSiteNameInput.disabled = true;
            searchSiteNameInput.value = '';
            allSiteOptions = [];
        }
    });

    siteSelect.addEventListener('change', () => {
        const siteId = siteSelect.value;
        if (siteId) {
            loadOptions('get_outlets_by_site', `site_id=${siteId}`, outletSelect, '-- Pilih Outlet --');
        } else {
            outletSelect.innerHTML = '<option value="">-- Pilih Site dulu --</option>';
            outletSelect.disabled = true;
            // Disable and clear outlet search input
            searchOutletNameInput.disabled = true;
            searchOutletNameInput.value = '';
            allOutletOptions = [];
        }
    });

    async function updateAvailableTypes() {
        const projectName = projectSelect.value;
        const level = inputLevelSelect.value;
        const mcId = microClusterSelect.value;

        typeSelect.disabled = true;
        typeSelect.innerHTML = '<option value="">-- Pilih Jenis --</option>';
        availableStockSpan.textContent = '0';
        quantityInput.max = 0;

        if (!projectName || !level) return;
        if (level === 'outlet' && !mcId) return;

        let params = `project_name=${encodeURIComponent(projectName)}&branch_id=${userBranchId}`;
        if (level === 'outlet') {
            params += `&mc_id=${mcId}`;
        }

        await loadOptions('get_matpro_types_with_stock', params, typeSelect, '-- Pilih Jenis Matpro --');
    }

    async function updateStockQuantity() {
        const projectName = projectSelect.value;
        const typeName = typeSelect.value;
        const level = inputLevelSelect.value;
        const mcId = microClusterSelect.value;

        availableStockSpan.textContent = '0';
        quantityInput.max = 0;

        if (!projectName || !typeName || !level) return;
        if (level === 'outlet' && !mcId) return;

        let params = `project_name=${encodeURIComponent(projectName)}&type_name=${encodeURIComponent(typeName)}&branch_id=${userBranchId}`;
        if (level === 'outlet') {
            params += `&mc_id=${mcId}`;
        }

        try {
            const response = await fetch(`api_helper.php?action=get_matpro_stock_quantity&${params}`);
            const data = await response.json();
            if (data && data.stock_quantity !== undefined) {
                const stock = parseInt(data.stock_quantity);
                availableStockSpan.textContent = stock;
                quantityInput.max = stock;
            }
        } catch (error) {
            console.error('Error fetching stock quantity:', error);
        }
    }

    projectSelect.addEventListener('change', updateAvailableTypes);
    microClusterSelect.addEventListener('change', updateAvailableTypes);
    inputLevelSelect.addEventListener('change', updateAvailableTypes);
    typeSelect.addEventListener('change', updateStockQuantity);

    form.addEventListener('submit', function(e) {
        const maxStock = parseInt(quantityInput.max) || 0;
        const qtyUsed = parseInt(quantityInput.value) || 0;
        if (qtyUsed > maxStock) {
            e.preventDefault();
            showMessageBox(`Kuantitas yang digunakan (${qtyUsed}) melebihi stok yang tersedia (${maxStock}).`);
        }
    });
});
</script>

</body>
</html>
