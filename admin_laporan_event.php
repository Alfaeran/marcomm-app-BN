<?php
// File: admin_laporan_event.php (Versi Final dengan Proses Terintegrasi)

// Pastikan session dimulai jika belum
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}

// --- BAGIAN BARU: LOGIKA PROSES AKSI (YANG SEBELUMNYA DI FILE TERPISAH) ---
if (($_SERVER["REQUEST_METHOD"] == "POST" || isset($_GET['action']))) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if (!empty($action)) {
        try {
            $mysqli->begin_transaction();
            $admin_id = (int)$_SESSION['id'];

            switch ($action) {
                case 'review_request': // This case handles both delete and edit requests from users
                    $req_id = (int)($_GET['req_id'] ?? ($_POST['req_id'] ?? 0)); // Get from GET or POST
                    $decision = $_GET['decision'] ?? ($_POST['decision'] ?? ''); // Get from GET or POST
                    $rejection_reason = $_GET['rejection_reason'] ?? ($_POST['rejection_reason'] ?? null);

                    if (empty($req_id) || !in_array($decision, ['approve', 'reject'])) {
                        throw new Exception("Data review tidak valid.");
                    }

                    $req_result = $mysqli->query("SELECT * FROM event_requests WHERE id = {$req_id} AND status = 'pending'");
                    if (!$req_result || $req_result->num_rows === 0) {
                        throw new Exception("Permintaan tidak ditemukan atau sudah diproses.");
                    }
                    $request = $req_result->fetch_assoc();
                    $event_id_to_process = (int)$request['event_id'];

                    if ($decision === 'approve') {
                        $target_status = 'completed';
                        if ($request['request_type'] === 'delete') {
                            if (!$mysqli->query("DELETE FROM event_submissions WHERE unique_id = {$event_id_to_process}")) {
                                throw new Exception("Gagal menghapus event yang diminta: " . $mysqli->error);
                            }
                            $_SESSION['success_message'] = "Permintaan hapus disetujui dan event telah dihapus.";
                        } else if ($request['request_type'] === 'edit') {
                            $target_status = 'approved';
                            $_SESSION['success_message'] = "Permintaan edit disetujui. User yang bersangkutan sekarang dapat mengedit event tersebut.";
                        }
                        $mysqli->query("UPDATE event_requests SET status = '$target_status', reviewed_by = {$admin_id}, reviewed_at = NOW() WHERE id = {$req_id}");
                    } elseif ($decision === 'reject') {
                        $safe_reason = $mysqli->real_escape_string($rejection_reason ?? '');
                        $mysqli->query("UPDATE event_requests SET status = 'rejected', reviewed_by = {$admin_id}, reviewed_at = NOW(), rejection_reason = '{$safe_reason}' WHERE id = {$req_id}");
                        $_SESSION['success_message'] = "Permintaan telah ditolak.";
                    }
                    break;

                case 'approve_all_requests':
                    $pending_requests_result = $mysqli->query("SELECT id, event_id, request_type FROM event_requests WHERE status = 'pending'");
                    if (!$pending_requests_result || $pending_requests_result->num_rows === 0) {
                        throw new Exception("Tidak ada permintaan untuk disetujui.");
                    }

                    $event_ids_to_delete = [];
                    while ($req = $pending_requests_result->fetch_assoc()) {
                        if ($req['request_type'] === 'delete') {
                            $event_ids_to_delete[] = (int)$req['event_id'];
                        }
                    }
                    
                    if (!empty($event_ids_to_delete)) {
                        $id_list_to_delete = implode(',', $event_ids_to_delete);
                        if (!$mysqli->query("DELETE FROM event_submissions WHERE unique_id IN ($id_list_to_delete)")) {
                            throw new Exception("Gagal menghapus event yang diminta: " . $mysqli->error);
                        }
                    }
                    
                    $mysqli->query("UPDATE event_requests SET status = 'completed', reviewed_by = {$admin_id}, reviewed_at = NOW() WHERE status = 'pending' AND request_type = 'delete'");
                    $mysqli->query("UPDATE event_requests SET status = 'approved', reviewed_by = {$admin_id}, reviewed_at = NOW() WHERE status = 'pending' AND request_type = 'edit'");
                    
                    $_SESSION['success_message'] = "Berhasil memproses seluruh permintaan aktif.";
                    break;

                case 'reject_all_requests':
                    // START PERBAIKAN: Ambil alasan penolakan massal
                    $rejection_reason_bulk = $_POST['rejection_reason_bulk'] ?? ''; // Dari form POST
                    $safe_reason_bulk = $mysqli->real_escape_string($rejection_reason_bulk);
                    // END PERBAIKAN
                    $update_sql = "UPDATE event_requests SET status = 'rejected', reviewed_by = {$admin_id}, reviewed_at = NOW(), rejection_reason = '{$safe_reason_bulk}' WHERE status = 'pending'";
                    if (!$mysqli->query($update_sql)) {
                        throw new Exception("Gagal menolak semua permintaan: " . $mysqli->error);
                    }
                    $_SESSION['success_message'] = "Berhasil menolak " . $mysqli->affected_rows . " permintaan.";
                    break;

                case 'delete_event': // New action for single delete
                    $id = (int)($_POST['unique_id'] ?? 0);
                    if (empty($id)) throw new Exception("ID Event tidak valid.");
                    if (!$mysqli->query("DELETE FROM event_submissions WHERE unique_id = $id")) {
                        throw new Exception("Gagal menghapus event: " . $mysqli->error);
                    }
                    $_SESSION['success_message'] = "Event berhasil dihapus.";
                    break;

                case 'bulk_delete_events': // New action for bulk delete
                    $event_ids = $_POST['event_ids'] ?? [];
                    if (empty($event_ids)) throw new Exception("Tidak ada event yang dipilih untuk dihapus.");
                    $safe_ids = array_map('intval', $event_ids);
                    $id_list = implode(',', $safe_ids);
                    if (!$mysqli->query("DELETE FROM event_submissions WHERE unique_id IN ($id_list)")) {
                        throw new Exception("Gagal menghapus event secara massal: " . $mysqli->error);
                    }
                    $_SESSION['success_message'] = $mysqli->affected_rows . " event berhasil dihapus.";
                    break;

                case 'edit_date_event': // Admin edit single event date
                    $edit_id   = (int)($_POST['unique_id'] ?? 0);
                    $new_date  = $mysqli->real_escape_string($_POST['new_date'] ?? '');
                    $new_time  = $mysqli->real_escape_string($_POST['new_time'] ?? '00:00:00');
                    if (empty($edit_id) || empty($new_date)) throw new Exception("ID atau tanggal tidak valid.");
                    $new_datetime = $new_date . ' ' . $new_time;
                    if (!$mysqli->query("UPDATE event_submissions SET waktu_input = '$new_datetime' WHERE unique_id = $edit_id")) {
                        throw new Exception("Gagal mengubah tanggal: " . $mysqli->error);
                    }
                    $_SESSION['success_message'] = "Tanggal event berhasil diubah.";
                    break;

                case 'bulk_edit_date_events': // Admin bulk edit event dates
                    $event_ids_edit = $_POST['event_ids'] ?? [];
                    $new_date_bulk  = $mysqli->real_escape_string($_POST['new_date'] ?? '');
                    $new_time_bulk  = $mysqli->real_escape_string($_POST['new_time'] ?? '00:00:00');
                    if (empty($event_ids_edit)) throw new Exception("Tidak ada event yang dipilih.");
                    if (empty($new_date_bulk)) throw new Exception("Tanggal baru tidak boleh kosong.");
                    $safe_ids_edit   = array_map('intval', $event_ids_edit);
                    $id_list_edit    = implode(',', $safe_ids_edit);
                    $new_datetime_bulk = $new_date_bulk . ' ' . $new_time_bulk;
                    if (!$mysqli->query("UPDATE event_submissions SET waktu_input = '$new_datetime_bulk' WHERE unique_id IN ($id_list_edit)")) {
                        throw new Exception("Gagal mengubah tanggal secara massal: " . $mysqli->error);
                    }
                    $_SESSION['success_message'] = $mysqli->affected_rows . " event berhasil diubah tanggalnya.";
                    break;
            }

            $mysqli->commit();
            header("Location: " . $_SERVER['PHP_SELF']); // Redirect ke halaman ini sendiri untuk refresh
            exit;

        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}


// --- Logika untuk Menampilkan Halaman (GET Request) ---
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

$records_per_page = isset($_GET['limit']) && in_array($_GET['limit'], [10, 25, 50, 100, 150, 200]) ? (int)$_GET['limit'] : 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$where_clauses = [];
$filter_query_string = "";
$filters = [
    'keyword' => $_GET['keyword'] ?? '', 
    'start_date' => $_GET['start_date'] ?? date('Y-m-01'),
    'end_date' => $_GET['end_date'] ?? date('Y-m-t'), 
    'brand' => $_GET['brand'] ?? '',
    'branch_id' => $_GET['branch_id'] ?? '', 'mc_id' => $_GET['mc_id'] ?? '',
    'area' => $_GET['area'] ?? ''
];
$sort_by = $_GET['sort_by'] ?? 'waktu_desc';

foreach ($filters as $key => $value) {
    if (!empty($value)) { $filter_query_string .= "&$key=" . urlencode($value); }
}
$filter_query_string .= "&sort_by=" . urlencode($sort_by);
$filter_query_string .= "&limit=" . $records_per_page;

if (!empty($filters['keyword'])) { $safe_keyword = "'%" . $mysqli->real_escape_string($filters['keyword']) . "%'"; $where_clauses[] = "(e.event_name LIKE $safe_keyword OR u.username LIKE $safe_keyword OR s.site_name LIKE $safe_keyword)"; }
if (!empty($filters['start_date'])) { $safe_date = "'" . $mysqli->real_escape_string($filters['start_date'] . " 00:00:00") . "'"; $where_clauses[] = "e.waktu_input >= $safe_date"; }
if (!empty($filters['end_date'])) { $safe_date = "'" . $mysqli->real_escape_string($filters['end_date'] . " 23:59:59") . "'"; $where_clauses[] = "e.waktu_input <= $safe_date"; }
if (!empty($filters['brand'])) { $safe_brand = "'" . $mysqli->real_escape_string($filters['brand']) . "'"; $where_clauses[] = "u.brand = $safe_brand"; }
if (!empty($filters['branch_id'])) { $safe_branch = (int)$filters['branch_id']; $where_clauses[] = "s.branch_id = $safe_branch"; }
if (!empty($filters['mc_id'])) { $safe_mc = (int)$filters['mc_id']; $where_clauses[] = "s.micro_cluster_id = $safe_mc"; }
if (!empty($filters['area'])) { $safe_area = "'%" . $mysqli->real_escape_string($filters['area']) . "%'"; $where_clauses[] = "s.area LIKE $safe_area"; }
$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

$allowed_sorts = ['waktu_desc' => 'e.waktu_input DESC', 'waktu_asc' => 'e.waktu_input ASC', 'benefit_desc' => 'e.benefit_total DESC', 'benefit_asc' => 'e.benefit_total ASC'];
$order_by_sql = $allowed_sorts[$sort_by] ?? 'e.waktu_input DESC';

$count_sql = "SELECT COUNT(e.unique_id) as total FROM event_submissions e JOIN users u ON e.user_id = u.id LEFT JOIN sites s ON e.site_id = s.id $where_sql";
$total_records = $mysqli->query($count_sql)->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

$sql = "SELECT e.unique_id, e.event_name, e.waktu_input, e.benefit_total, u.username AS user_input, s.site_name, s.site_id as site_code, s.area, b.nama_branch, mc.nama_micro_cluster
        FROM event_submissions e
        JOIN users u ON e.user_id = u.id
        LEFT JOIN sites s ON e.site_id = s.id
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id
        $where_sql ORDER BY $order_by_sql LIMIT $records_per_page OFFSET $offset";
$events_result = $mysqli->query($sql);

// START PERBAIKAN: Ambil juga requested_columns dari event_requests dan rejection_reason
$pending_requests_sql = "SELECT r.*, u.username, e.event_name, r.requested_columns, r.rejection_reason 
                         FROM event_requests r 
                         JOIN users u ON r.user_id = u.id 
                         JOIN event_submissions e ON r.event_id = e.unique_id 
                         WHERE r.status = 'pending' ORDER BY r.requested_at ASC";
$pending_requests_result = $mysqli->query($pending_requests_sql);
$pending_requests_count = $pending_requests_result ? $pending_requests_result->num_rows : 0;

// Definisikan array $editable_columns (sama seperti di dashboard_user.php)
$editable_columns = [
    'sp_0k' => 'SP 0K', 'sp_3gb' => 'SP 3GB', 'sp_5gb' => 'SP 5GB', 'sp_7gb' => 'SP 7GB', 'sp_100gb' => 'SP 100GB',
    'fwa' => 'FWA', 'sp_existing' => 'SP Existing', 'hit_haji_umroh' => 'HIT Haji/Umroh',
    'jumlah_audience' => 'Jumlah Audience', 'reload' => 'Reload', 'mobo_paket' => 'Mobo/Paket', 'cost' => 'Cost',
    'alasan' => 'Alasan / Feedback', 'provider_digunakan' => 'Provider Digunakan',
    'provider_terbaik' => 'Provider Sinyal Terbaik', 'kenal_im3' => 'Mengenal IM3?',
    'sudah_beli_im3' => 'Sudah Beli IM3?', 'lokasi_beli' => 'Lokasi Beli', 'tertarik_beli_im3' => 'Tertarik Beli IM3?',
    'foto_event_url' => 'Foto Event', 'msisdn_file' => 'File MSISDN'
];
// END PERBAIKAN

$monthly_stats_result = $mysqli->query("SELECT YEAR(waktu_input) as tahun, MONTH(waktu_input) as bulan, COUNT(unique_id) as total_event, SUM(benefit_total) as total_benefit FROM event_submissions GROUP BY YEAR(waktu_input), MONTH(waktu_input) ORDER BY tahun DESC, bulan DESC");
$brands_for_filter = $mysqli->query("SELECT DISTINCT brand FROM branches WHERE brand IS NOT NULL ORDER BY brand");
// Ambil pengaturan aplikasi
$app_name = get_setting($mysqli, 'app_name') ?: 'MarComm App';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Laporan Event - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
    <script src="assets/js/progress.js"></script>
    <script src="assets/js/search-sort.js?v=<?= time() ?>"></script>
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Laporan Event</h2>
                        <p class="text-slate-500 font-medium text-sm">Rekapitulasi dan pengelolaan seluruh aktivitas event lapangan</p>
                    </div>
                </div>
            </header>

            <!-- Success/Error Messages -->
            <?php if (!empty($success_message)): ?>
            <div class="glass-card border-l-4 border-emerald-500 text-emerald-700 p-6 mb-8 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                <div class="h-10 w-10 rounded-full bg-emerald-100 flex items-center justify-center shrink-0">
                    <i class="fas fa-check"></i>
                </div>
                <p class="font-bold"><?php echo $success_message; ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="glass-card border-l-4 border-red-500 text-red-700 p-6 mb-8 rounded-2xl flex items-center gap-4 animate-in fade-in slide-in-from-top-4 duration-300" role="alert">
                <div class="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <p class="font-bold"><?php echo $error_message; ?></p>
            </div>
            <?php endif; ?>

            <!-- Pending Requests Section -->
            <div class="glass-card rounded-3xl p-8 mb-10 border border-white/50 bg-amber-50/30">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                    <div>
                        <h2 class="text-xl font-extrabold text-slate-800 tracking-tight flex items-center gap-3">
                            <i class="fas fa-bell text-amber-500 <?php if ($pending_requests_count > 0) echo 'animate-bounce'; ?>"></i>
                            Permintaan Perubahan
                            <?php if ($pending_requests_count > 0): ?>
                                <span class="bg-red-500 text-white text-[10px] font-black rounded-full h-5 w-5 flex items-center justify-center"><?php echo $pending_requests_count; ?></span>
                            <?php endif; ?>
                        </h2>
                        <p class="text-xs text-slate-500 font-medium">Persetujuan atau penolakan permintaan edit/hapus dari tim lapangan.</p>
                    </div>
                    <?php if ($pending_requests_count > 0): ?>
                    <div class="flex items-center gap-2">
                        <form action="" method="POST" onsubmit="return confirm('Setujui semua <?php echo $pending_requests_count; ?> permintaan?');">
                            <input type="hidden" name="action" value="approve_all_requests">
                            <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-xs font-bold rounded-xl hover:bg-emerald-700 transition-all flex items-center gap-2 shadow-lg shadow-emerald-200">
                                <i class="fas fa-check-double"></i> Setujui Semua
                            </button>
                        </form>
                        <form id="rejectAllForm" action="" method="POST">
                            <input type="hidden" name="action" value="reject_all_requests">
                            <input type="hidden" name="rejection_reason_bulk" id="rejection_reason_bulk_input">
                            <button type="button" onclick="rejectAllRequests()" class="px-4 py-2 bg-slate-800 text-white text-xs font-bold rounded-xl hover:bg-slate-900 transition-all flex items-center gap-2 shadow-lg shadow-slate-200">
                                <i class="fas fa-times-circle"></i> Tolak Semua
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($pending_requests_count > 0): ?>
                <div class="overflow-x-auto rounded-2xl border border-slate-100 bg-white/50">
                    <table class="min-w-full text-left">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">User & Event</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tipe</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Alasan</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Kolom Diedit</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php mysqli_data_seek($pending_requests_result, 0); ?>
                        <?php while($req = $pending_requests_result->fetch_assoc()): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="py-4 px-6">
                                    <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($req['username']); ?></p>
                                    <p class="text-xs text-slate-400 font-medium truncate max-w-[200px]" title="<?php echo htmlspecialchars($req['event_name']); ?>"><?php echo htmlspecialchars($req['event_name']); ?></p>
                                    <p class="text-[10px] text-slate-400 font-medium mt-1"><i class="far fa-clock mr-1"></i><?php echo date('d M Y H:i', strtotime($req['requested_at'])); ?></p>
                                </td>
                                <td class="py-4 px-6">
                                    <?php if ($req['request_type'] == 'edit'): ?>
                                        <span class="px-2 py-1 bg-amber-50 text-amber-600 rounded-lg text-[10px] font-black uppercase tracking-widest border border-amber-100 italic">EDIT</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 bg-red-50 text-red-600 rounded-lg text-[10px] font-black uppercase tracking-widest border border-red-100 italic font-bold">DELETE</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-6 text-xs font-medium text-slate-600 max-w-[200px]"><?php echo htmlspecialchars($req['reason']); ?></td>
                                <td class="py-4 px-6 text-xs text-slate-500 font-medium italic">
                                    <?php
                                    $requested_cols_array = json_decode($req['requested_columns'], true);
                                    if (is_array($requested_cols_array) && !empty($requested_cols_array)) {
                                        $display_cols = [];
                                        foreach ($requested_cols_array as $col_name) {
                                            $display_cols[] = $editable_columns[$col_name] ?? $col_name;
                                        }
                                        echo implode(', ', $display_cols);
                                    } else { echo '-'; }
                                    ?>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <form action="" method="POST" class="inline-block" onsubmit="return confirm('Setujui permintaan ini?');">
                                            <input type="hidden" name="action" value="review_request">
                                            <input type="hidden" name="req_id" value="<?php echo $req['id']; ?>">
                                            <input type="hidden" name="decision" value="approve">
                                            <button type="submit" class="h-8 w-8 flex items-center justify-center bg-emerald-50 text-emerald-600 rounded-lg hover:bg-emerald-600 hover:text-white transition-all shadow-sm" title="Setujui">
                                                <i class="fas fa-check text-xs"></i>
                                            </button>
                                        </form>
                                        <button type="button" onclick="rejectSingleRequest(<?php echo $req['id']; ?>)" class="h-8 w-8 flex items-center justify-center bg-red-50 text-red-600 rounded-lg hover:bg-red-600 hover:text-white transition-all shadow-sm" title="Tolak">
                                            <i class="fas fa-times text-xs"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="py-12 text-center text-slate-400 font-medium border-2 border-dashed border-slate-100 rounded-3xl">
                    <i class="fas fa-check-circle text-4xl mb-4 text-emerald-100"></i>
                    <p>Semua permintaan telah diproses atau belum ada permintaan baru.</p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Monthly Summary Section -->
            <div class="glass-card rounded-3xl p-8 mb-10 border border-white/50 overflow-hidden">
                <div class="mb-6 px-1">
                    <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Ringkasan Per Bulan</h2>
                    <p class="text-xs text-slate-500 font-medium">Akumulasi performa event bulanan.</p>
                </div>
                <div class="max-h-64 overflow-y-auto rounded-2xl border border-slate-100 bg-white/50">
                    <table class="min-w-full text-left">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Bulan & Tahun</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Total Event</th>
                                <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Total Benefit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php if($monthly_stats_result && $monthly_stats_result->num_rows > 0): ?>
                                <?php while($stat = $monthly_stats_result->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="py-4 px-6 text-sm font-bold text-slate-600"><?php echo date("F Y", mktime(0, 0, 0, $stat['bulan'], 1, $stat['tahun'])); ?></td>
                                    <td class="py-4 px-6 text-center text-sm font-bold text-slate-800"><?php echo number_format($stat['total_event']); ?></td>
                                    <td class="py-4 px-6 text-right text-sm font-black text-emerald-600">Rp <?php echo number_format($stat['total_benefit'], 0, ',', '.'); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center py-10 text-slate-400 font-medium">Belum ada data historis.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Filter Panel -->
            <div class="glass-card rounded-3xl p-8 mb-10 border border-white/50">
                <div class="flex items-center gap-3 mb-8">
                    <div class="h-10 w-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-sm">
                        <i class="fas fa-filter text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-800 tracking-tight">Filter Laporan</h3>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Saring Data Secara Spesifik</p>
                    </div>
                </div>

                <form action="" method="GET" class="space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="space-y-2">
                            <label for="keyword" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Pencarian Cepat</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" name="keyword" id="keyword" value="<?php echo htmlspecialchars($filters['keyword']); ?>" 
                                       placeholder="Event, User, Site..."
                                       class="w-full pl-10 pr-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label for="brand_filter" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Filter Brand</label>
                            <select name="brand" id="brand_filter" class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all appearance-none cursor-pointer">
                                <option value="">Semua Brand</option>
                                <?php if($brands_for_filter) { mysqli_data_seek($brands_for_filter, 0); while($brand = $brands_for_filter->fetch_assoc()): ?>
                                <option value="<?php echo $brand['brand']; ?>" <?php if ($filters['brand'] == $brand['brand']) echo 'selected'; ?>><?php echo $brand['brand']; ?></option>
                                <?php endwhile; } ?>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="branch_id" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Filter Branch</label>
                            <select name="branch_id" id="branch_id" class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all appearance-none cursor-pointer disabled:opacity-50" <?php if(empty($filters['brand'])) echo 'disabled'; ?>>
                                <option value="">Pilih Brand</option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="mc_id" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Filter Cluster</label>
                            <select name="mc_id" id="mc_id" class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all appearance-none cursor-pointer disabled:opacity-50" <?php if(empty($filters['branch_id'])) echo 'disabled'; ?>>
                                <option value="">Pilih Branch</option>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label for="area" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Filter Area</label>
                            <input type="text" name="area" id="area" value="<?php echo htmlspecialchars($filters['area']); ?>" 
                                   placeholder="Contoh: Denpasar"
                                   class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>

                        <div class="space-y-2">
                            <label for="start_date" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Dari Tanggal</label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($filters['start_date']); ?>" 
                                   class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>

                        <div class="space-y-2">
                            <label for="end_date" class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Sampai Tanggal</label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($filters['end_date']); ?>" 
                                   class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all">
                        </div>
                    </div>

                    <div class="flex flex-col md:flex-row justify-end items-center gap-6 pt-6 border-t border-slate-100">
                        <div class="flex gap-2">
                            <a href="admin_laporan_event.php" class="px-6 py-2.5 bg-slate-100 text-slate-600 font-bold text-xs rounded-xl hover:bg-slate-200 transition-all uppercase tracking-widest">Reset</a>
                            <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white font-bold text-xs rounded-xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all uppercase tracking-widest">Terapkan</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Main Data Table Section -->
            <div class="glass-card rounded-3xl border border-white/50 overflow-visible shadow-xl shadow-blue-900/5">
                <div class="p-8 border-b border-slate-100 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                    <div>
                        <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Daftar Aktivitas Event</h2>
                        <div class="flex items-center gap-3 mt-2">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Tampilkan:</span>
                            <select id="limit" onchange="window.location.href = 'admin_laporan_event.php?page=1<?php echo str_replace(['&limit='.$records_per_page, '&page='.$page], '', $filter_query_string); ?>&limit=' + this.value" class="bg-slate-50 border-none rounded-lg text-[10px] font-black text-blue-600 focus:ring-0 cursor-pointer">
                                <option value="10" <?php if($records_per_page == 10) echo 'selected'; ?>>10 baris</option>
                                <option value="25" <?php if($records_per_page == 25) echo 'selected'; ?>>25 baris</option>
                                <option value="50" <?php if($records_per_page == 50) echo 'selected'; ?>>50 baris</option>
                                <option value="100" <?php if($records_per_page == 100) echo 'selected'; ?>>100 baris</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Dropdown Excel -->
                        <div class="relative inline-block text-left" id="excelDropdownContainer">
                            <button type="button" id="excelDropdownBtn" class="px-3 py-2 bg-emerald-600 text-white font-bold text-[10px] rounded-lg hover:bg-emerald-700 shadow-md transition-all flex items-center gap-2">
                                <i class="fas fa-file-excel text-xs"></i> Excel <i class="fas fa-chevron-down text-[8px]"></i>
                            </button>
                            <div id="excelDropdownMenu" class="hidden absolute right-0 mt-2 w-48 glass-card rounded-xl shadow-xl border border-white/50 z-50 overflow-hidden divide-y divide-slate-100">
                                <button type="button" onclick="submitEventExport('excel', 'filtered')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600 transition-colors flex items-center gap-2">
                                    <i class="fas fa-filter text-emerald-500"></i> Terfilter
                                </button>
                                <button type="button" onclick="submitEventExport('excel', 'mtd')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600 transition-colors flex items-center gap-2">
                                    <i class="fas fa-calendar-alt text-emerald-500"></i> MTD (Bulan Ini)
                                </button>
                                <button type="button" onclick="submitEventExport('excel', 'all')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600 transition-colors flex items-center gap-2">
                                    <i class="fas fa-database text-emerald-500"></i> Semua Data
                                </button>
                            </div>
                        </div>

                        <!-- Dropdown CSV -->
                        <div class="relative inline-block text-left" id="csvDropdownContainer">
                            <button type="button" id="csvDropdownBtn" class="px-3 py-2 bg-slate-800 text-white font-bold text-[10px] rounded-lg hover:bg-slate-900 shadow-md transition-all flex items-center gap-2">
                                <i class="fas fa-file-csv text-xs"></i> CSV <i class="fas fa-chevron-down text-[8px]"></i>
                            </button>
                            <div id="csvDropdownMenu" class="hidden absolute right-0 mt-2 w-48 glass-card rounded-xl shadow-xl border border-white/50 z-50 overflow-hidden divide-y divide-slate-100">
                                <button type="button" onclick="submitEventExport('csv', 'filtered')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-slate-50 hover:text-slate-900 transition-colors flex items-center gap-2">
                                    <i class="fas fa-filter text-slate-500"></i> Terfilter
                                </button>
                                <button type="button" onclick="submitEventExport('csv', 'mtd')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-slate-50 hover:text-slate-900 transition-colors flex items-center gap-2">
                                    <i class="fas fa-calendar-alt text-slate-500"></i> MTD (Bulan Ini)
                                </button>
                                <button type="button" onclick="submitEventExport('csv', 'all')" class="w-full text-left px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-slate-50 hover:text-slate-900 transition-colors flex items-center gap-2">
                                    <i class="fas fa-database text-slate-500"></i> Semua Data
                                </button>
                            </div>
                        </div>

                        <form action="" method="GET" class="flex items-center gap-3 px-4 py-2 bg-slate-50 rounded-2xl border border-slate-100">
                            <?php foreach($filters as $key => $value): if ($key != 'sort_by'): ?>
                                <input type="hidden" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($value); ?>">
                            <?php endif; endforeach; ?>
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest shrink-0">Urutkan:</span>
                            <select name="sort_by" id="sort_by" onchange="this.form.submit()" class="bg-transparent border-none text-[10px] font-black text-indigo-600 focus:ring-0 cursor-pointer">
                                <option value="waktu_desc"  <?php if($sort_by=='waktu_desc')  echo 'selected'; ?>>Terbaru</option>
                                <option value="waktu_asc"   <?php if($sort_by=='waktu_asc')   echo 'selected'; ?>>Terlama</option>
                                <option value="benefit_desc"<?php if($sort_by=='benefit_desc') echo 'selected'; ?>>Revenue Tertinggi</option>
                                <option value="benefit_asc" <?php if($sort_by=='benefit_asc')  echo 'selected'; ?>>Revenue Terendah</option>
                            </select>
                        </form>
                    </div>
                </div>

                <form action="" method="POST" id="bulk-delete-form">
                    <input type="hidden" name="action" value="bulk_delete_events">
                     <div class="overflow-x-auto">
                        <table class="min-w-full text-left" id="adminEventTable">
                            <thead class="bg-slate-50/50">
                                <tr>
                                    <th class="py-4 px-6 text-center"><input type="checkbox" id="select-all" class="rounded border-slate-300 text-blue-600 focus:ring-blue-100"></th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Event & Lokasi</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Waktu Input</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">User / Agent</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Branch / MC</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Revenue</th>
                                    <th class="py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if ($events_result && $events_result->num_rows > 0): ?>
                                    <?php while($event = $events_result->fetch_assoc()): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors group">
                                        <td class="py-4 px-6 text-center">
                                            <input type="checkbox" name="event_ids[]" value="<?php echo $event['unique_id']; ?>" class="event-checkbox rounded border-slate-300 text-blue-600 focus:ring-blue-100">
                                        </td>
                                        <td class="py-4 px-6">
                                            <p class="text-sm font-bold text-slate-800 group-hover:text-blue-600 transition-colors"><?php echo htmlspecialchars($event['event_name']); ?></p>
                                            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5"><?php echo htmlspecialchars($event['site_name'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($event['site_code'] ?? 'N/A'); ?>)</p>
                                        </td>
                                        <td class="py-4 px-6" data-date="<?php echo strtotime($event['waktu_input']); ?>">
                                            <p class="text-[11px] font-medium text-slate-500"><?php echo date('d M Y', strtotime($event['waktu_input'])); ?></p>
                                            <p class="text-[9px] font-black text-slate-300 uppercase tracking-tighter"><?php echo date('H:i:s', strtotime($event['waktu_input'])); ?></p>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="flex items-center gap-2">
                                                <div class="h-6 w-6 bg-slate-100 rounded-full flex items-center justify-center text-[10px] font-bold text-slate-500"><?php echo strtoupper(substr($event['user_input'], 0, 1)); ?></div>
                                                <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($event['user_input']); ?></p>
                                            </div>
                                        </td>
                                        <td class="py-4 px-6">
                                            <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($event['nama_branch']); ?></p>
                                            <p class="text-[10px] font-medium text-slate-400"><?php echo htmlspecialchars($event['nama_micro_cluster']); ?></p>
                                        </td>
                                        <td class="py-4 px-6 text-right">
                                            <p class="text-sm font-black text-emerald-600">Rp <?php echo number_format($event['benefit_total'], 0, ',', '.'); ?></p>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="flex items-center justify-center gap-2">
                                                <a href="admin_detail_event.php?id=<?php echo $event['unique_id']; ?>" class="h-8 w-8 flex items-center justify-center bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-600 hover:text-white transition-all shadow-sm" title="Detail">
                                                    <i class="fas fa-eye text-xs"></i>
                                                </a>
                                                <a href="admin_event_form.php?id=<?php echo $event['unique_id']; ?>" class="h-8 w-8 flex items-center justify-center bg-amber-50 text-amber-600 rounded-lg hover:bg-amber-600 hover:text-white transition-all shadow-sm" title="Edit">
                                                    <i class="fas fa-pencil-alt text-xs"></i>
                                                </a>
                                                <button type="button" onclick="openEditDateModal('<?php echo $event['unique_id']; ?>', '<?php echo date('Y-m-d', strtotime($event['waktu_input'])); ?>', '<?php echo date('H:i', strtotime($event['waktu_input'])); ?>')" class="h-8 w-8 flex items-center justify-center bg-purple-50 text-purple-600 rounded-lg hover:bg-purple-600 hover:text-white transition-all shadow-sm" title="Edit Tanggal">
                                                    <i class="fas fa-calendar-alt text-xs"></i>
                                                </button>
                                                <button type="button" onclick="deleteSingle('<?php echo $event['unique_id']; ?>')" class="h-8 w-8 flex items-center justify-center bg-red-50 text-red-600 rounded-lg hover:bg-red-600 hover:text-white transition-all shadow-sm" title="Hapus">
                                                    <i class="fas fa-trash-alt text-xs"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="py-20 text-center text-slate-400 font-medium">Tidak ada data event yang ditemukan.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="p-8 bg-slate-50/50 border-t border-slate-100 flex flex-col md:flex-row justify-between items-center gap-6">
                        <div class="flex items-center gap-3">
                            <button type="submit" id="bulkDeleteEventsBtn" class="px-6 py-3 bg-red-600 text-white font-black text-[10px] uppercase tracking-widest rounded-xl hover:bg-red-700 shadow-lg shadow-red-200 disabled:opacity-30 disabled:shadow-none transition-all flex items-center gap-2" disabled>
                                <i class="fas fa-trash-alt"></i> Hapus yang Dipilih
                            </button>
                            <button type="button" id="bulkEditDateEventsBtn" class="px-6 py-3 bg-purple-600 text-white font-black text-[10px] uppercase tracking-widest rounded-xl hover:bg-purple-700 shadow-lg shadow-purple-200 disabled:opacity-30 disabled:shadow-none transition-all flex items-center gap-2" disabled onclick="openBulkEditDateEventModal()">
                                <i class="fas fa-calendar-alt"></i> Edit Tanggal Terpilih
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <!-- Pagination will go here -->
                        </div>
                    </div>
                </form>
            </div>

            <div class="mt-10 mb-20 flex justify-center">
                <nav class="flex items-center gap-2" aria-label="Pagination">
                    <?php
                    if($total_pages > 1) {
                        $max_pages_to_show = 5;
                        $start_page = max(1, $page - floor($max_pages_to_show / 2));
                        $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                        $start_page = max(1, $end_page - $max_pages_to_show + 1);

                        if ($page > 1) echo '<a href="?page='.($page-1).$filter_query_string.'" class="h-10 px-4 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all">Sebelumnya</a>';
                        
                        if ($start_page > 1) { 
                            echo '<a href="?page=1'.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all">1</a>'; 
                            if ($start_page > 2) echo '<span class="text-slate-300 text-xs">...</span>'; 
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++) { 
                            $active_class = ($i == $page) ? 'bg-blue-600 border-blue-600 text-white shadow-lg shadow-blue-200' : 'bg-white border-slate-200 text-slate-500 hover:border-blue-500 hover:text-blue-600'; 
                            echo '<a href="?page='.$i.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center border rounded-xl text-xs font-bold transition-all '.$active_class.'">'.$i.'</a>'; 
                        }
                        
                        if ($end_page < $total_pages) { 
                            if ($end_page < $total_pages - 1) echo '<span class="text-slate-300 text-xs">...</span>'; 
                            echo '<a href="?page='.$total_pages.$filter_query_string.'" class="h-10 w-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all">'.$total_pages.'</a>'; 
                        }
                        
                        if ($page < $total_pages) echo '<a href="?page='.($page+1).$filter_query_string.'" class="h-10 px-4 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:border-blue-500 hover:text-blue-600 transition-all">Selanjutnya</a>';
                    }
                    ?>
                </nav>
            </div>
        </main>
    </div>
</div>
<!-- Modal: Single Edit Tanggal Event -->
<div id="editDateEventModal" class="modal-overlay hidden">
    <div class="glass-card p-10 rounded-3xl w-full max-w-md border border-white/50">
        <h2 class="text-2xl font-extrabold text-slate-800 mb-1">Edit Tanggal Event</h2>
        <p class="text-xs text-slate-500 mb-8 font-medium">Ubah tanggal dan waktu input event ini.</p>
        <form id="editDateEventForm" action="" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="edit_date_event">
            <input type="hidden" name="unique_id" id="editDateEventId">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Tanggal Baru</label>
                <input type="date" name="new_date" id="editDateEventDate" required
                       class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Jam (opsional)</label>
                <input type="time" name="new_time" id="editDateEventTime"
                       class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all">
            </div>
            <div class="flex gap-4 pt-4">
                <button type="button" onclick="document.getElementById('editDateEventModal').classList.add('hidden')" class="w-1/2 px-6 py-3 bg-slate-100 text-slate-600 font-bold text-xs rounded-xl hover:bg-slate-200 transition-all uppercase tracking-widest">Batal</button>
                <button type="submit" class="w-1/2 px-6 py-3 bg-purple-600 text-white font-bold text-xs rounded-xl hover:bg-purple-700 shadow-lg shadow-purple-200 transition-all uppercase tracking-widest">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Bulk Edit Tanggal Event -->
<div id="bulkEditDateEventModal" class="modal-overlay hidden">
    <div class="glass-card p-10 rounded-3xl w-full max-w-md border border-white/50">
        <h2 class="text-2xl font-extrabold text-slate-800 mb-1">Edit Tanggal Massal</h2>
        <p class="text-xs text-slate-500 mb-1 font-medium">Ubah tanggal untuk semua event yang dipilih.</p>
        <p id="bulkEditEventCount" class="text-sm font-black text-purple-600 mb-8"></p>
        <form id="bulkEditDateEventForm" action="" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="bulk_edit_date_events">
            <div id="bulkEditEventIdsContainer"></div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Tanggal Baru</label>
                <input type="date" name="new_date" id="bulkEditDateEventDate" required
                       class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Jam (opsional, default 00:00)</label>
                <input type="time" name="new_time" id="bulkEditDateEventTime"
                       class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all">
            </div>
            <div class="flex gap-4 pt-4">
                <button type="button" onclick="document.getElementById('bulkEditDateEventModal').classList.add('hidden')" class="w-1/2 px-6 py-3 bg-slate-100 text-slate-600 font-bold text-xs rounded-xl hover:bg-slate-200 transition-all uppercase tracking-widest">Batal</button>
                <button type="submit" class="w-1/2 px-6 py-3 bg-purple-600 text-white font-bold text-xs rounded-xl hover:bg-purple-700 shadow-lg shadow-purple-200 transition-all uppercase tracking-widest">Simpan Semua</button>
            </div>
        </form>
    </div>
</div>

<script>
    // === Edit Tanggal Event Helpers ===
    function openEditDateModal(id, date, time) {
        document.getElementById('editDateEventId').value = id;
        document.getElementById('editDateEventDate').value = date;
        document.getElementById('editDateEventTime').value = time;
        document.getElementById('editDateEventModal').classList.remove('hidden');
    }

    function openBulkEditDateEventModal() {
        const checked = document.querySelectorAll('.event-checkbox:checked');
        if (checked.length === 0) return;
        const container = document.getElementById('bulkEditEventIdsContainer');
        container.innerHTML = '';
        checked.forEach(cb => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'event_ids[]';
            inp.value = cb.value;
            container.appendChild(inp);
        });
        document.getElementById('bulkEditEventCount').textContent = checked.length + ' event dipilih';
        document.getElementById('bulkEditDateEventModal').classList.remove('hidden');
    }

    // START PERBAIKAN: Fungsi untuk menampilkan prompt alasan penolakan
    function rejectSingleRequest(reqId) {
        const reason = prompt('Masukkan alasan penolakan untuk permintaan ini:');
        if (reason !== null) { // Jika user tidak membatalkan prompt
            const form = document.createElement('form');
            form.method = 'POST'; // Menggunakan POST untuk konsistensi
            form.action = ''; // Submit ke halaman ini sendiri
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'review_request';
            form.appendChild(actionInput);

            const reqIdInput = document.createElement('input');
            reqIdInput.type = 'hidden';
            reqIdInput.name = 'req_id';
            reqIdInput.value = reqId;
            form.appendChild(reqIdInput);

            const decisionInput = document.createElement('input');
            decisionInput.type = 'hidden';
            decisionInput.name = 'decision';
            decisionInput.value = 'reject';
            form.appendChild(decisionInput);

            const reasonInput = document.createElement('input');
            reasonInput.type = 'hidden';
            reasonInput.name = 'rejection_reason';
            reasonInput.value = reason;
            form.appendChild(reasonInput);

            document.body.appendChild(form);
            form.submit();
        }
    }

    function rejectAllRequests() {
        const reason = prompt('Masukkan alasan penolakan untuk SEMUA permintaan ini:');
        if (reason !== null) { // Jika user tidak membatalkan prompt
            const form = document.getElementById('rejectAllForm'); // Form yang sudah ada
            document.getElementById('rejection_reason_bulk_input').value = reason; // Set nilai ke hidden input
            form.submit();
        }
    }
    // END PERBAIKAN

    // START PERBAIKAN: Logika Select All dan Bulk Delete
    document.addEventListener('DOMContentLoaded', function() {
        const selectAllCheckbox = document.getElementById('select-all');
        const eventCheckboxes = document.querySelectorAll('.event-checkbox');
        const bulkDeleteEventsBtn = document.getElementById('bulkDeleteEventsBtn');
        const bulkEditDateEventsBtn = document.getElementById('bulkEditDateEventsBtn');
        const bulkDeleteForm = document.getElementById('bulk-delete-form');

        function updateBulkDeleteButtonState() {
            const anyChecked = Array.from(eventCheckboxes).some(checkbox => checkbox.checked);
            bulkDeleteEventsBtn.disabled = !anyChecked;
            if (bulkEditDateEventsBtn) bulkEditDateEventsBtn.disabled = !anyChecked;
        }

        selectAllCheckbox.addEventListener('change', function(e) {
            eventCheckboxes.forEach(function(checkbox) {
                checkbox.checked = e.target.checked;
            });
            updateBulkDeleteButtonState();
        });

        eventCheckboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                if (!this.checked) {
                    selectAllCheckbox.checked = false;
                } else {
                    const allChecked = Array.from(eventCheckboxes).every(cb => cb.checked);
                    if (allChecked) {
                        selectAllCheckbox.checked = true;
                    }
                }
                updateBulkDeleteButtonState();
            });
        });

        bulkDeleteEventsBtn.addEventListener('click', function(e) {
            if (!confirm('Apakah Anda yakin ingin menghapus semua event yang dipilih? Aksi ini tidak bisa dibatalkan.')) {
                e.preventDefault();
            }
            // Form akan disubmit oleh atribut type="submit" jika confirm true
        });

        // Initial state check on page load
        updateBulkDeleteButtonState();
    });
    // END PERBAIKAN

    function deleteSingle(id) {
        if (confirm('Apakah Anda yakin ingin menghapus event ini?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = ''; // Submit ke halaman ini sendiri
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_event'; // Aksi baru untuk hapus single
            form.appendChild(actionInput);

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'unique_id';
            idInput.value = id;
            form.appendChild(idInput);

            document.body.appendChild(form);
            form.submit();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const brandSelect = document.getElementById('brand_filter');
        const branchSelect = document.getElementById('branch_id');
        const mcSelect = document.getElementById('mc_id');

        function loadOptions(url, selectElement, prompt, selectedValue = null) {
             fetch(url)
                .then(response => response.json())
                .then(data => {
                    selectElement.innerHTML = `<option value="">${prompt}</option>`;
                    data.forEach(item => {
                        const option = new Option(item.nama_branch || item.nama_micro_cluster, item.id);
                        selectElement.add(option);
                    });
                    if (selectedValue) {
                        selectElement.value = selectedValue;
                    }
                    selectElement.disabled = false;
                })
                .catch(error => {
                    console.error('Error fetching options:', error);
                    selectElement.innerHTML = `<option value="">Gagal memuat</option>`;
                    selectElement.disabled = true;
                });
        }

        brandSelect.addEventListener('change', () => {
            mcSelect.innerHTML = '<option value="">Pilih Branch dulu</option>';
            mcSelect.disabled = true;
            if(brandSelect.value) {
                loadOptions(`api_helper.php?action=get_branches_by_brand&brand=${brandSelect.value}`, branchSelect, 'Semua Branch');
            } else {
                 branchSelect.innerHTML = '<option value="">Pilih Brand dulu</option>';
                 branchSelect.disabled = true;
            }
        });
        branchSelect.addEventListener('change', () => {
            if(branchSelect.value) {
                loadOptions(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${branchSelect.value}`, mcSelect, 'Semua Micro Cluster');
            } else {
                mcSelect.innerHTML = '<option value="">Pilih Branch dulu</option>';
                mcSelect.disabled = true;
            }
        });
        
        const initialBrand = '<?php echo $filters['brand']; ?>';
        const initialBranch = '<?php echo $filters['branch_id']; ?>';
        const initialMc = '<?php echo $filters['mc_id']; ?>';
        if (initialBrand) {
            loadOptions(`api_helper.php?action=get_branches_by_brand&brand=${initialBrand}`, branchSelect, 'Semua Branch', initialBranch);
        }
        if (initialBranch) {
            loadOptions(`api_helper.php?action=get_micro_clusters_by_branch&branch_id=${initialBranch}`, mcSelect, 'Semua Micro Cluster', initialMc);
        }
    });
    
</script>
<script>
    // UI Helpers (Required for exportTableToCSV)
    // UI Helpers (Required for exportTableToCSV)
    window.LoadingSpinner = {
        show: function(message) {
            const spinner = document.createElement('div');
            spinner.id = 'loadingSpinner';
            spinner.className = 'fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[9999] flex items-center justify-center';
            spinner.innerHTML = `
                <div class="bg-white p-6 rounded-2xl shadow-2xl flex flex-col items-center gap-4 animate-in fade-in zoom-in duration-200">
                    <div class="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                    <p class="text-slate-600 font-bold animate-pulse">${message || 'Loading...'}</p>
                </div>
            `;
            document.body.appendChild(spinner);
        },
        hide: function() {
            const spinner = document.getElementById('loadingSpinner');
            if (spinner) spinner.remove();
        }
    };

    window.Toast = {
        success: function(message) {
            this.show(message, 'bg-emerald-500');
        },
        error: function(message) {
            this.show(message, 'bg-red-500');
        },
        show: function(message, bgClass) {
            const toast = document.createElement('div');
            toast.className = `fixed bottom-8 right-8 ${bgClass} text-white px-6 py-4 rounded-xl shadow-2xl z-[9999] flex items-center gap-3 animate-in slide-in-from-bottom-5 duration-300`;
            toast.innerHTML = `
                <i class="fas fa-check-circle text-xl"></i>
                <span class="font-bold">${message}</span>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('animate-out', 'fade-out', 'slide-out-to-bottom-5');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
    };

    // === Edit Tanggal Event Helpers ===
    function openEditDateModal(id, date, time) {
        document.getElementById('editDateEventId').value = id;
        document.getElementById('editDateEventDate').value = date;
        document.getElementById('editDateEventTime').value = time;
        document.getElementById('editDateEventModal').classList.remove('hidden');
    }

    function openBulkEditDateEventModal() {
        const checked = document.querySelectorAll('.event-checkbox:checked');
        if (checked.length === 0) return;
        const container = document.getElementById('bulkEditEventIdsContainer');
        container.innerHTML = '';
        checked.forEach(cb => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'event_ids[]';
            inp.value = cb.value;
            container.appendChild(inp);
        });
        document.getElementById('bulkEditEventCount').textContent = checked.length + ' event dipilih';
        document.getElementById('bulkEditDateEventModal').classList.remove('hidden');
    }

    // Initialize Search and Sort for Admin Event Table
    document.addEventListener('DOMContentLoaded', function() {
        const adminEventSearch = new TableSearch('adminEventTable', 'adminEventSearchInput');
        
        // Export Dropdowns Logic
        function setupDropdown(btnId, menuId) {
            const btn = document.getElementById(btnId);
            const menu = document.getElementById(menuId);
            if(btn && menu) {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    menu.classList.toggle('hidden');
                });
                document.addEventListener('click', () => menu.classList.add('hidden'));
            }
        }
        setupDropdown('excelDropdownBtn', 'excelDropdownMenu');
        setupDropdown('csvDropdownBtn', 'csvDropdownMenu');

        // Wire bulk edit date button enable/disable
        const bulkEditDateEventsBtn = document.getElementById('bulkEditDateEventsBtn');
        function updateBulkEventBtns() {
            const count = document.querySelectorAll('.event-checkbox:checked').length;
            if (bulkEditDateEventsBtn) bulkEditDateEventsBtn.disabled = count === 0;
        }
        document.querySelectorAll('.event-checkbox').forEach(cb => cb.addEventListener('change', updateBulkEventBtns));
        const selectAllEvt = document.getElementById('select-all');
        if (selectAllEvt) selectAllEvt.addEventListener('change', updateBulkEventBtns);
        updateBulkEventBtns();

        window.submitEventExport = function(format, type) {
            const now = new Date();
            const formatDate = (date) => {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };
            const firstDay = formatDate(new Date(now.getFullYear(), now.getMonth(), 1));
            const lastDay = formatDate(new Date(now.getFullYear(), now.getMonth() + 1, 0));

            if (format === 'excel') {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'process/admin_export_process.php';
                
                const addInput = (name, value) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                };

                addInput('export_excel', '1');
                
                if (type === 'mtd') {
                    addInput('action', 'export_mtd');
                    addInput('start_date_export', firstDay);
                    addInput('end_date_export', lastDay);
                    // Send other empty filters to prevent undefined index warnings if backend expects them
                    addInput('keyword_export', '');
                    addInput('brand_export', '');
                    addInput('branch_id_export', '');
                    addInput('mc_id_export', '');
                    addInput('area_export', '');
                } else if (type === 'filtered') {
                    addInput('action', 'export_filtered');
                    addInput('keyword_export', document.getElementById('keyword').value);
                    addInput('start_date_export', document.getElementById('start_date').value);
                    addInput('end_date_export', document.getElementById('end_date').value);
                    addInput('brand_export', document.getElementById('brand_filter').value);
                    addInput('branch_id_export', document.getElementById('branch_id').value);
                    addInput('mc_id_export', document.getElementById('mc_id').value);
                    addInput('area_export', document.getElementById('area').value);
                } else if (type === 'all') {
                    addInput('action', 'export_all');
                    // Send empty filters for all data export
                    addInput('keyword_export', '');
                    addInput('start_date_export', '');
                    addInput('end_date_export', '');
                    addInput('brand_export', '');
                    addInput('branch_id_export', '');
                    addInput('mc_id_export', '');
                    addInput('area_export', '');
                }
                
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            } else if (format === 'csv') {
                const searchQuery = document.getElementById('adminEventSearchInput').value;
                let params = { search: searchQuery };
                
                if (type === 'mtd') {
                    params.start_date = firstDay;
                    params.end_date = lastDay;
                } else if (type === 'filtered') {
                    params.start_date = document.getElementById('start_date').value;
                    params.end_date = document.getElementById('end_date').value;
                    params.brand = document.getElementById('brand_filter').value;
                    params.branch_id = document.getElementById('branch_id').value;
                    params.mc_id = document.getElementById('mc_id').value;
                    params.area = document.getElementById('area').value;
                }
                
                exportTableToCSV('process/export_events_csv.php', params);
            }
        };
    });
</script>
        </main>
    </div>
</body>
</html>
