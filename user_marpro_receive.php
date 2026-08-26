<?php
// user_marpro_receive.php
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
$user_username = $_SESSION['username'] ?? '';
$user_branch_id = $_SESSION['branch_id'] ?? null;
$user_brand = $_SESSION['brand'] ?? null;

if (empty($user_branch_id)) {
    $_SESSION['error_message'] = "Error: Akun Anda tidak terhubung ke Branch manapun. Silakan hubungi Admin.";
    header("location: dashboard_user.php");
    exit;
}

$app_name = get_setting($mysqli, 'app_name');

// Ambil data branch
$branch_name = '';
$stmt_br = $mysqli->prepare("SELECT nama_branch FROM branches WHERE id = ?");
if ($stmt_br) {
    $stmt_br->bind_param("i", $user_branch_id);
    $stmt_br->execute();
    $res_br = $stmt_br->get_result();
    if ($row_br = $res_br->fetch_assoc()) {
        $branch_name = $row_br['nama_branch'];
    }
    $stmt_br->close();
}

// Ambil riwayat penerimaan Marpro di Branch ini
$receives = [];
$sql_rec = "SELECT mr.id, mr.tanggal_terima, mr.project_name, mr.type_name, mr.qty_branch, mr.qty_allocated, mr.diterima_siapa, mr.photo_url, mr.latitude, mr.longitude
            FROM marpro_receives mr
            WHERE mr.branch_id = ?
            ORDER BY mr.tanggal_terima DESC, mr.id DESC LIMIT 25";
$stmt_rec = $mysqli->prepare($sql_rec);
if ($stmt_rec) {
    $stmt_rec->bind_param("i", $user_branch_id);
    $stmt_rec->execute();
    $receives = $stmt_rec->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_rec->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Penerimaan Marpro - <?php echo strip_tags($app_name); ?></title>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Form Penerimaan Marpro (Branch)</h2>
                        <p class="text-slate-500 font-medium">Catat penerimaan material promosi baru di Branch <?php echo htmlspecialchars($branch_name); ?></p>
                    </div>
                </div>
            </header>

            <div class="max-w-5xl space-y-12">
                <?php if ($success_message): ?>
                    <div class="glass-card bg-emerald-50/50 border-emerald-200 p-4 mb-4 flex items-center gap-3">
                        <div class="h-10 w-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle"></i></div>
                        <p class="text-emerald-800 font-bold"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="glass-card bg-red-50/50 border-red-200 p-4 mb-4 flex items-center gap-3">
                        <div class="h-10 w-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center"><i class="fas fa-exclamation-triangle"></i></div>
                        <p class="text-red-800 font-bold"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Form Penerimaan -->
                <div class="glass-card p-8 border border-white/50 shadow-xl shadow-blue-900/5">
                    <form id="marproReceiveForm" action="process/user_marpro_receive_process.php" method="post" enctype="multipart/form-data" class="space-y-8">
                        <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($user_branch_id); ?>">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <!-- Kolom Kiri: Detail Marpro -->
                            <div class="space-y-6">
                                <h3 class="form-section-title text-lg uppercase tracking-widest text-slate-800">Detail Penerimaan</h3>
                                

                                <div class="space-y-2">
                                    <label for="project_id" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Proyek Marpro <span class="text-red-500">*</span></label>
                                    <select id="project_id" name="project_id" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer">
                                        <option value="">-- Pilih Proyek --</option>
                                    </select>
                                </div>

                                <div class="space-y-2">
                                    <label for="type_id" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Jenis Marpro <span class="text-red-500">*</span></label>
                                    <select id="type_id" name="type_id" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all appearance-none cursor-pointer" disabled>
                                        <option value="">-- Pilih Proyek dulu --</option>
                                    </select>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <label for="tanggal_terima" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Tanggal Terima <span class="text-red-500">*</span></label>
                                        <input type="date" name="tanggal_terima" id="tanggal_terima" value="<?php echo date('Y-m-d'); ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all">
                                    </div>
                                    <div class="space-y-2">
                                        <label for="qty_branch" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">QTY Terima (Pcs) <span class="text-red-500">*</span></label>
                                        <input type="number" name="qty_branch" id="qty_branch" value="1" min="1" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-black focus:ring-4 focus:ring-blue-100 outline-none transition-all">
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label for="diterima_siapa" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Diterima Oleh <span class="text-red-500">*</span></label>
                                    <input type="text" name="diterima_siapa" id="diterima_siapa" value="<?php echo htmlspecialchars($user_username); ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all">
                                </div>
                            </div>

                            <!-- Kolom Kanan: Kamera & Geolocation -->
                            <div class="space-y-6">
                                <h3 class="form-section-title text-lg uppercase tracking-widest text-slate-800">Bukti Fisik & Lokasi</h3>

                                <div class="space-y-2">
                                    <label for="photo_bukti" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Ambil Foto Bukti <span class="text-red-500">*</span></label>
                                    <input type="file" name="photo_bukti" id="photo_bukti" required accept="image/*" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all cursor-pointer">
                                    <p class="text-[10px] text-slate-400 font-bold italic mt-1"><i class="fas fa-camera mr-1"></i> Mendukung pengambilan foto kamera langsung maupun unggah dari galeri.</p>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <label for="latitude" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Latitude <span class="text-red-500">*</span></label>
                                        <input type="text" name="latitude" id="latitude" required readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-500 cursor-not-allowed" placeholder="Mendeteksi...">
                                    </div>
                                    <div class="space-y-2">
                                        <label for="longitude" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Longitude <span class="text-red-500">*</span></label>
                                        <input type="text" name="longitude" id="longitude" required readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-500 cursor-not-allowed" placeholder="Mendeteksi...">
                                    </div>
                                </div>

                                <div class="flex justify-end pt-2">
                                    <button type="button" id="getLocationBtn" class="px-5 py-2.5 bg-blue-50 text-blue-600 font-bold text-xs rounded-xl hover:bg-blue-600 hover:text-white transition-all flex items-center gap-2 border border-blue-100 shadow-sm active:scale-95">
                                        <i class="fas fa-sync-alt"></i> Segarkan GPS
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-slate-100">
                            <button type="submit" id="submitBtn" class="w-full py-4 bg-blue-600 text-white font-extrabold text-base rounded-2xl hover:bg-blue-700 shadow-xl shadow-blue-200 active:scale-95 transition-all flex items-center justify-center gap-3">
                                <i class="fas fa-download"></i> Simpan Penerimaan Marpro
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Tabel Riwayat -->
                <div class="glass-card p-8 border border-white/50 shadow-xl shadow-blue-900/5">
                    <h3 class="form-section-title text-lg uppercase tracking-widest text-slate-800">Riwayat Penerimaan di Branch Anda</h3>
                    <div class="overflow-x-auto mt-4">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50/50 border-b border-slate-100">
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tanggal</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Material / Proyek</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">QTY</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Teralokasi</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Diterima Oleh</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Foto</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (!empty($receives)): ?>
                                    <?php foreach ($receives as $row): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors text-sm">
                                        <td class="py-4 px-6 font-bold text-slate-700"><?php echo date('d M Y', strtotime($row['tanggal_terima'])); ?></td>
                                        <td class="py-4 px-6">
                                            <p class="font-bold text-slate-800"><?php echo htmlspecialchars($row['type_name']); ?></p>
                                            <p class="text-xs text-slate-400 font-medium"><?php echo htmlspecialchars($row['project_name']); ?></p>
                                        </td>
                                        <td class="py-4 px-6 text-center font-black text-slate-800"><?php echo number_format($row['qty_branch']); ?></td>
                                        <td class="py-4 px-6 text-center font-semibold">
                                            <span class="px-2 py-1 rounded-lg text-xs font-bold <?php echo $row['qty_allocated'] >= $row['qty_branch'] ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : ($row['qty_allocated'] > 0 ? 'bg-amber-50 text-amber-600 border border-amber-100' : 'bg-slate-100 text-slate-400'); ?>">
                                                <?php echo number_format($row['qty_allocated']); ?> / <?php echo number_format($row['qty_branch']); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-6 text-slate-500 font-medium"><?php echo htmlspecialchars($row['diterima_siapa']); ?></td>
                                        <td class="py-4 px-6 text-center">
                                            <a href="<?php echo htmlspecialchars($row['photo_url']); ?>" target="_blank" class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-blue-50 text-blue-600 border border-blue-100 hover:bg-blue-600 hover:text-white transition-all shadow-sm">
                                                <i class="fas fa-image"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="py-12 text-center text-slate-400 font-bold">Belum ada riwayat penerimaan Marpro di Branch ini.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal Alert Helper -->
    <div id="msgModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-[200] p-4 hidden">
        <div class="glass-card max-w-sm w-full p-8 text-center shadow-2xl">
            <div id="modalIconBg" class="h-16 w-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-6">
                <i id="modalIcon" class="fas fa-info-circle"></i>
            </div>
            <h3 class="text-lg font-extrabold text-slate-800 mb-2">Pemberitahuan</h3>
            <p id="modalMsg" class="text-sm font-medium text-slate-600 mb-8 leading-relaxed"></p>
            <button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl shadow-lg active:scale-95 transition-all" onclick="closeMsgModal()">Mengerti</button>
        </div>
    </div>

    <script>
    function showMsg(message, type = 'blue') {
        const modal = document.getElementById('msgModal');
        const modalMsg = document.getElementById('modalMsg');
        const modalIcon = document.getElementById('modalIcon');
        const modalIconBg = document.getElementById('modalIconBg');

        modalMsg.textContent = message;

        // Reset classes
        modalIconBg.className = "h-16 w-16 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-6 ";
        
        if(type === 'green') {
            modalIconBg.classList.add('bg-emerald-50', 'text-emerald-600');
            modalIcon.className = "fas fa-check-circle";
        } else if(type === 'red') {
            modalIconBg.classList.add('bg-red-50', 'text-red-600');
            modalIcon.className = "fas fa-exclamation-triangle";
        } else {
            modalIconBg.classList.add('bg-blue-50', 'text-blue-600');
            modalIcon.className = "fas fa-info-circle";
        }
        
        modal.classList.remove('hidden');
    }

    function closeMsgModal() {
        document.getElementById('msgModal').classList.add('hidden');
    }

    document.addEventListener('DOMContentLoaded', function() {
        const projectSelect = document.getElementById('project_id');
        const typeSelect = document.getElementById('type_id');
        const latInput = document.getElementById('latitude');
        const lonInput = document.getElementById('longitude');
        const getLocationBtn = document.getElementById('getLocationBtn');

        // 1. Auto Get Location on Load
        function getGeolocation(isManual = false) {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        latInput.value = position.coords.latitude.toFixed(8);
                        lonInput.value = position.coords.longitude.toFixed(8);
                        if (isManual) showMsg('Lokasi GPS berhasil diperbarui.', 'green');
                    },
                    (error) => {
                        console.error('GPS Error:', error);
                        if (isManual) {
                            showMsg('Gagal mendapatkan lokasi GPS: ' + error.message, 'red');
                        } else {
                            showMsg('Gagal mendeteksi lokasi otomatis. Silakan tekan tombol "Segarkan GPS".', 'blue');
                        }
                    },
                    { enableHighAccuracy: true, timeout: 8000 }
                );
            } else {
                showMsg('Browser Anda tidak mendukung deteksi lokasi (Geolocation).', 'red');
            }
        }

        // Trigger Geolocation on load
        getGeolocation(false);

        // Click handler to manually refresh geolocation
        getLocationBtn.addEventListener('click', () => getGeolocation(true));

        // 2. Load Active Projects
        async function loadProjects() {
            try {
                const response = await fetch('api_helper.php?action=get_active_projects');
                let data = null;
                try { data = await response.json(); } catch(e) {}
                
                if (!response.ok) {
                    throw new Error(data && data.error ? data.error : 'Network error (Status: ' + response.status + ')');
                }
                if (data && data.error) throw new Error(data.error);

                projectSelect.innerHTML = '<option value="">-- Pilih Proyek --</option>';
                if (Array.isArray(data) && data.length > 0) {
                    data.forEach(item => {
                        const opt = new Option(item.text, item.id);
                        projectSelect.add(opt);
                    });
                } else {
                    projectSelect.innerHTML = '<option value="">-- Tidak ada proyek aktif --</option>';
                }
            } catch (err) {
                console.error('Error loading projects:', err);
                showMsg('Gagal memuat daftar proyek. Detail: ' + err.message, 'red');
            }
        }

        loadProjects();

        // 3. Load Types on Project change
        projectSelect.addEventListener('change', async function() {
            const pId = this.value;
            typeSelect.innerHTML = '<option value="">-- Pilih Jenis --</option>';
            typeSelect.disabled = true;

            if (!pId) return;

            try {
                typeSelect.innerHTML = '<option value="">Memuat...</option>';
                const response = await fetch(`api_helper.php?action=get_active_types_by_project&project_id=${encodeURIComponent(pId)}`);
                let data = null;
                try { data = await response.json(); } catch(e) {}
                
                if (!response.ok) {
                    throw new Error(data && data.error ? data.error : 'Network error (Status: ' + response.status + ')');
                }
                if (data && data.error) throw new Error(data.error);

                typeSelect.innerHTML = '<option value="">-- Pilih Jenis Marpro --</option>';
                if (Array.isArray(data) && data.length > 0) {
                    data.forEach(item => {
                        const opt = new Option(item.text, item.id);
                        typeSelect.add(opt);
                    });
                    typeSelect.disabled = false;
                } else {
                    typeSelect.innerHTML = '<option value="">-- Tidak ada jenis terdaftar --</option>';
                }
            } catch (err) {
                console.error('Error loading types:', err);
                showMsg('Gagal memuat jenis Marpro. Detail: ' + err.message, 'red');
                typeSelect.innerHTML = '<option value="">Gagal memuat</option>';
            }
        });

        // 4. Form submit initialization
        const form = document.getElementById('marproReceiveForm');
    });
    </script>
</body>
</html>
