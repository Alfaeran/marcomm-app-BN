<?php
// user_marpro_allocate.php
require_once 'config/database.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}

$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

$user_id       = (int)$_SESSION['id'];
$user_branch_id = $_SESSION['branch_id'] ?? null;

if (empty($user_branch_id)) {
    $_SESSION['error_message'] = "Error: Akun Anda tidak terhubung ke Branch manapun.";
    header("location: dashboard_user.php");
    exit;
}

$app_name = get_setting($mysqli, 'app_name');

// Ambil data Branch
$branch_name = '';
$stmt_br = $mysqli->prepare("SELECT nama_branch FROM branches WHERE id = ?");
if ($stmt_br) {
    $stmt_br->bind_param("i", $user_branch_id);
    $stmt_br->execute();
    if ($row_br = $stmt_br->get_result()->fetch_assoc()) $branch_name = $row_br['nama_branch'];
    $stmt_br->close();
}

// Ambil MC yang ada di Branch ini
$micro_clusters = [];
$stmt_mc = $mysqli->prepare("SELECT id, nama_micro_cluster FROM micro_clusters WHERE branch_id = ? ORDER BY nama_micro_cluster");
if ($stmt_mc) {
    $stmt_mc->bind_param("i", $user_branch_id);
    $stmt_mc->execute();
    $micro_clusters = $stmt_mc->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_mc->close();
}

// Ambil riwayat alokasi oleh user ini
$allocations = [];
$sql_alloc = "SELECT ma.id, ma.tanggal_alokasi, mr.project_name, mr.type_name, mc.nama_micro_cluster, ma.qty_pcs, ma.photo_url, ma.latitude, ma.longitude
              FROM marpro_allocations ma
              JOIN marpro_receives mr ON ma.receive_id = mr.id
              JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
              WHERE ma.user_id = ?
              ORDER BY ma.tanggal_alokasi DESC, ma.id DESC LIMIT 25";
$stmt_al = $mysqli->prepare($sql_alloc);
if ($stmt_al) {
    $stmt_al->bind_param("i", $user_id);
    $stmt_al->execute();
    $allocations = $stmt_al->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_al->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Alokasi Marpro ke MC - <?php echo strip_tags($app_name); ?></title>
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
            left: 0; top: 50%;
            transform: translateY(-50%);
            width: 4px; height: 100%;
            background: #8b5cf6;
            border-radius: 2px;
        }
        #sisa_stok_badge { transition: all .3s; }
    </style>
</head>
<body class="min-h-screen">
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>

    <div class="flex">
        <?php include 'components/sidebar_user.php'; ?>

        <main class="flex-grow p-4 lg:p-10 lg:ml-64 min-w-0">
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-3 text-slate-600 glass-card"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Alokasi Marpro ke Micro Cluster</h2>
                        <p class="text-slate-500 font-medium">Distribusikan stok Marpro dari Branch <strong><?php echo htmlspecialchars($branch_name); ?></strong> ke MC.</p>
                    </div>
                </div>
                <a href="user_marpro_receive.php" class="inline-flex items-center gap-2 px-5 py-3 bg-blue-50 text-blue-700 font-bold text-sm rounded-2xl border border-blue-100 hover:bg-blue-600 hover:text-white transition-all shadow-sm">
                    <i class="fas fa-download"></i> Penerimaan Marpro
                </a>
            </header>

            <div class="max-w-5xl space-y-12">
                <?php if ($success_message): ?>
                    <div class="glass-card bg-emerald-50/50 border-emerald-200 p-4 flex items-center gap-3">
                        <div class="h-10 w-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle"></i></div>
                        <p class="text-emerald-800 font-bold"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                    <div class="glass-card bg-red-50/50 border-red-200 p-4 flex items-center gap-3">
                        <div class="h-10 w-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center"><i class="fas fa-exclamation-triangle"></i></div>
                        <p class="text-red-800 font-bold"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Form Alokasi -->
                <div class="glass-card p-8 border border-white/50 shadow-xl shadow-purple-900/5">
                    <form id="marproAllocateForm" action="process/user_marpro_allocate_process.php" method="post" enctype="multipart/form-data" class="space-y-8">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <!-- Kolom Kiri -->
                            <div class="space-y-6">
                                <h3 class="form-section-title text-lg uppercase tracking-widest text-slate-800">Detail Alokasi</h3>

                                <div class="space-y-2">
                                    <label for="receive_id" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Pilih Stok Penerimaan <span class="text-red-500">*</span></label>
                                    <select id="receive_id" name="receive_id" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-purple-100 outline-none transition-all appearance-none cursor-pointer">
                                        <option value="">-- Memuat stok tersedia... --</option>
                                    </select>
                                    <!-- Badge sisa stok -->
                                    <div id="sisa_stok_badge" class="hidden mt-2 space-y-1.5">
                                        <div class="px-4 py-2 rounded-xl bg-purple-50 border border-purple-100 text-xs font-black text-purple-700 flex items-center justify-between">
                                            <span class="flex items-center gap-2"><i class="fas fa-file-invoice"></i> Sisa Penerimaan</span>
                                            <span id="sisa_receive_val" class="text-base font-black">0</span> Pcs
                                        </div>
                                        <div id="stock_matpro_row" class="px-4 py-2 rounded-xl bg-emerald-50 border border-emerald-100 text-xs font-black text-emerald-700 flex items-center justify-between">
                                            <span class="flex items-center gap-2"><i class="fas fa-warehouse"></i> Stok Marpro (Aktual)</span>
                                            <span id="stock_matpro_val" class="text-base font-black">0</span> Pcs
                                        </div>
                                        <div id="max_qty_row" class="px-4 py-2.5 rounded-xl bg-slate-800 border border-slate-700 text-xs font-black text-white flex items-center justify-between">
                                            <span class="flex items-center gap-2"><i class="fas fa-lock"></i> Maks. Alokasi (berlaku)</span>
                                            <span id="sisa_stok_val" class="text-lg font-black text-yellow-300">0</span> Pcs
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label for="micro_cluster_id" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Micro Cluster Tujuan <span class="text-red-500">*</span></label>
                                    <select id="micro_cluster_id" name="micro_cluster_id" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-purple-100 outline-none transition-all appearance-none cursor-pointer">
                                        <option value="">-- Pilih Micro Cluster --</option>
                                        <?php foreach ($micro_clusters as $mc): ?>
                                            <option value="<?php echo $mc['id']; ?>"><?php echo htmlspecialchars($mc['nama_micro_cluster']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <label for="tanggal_alokasi" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Tanggal Alokasi <span class="text-red-500">*</span></label>
                                        <input type="date" name="tanggal_alokasi" id="tanggal_alokasi" value="<?php echo date('Y-m-d'); ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-4 focus:ring-purple-100 outline-none transition-all">
                                    </div>
                                    <div class="space-y-2">
                                        <label for="qty_pcs" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">QTY Alokasi (Pcs) <span class="text-red-500">*</span></label>
                                        <input type="number" name="qty_pcs" id="qty_pcs" value="1" min="1" max="99999" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-black focus:ring-4 focus:ring-purple-100 outline-none transition-all">
                                    </div>
                                </div>
                            </div>

                            <!-- Kolom Kanan -->
                            <div class="space-y-6">
                                <h3 class="form-section-title text-lg uppercase tracking-widest text-slate-800">Bukti Fisik & Lokasi</h3>

                                <div class="space-y-2">
                                    <label for="photo_bukti" class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Ambil Foto Bukti <span class="text-red-500">*</span></label>
                                    <input type="file" name="photo_bukti" id="photo_bukti" required accept="image/*"
                                           class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 transition-all cursor-pointer">
                                    <p class="text-[10px] text-slate-400 font-bold italic mt-1"><i class="fas fa-camera mr-1"></i> Mendukung pengambilan foto kamera langsung maupun unggah dari galeri.</p>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Latitude <span class="text-red-500">*</span></label>
                                        <input type="text" name="latitude" id="latitude" required readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-500 cursor-not-allowed" placeholder="Mendeteksi...">
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest px-1">Longitude <span class="text-red-500">*</span></label>
                                        <input type="text" name="longitude" id="longitude" required readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-500 cursor-not-allowed" placeholder="Mendeteksi...">
                                    </div>
                                </div>

                                <div class="flex justify-end pt-2">
                                    <button type="button" id="getLocationBtn" class="px-5 py-2.5 bg-purple-50 text-purple-600 font-bold text-xs rounded-xl hover:bg-purple-600 hover:text-white transition-all flex items-center gap-2 border border-purple-100 shadow-sm active:scale-95">
                                        <i class="fas fa-sync-alt"></i> Segarkan GPS
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-slate-100">
                            <button type="submit" id="submitBtn" class="w-full py-4 bg-violet-600 text-white font-extrabold text-base rounded-2xl hover:bg-violet-700 shadow-xl shadow-violet-200 active:scale-95 transition-all flex items-center justify-center gap-3">
                                <i class="fas fa-share-nodes"></i> Simpan Alokasi ke Micro Cluster
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Tabel Riwayat Alokasi -->
                <div class="glass-card p-8 border border-white/50 shadow-xl shadow-purple-900/5">
                    <h3 class="form-section-title text-lg uppercase tracking-widest text-slate-800">Riwayat Alokasi Anda</h3>
                    <div class="overflow-x-auto mt-4">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50/50 border-b border-slate-100">
                                    <th class="py-4 px-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tanggal</th>
                                    <th class="py-4 px-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Material / Proyek</th>
                                    <th class="py-4 px-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Micro Cluster</th>
                                    <th class="py-4 px-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">QTY</th>
                                    <th class="py-4 px-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Foto</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (!empty($allocations)): ?>
                                    <?php foreach ($allocations as $row): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors text-sm">
                                        <td class="py-4 px-5 font-bold text-slate-700"><?php echo date('d M Y', strtotime($row['tanggal_alokasi'])); ?></td>
                                        <td class="py-4 px-5">
                                            <p class="font-bold text-slate-800"><?php echo htmlspecialchars($row['type_name']); ?></p>
                                            <p class="text-xs text-slate-400 font-medium"><?php echo htmlspecialchars($row['project_name']); ?></p>
                                        </td>
                                        <td class="py-4 px-5">
                                            <span class="px-2 py-1 bg-purple-50 text-purple-700 border border-purple-100 rounded-lg text-xs font-black">
                                                <?php echo htmlspecialchars($row['nama_micro_cluster']); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-5 text-center font-black text-slate-800"><?php echo number_format($row['qty_pcs']); ?> Pcs</td>
                                        <td class="py-4 px-5 text-center">
                                            <a href="<?php echo htmlspecialchars($row['photo_url']); ?>" target="_blank" class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-purple-50 text-purple-600 border border-purple-100 hover:bg-purple-600 hover:text-white transition-all shadow-sm">
                                                <i class="fas fa-image"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="py-12 text-center text-slate-400 font-bold">Belum ada riwayat alokasi Marpro.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal Alert -->
    <div id="msgModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-[200] p-4 hidden">
        <div class="glass-card max-w-sm w-full p-8 text-center shadow-2xl">
            <div id="modalIconBg" class="h-16 w-16 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-6 bg-blue-50 text-blue-600">
                <i id="modalIcon" class="fas fa-info-circle"></i>
            </div>
            <h3 class="text-lg font-extrabold text-slate-800 mb-2">Pemberitahuan</h3>
            <p id="modalMsg" class="text-sm font-medium text-slate-600 mb-8 leading-relaxed"></p>
            <button class="w-full bg-violet-600 hover:bg-violet-700 text-white font-bold py-3 rounded-xl shadow-lg active:scale-95 transition-all" onclick="closeMsgModal()">Mengerti</button>
        </div>
    </div>

    <script>
    function showMsg(message, type = 'blue') {
        const modal = document.getElementById('msgModal');
        const modalIconBg = document.getElementById('modalIconBg');
        const modalIcon = document.getElementById('modalIcon');
        document.getElementById('modalMsg').textContent = message;

        modalIconBg.className = "h-16 w-16 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-6 ";
        if (type === 'green') { modalIconBg.classList.add('bg-emerald-50', 'text-emerald-600'); modalIcon.className = "fas fa-check-circle"; }
        else if (type === 'red') { modalIconBg.classList.add('bg-red-50', 'text-red-600'); modalIcon.className = "fas fa-exclamation-triangle"; }
        else { modalIconBg.classList.add('bg-blue-50', 'text-blue-600'); modalIcon.className = "fas fa-info-circle"; }

        modal.classList.remove('hidden');
    }
    function closeMsgModal() { document.getElementById('msgModal').classList.add('hidden'); }

    document.addEventListener('DOMContentLoaded', function () {
        const receiveSelect     = document.getElementById('receive_id');
        const qtyInput          = document.getElementById('qty_pcs');
        const sisaBadge         = document.getElementById('sisa_stok_badge');
        const sisaReceiveEl     = document.getElementById('sisa_receive_val');
        const stockMatproEl     = document.getElementById('stock_matpro_val');
        const stockMatproRow    = document.getElementById('stock_matpro_row');
        const maxQtyEl          = document.getElementById('sisa_stok_val');
        const latInput          = document.getElementById('latitude');
        const lonInput          = document.getElementById('longitude');
        const getLocBtn         = document.getElementById('getLocationBtn');
        const form              = document.getElementById('marproAllocateForm');

        let maxQty = 0;

        // 1) Auto GPS on load
        function getGeolocation(isManual = false) {
            if (!navigator.geolocation) {
                showMsg('Browser Anda tidak mendukung GPS.', 'red');
                return;
            }
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    latInput.value = pos.coords.latitude.toFixed(8);
                    lonInput.value = pos.coords.longitude.toFixed(8);
                    if (isManual) showMsg('Lokasi GPS berhasil diperbarui.', 'green');
                },
                (err) => {
                    if (isManual) showMsg('Gagal mendapatkan GPS: ' + err.message, 'red');
                    else showMsg('Lokasi GPS belum terdeteksi. Tekan "Segarkan GPS" setelah mengaktifkan lokasi.', 'blue');
                },
                { enableHighAccuracy: true, timeout: 8000 }
            );
        }
        getGeolocation(false);
        getLocBtn.addEventListener('click', () => getGeolocation(true));

        // 2) Load Receivable Marpros
        async function loadReceivables() {
            try {
                receiveSelect.innerHTML = '<option value="">Memuat...</option>';
                const res = await fetch('api_helper.php?action=get_receivable_marpros');
                let data = null;
                try { data = await res.json(); } catch(e) {}
                
                if (!res.ok) {
                    throw new Error(data && data.error ? data.error : 'Network error (Status: ' + res.status + ')');
                }
                if (data && data.error) throw new Error(data.error);

                receiveSelect.innerHTML = '<option value="">-- Pilih Stok Penerimaan --</option>';
                if (Array.isArray(data) && data.length > 0) {
                    data.forEach(item => receiveSelect.add(new Option(item.text, item.id)));
                } else {
                    receiveSelect.innerHTML = '<option value="">-- Tidak ada stok tersedia untuk dialokasikan --</option>';
                }
            } catch (err) {
                receiveSelect.innerHTML = '<option value="">Gagal memuat</option>';
                showMsg('Gagal memuat daftar stok: ' + err.message, 'red');
            }
        }
        loadReceivables();

        // 3) On receive_id change: fetch real stock limit from server
        receiveSelect.addEventListener('change', async function () {
            const rId = this.value;
            sisaBadge.classList.add('hidden');
            maxQty = 0;
            qtyInput.max = 99999;
            qtyInput.value = 1;

            if (!rId) return;

            try {
                // Fetch stock details from both marpro_receives & matpro_stocks
                const res = await fetch(`api_helper.php?action=get_branch_stock_by_receive&receive_id=${rId}`);
                let data = null;
                try { data = await res.json(); } catch(e) {}
                
                if (!res.ok) {
                    throw new Error(data && data.error ? data.error : 'Network error (Status: ' + res.status + ')');
                }
                if (data && data.error) throw new Error(data.error);

                const sisaReceive  = parseInt(data.sisa_receive)  || 0;
                const stockMatpro  = parseInt(data.stock_matpro)  || 0;
                maxQty             = parseInt(data.max_qty)        || 0;

                // Update badge rows
                sisaReceiveEl.textContent  = sisaReceive.toLocaleString();
                stockMatproEl.textContent  = stockMatpro.toLocaleString();
                maxQtyEl.textContent       = maxQty.toLocaleString();

                // Highlight stok matpro row: merah jika stok matpro lebih rendah (jadi pembatas nyata)
                if (stockMatpro < sisaReceive) {
                    stockMatproRow.className = 'px-4 py-2 rounded-xl bg-red-50 border border-red-200 text-xs font-black text-red-700 flex items-center justify-between';
                } else {
                    stockMatproRow.className = 'px-4 py-2 rounded-xl bg-emerald-50 border border-emerald-100 text-xs font-black text-emerald-700 flex items-center justify-between';
                }

                // Apply max constraint to input
                qtyInput.max = maxQty > 0 ? maxQty : 99999;
                if (parseInt(qtyInput.value) > maxQty) qtyInput.value = maxQty;

                sisaBadge.classList.remove('hidden');

                if (maxQty <= 0) {
                    showMsg('Stok Marpro untuk material ini sudah habis di Branch Anda. Silakan terima stok terlebih dahulu.', 'red');
                }
            } catch (err) {
                console.error(err);
                showMsg('Gagal mengambil info stok: ' + err.message, 'red');
            }
        });

        // 4) Validate qty on submit
        form.addEventListener('submit', function (e) {
            const qty = parseInt(qtyInput.value) || 0;
            if (qty <= 0) {
                e.preventDefault();
                showMsg('QTY alokasi harus lebih dari 0.', 'red');
                return;
            }
            if (maxQty > 0 && qty > maxQty) {
                e.preventDefault();
                showMsg(`QTY alokasi (${qty} Pcs) melebihi batas maksimum (${maxQty} Pcs). Silakan kurangi jumlah.`, 'red');
                return;
            }
            if (!latInput.value || !lonInput.value) {
                e.preventDefault();
                showMsg('Lokasi GPS belum terdeteksi. Aktifkan GPS dan tekan "Segarkan GPS".', 'red');
                return;
            }
        });
    });
    </script>
</body>
</html>
