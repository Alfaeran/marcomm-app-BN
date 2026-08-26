<?php
// user_events.php
require_once 'config/database.php';

// Cek hak akses user
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'user') {
    header("location: login.php");
    exit;
}

$user_id = (int)$_SESSION['id'];

// Inisialisasi pesan
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// --- Daftar kolom yang bisa diedit oleh user untuk Event ---
$editable_columns = [
    'sp_0k' => 'SP 0K', 'sp_3gb' => 'SP 3GB', 'sp_5gb' => 'SP 5GB', 'sp_7gb' => 'SP 7GB', 'sp_100gb' => 'SP 100GB',
    'fwa' => 'FWA', 'sp_existing' => 'SP Existing', 'hit_haji_umroh' => 'HIT Haji/Umroh',
    'jumlah_audience' => 'Jumlah Audience', 'reload' => 'Reload', 'mobo_paket' => 'Mobo/Paket', 'cost' => 'Cost',
    'alasan' => 'Alasan / Feedback', 'provider_digunakan' => 'Provider Digunakan',
    'provider_terbaik' => 'Provider Sinyal Terbaik', 'kenal_im3' => 'Mengenal IM3?',
    'sudah_beli_im3' => 'Sudah Beli IM3?', 'lokasi_beli' => 'Lokasi Beli', 'tertarik_beli_im3' => 'Tertarik Beli IM3?',
    'foto_event_url' => 'Foto Event', 'msisdn_file' => 'File MSISDN'
];

// --- Logika Paginasi & Filter ---
$records_per_page = 25;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$search_keyword = $_GET['keyword'] ?? '';
$date_filter = $_GET['date_filter'] ?? 'all';
$filter_query_string = "";
$where_clauses = ["e.user_id = " . $user_id];
$param_types = "";
$param_values = [];

if (!empty($search_keyword)) {
    $where_clauses[] = "(e.event_name LIKE ? OR s.site_name LIKE ? OR s.site_id LIKE ?)";
    $param_types .= "sss";
    $keyword_like = "%" . $search_keyword . "%";
    array_push($param_values, $keyword_like, $keyword_like, $keyword_like);
    $filter_query_string .= "&keyword=" . urlencode($search_keyword);
}

if ($date_filter === 'today') {
    $where_clauses[] = "DATE(e.waktu_input) = CURDATE()";
    $filter_query_string .= "&date_filter=today";
} elseif ($date_filter === 'week') {
    $where_clauses[] = "DATE(e.waktu_input) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $filter_query_string .= "&date_filter=week";
} elseif ($date_filter === 'month') {
    $where_clauses[] = "MONTH(e.waktu_input) = MONTH(CURDATE()) AND YEAR(e.waktu_input) = YEAR(CURDATE())";
    $filter_query_string .= "&date_filter=month";
}

$where_sql = " WHERE " . implode(" AND ", $where_clauses);

// Query untuk menghitung total data
$count_sql = "SELECT COUNT(e.unique_id) as total FROM event_submissions e LEFT JOIN sites s ON e.site_id = s.id" . $where_sql;
$stmt_count = $mysqli->prepare($count_sql);
$total_records = 0;
if ($stmt_count) {
    if (!empty($param_values)) {
        $stmt_count->bind_param($param_types, ...$param_values);
    }
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    if ($result_count) {
        $total_records = $result_count->fetch_assoc()['total'];
    }
    $stmt_count->close();
}

$total_pages = ceil($total_records / $records_per_page);

// Query untuk mengambil data event user
$sql = "SELECT 
            e.unique_id, e.event_name, e.waktu_input, e.benefit_total,
            s.site_name, s.site_id as site_code, s.area
        FROM event_submissions e
        LEFT JOIN sites s ON e.site_id = s.id
        $where_sql
        ORDER BY e.waktu_input DESC
        LIMIT ? OFFSET ?";

$param_types_page = $param_types . "ii";
$param_values_page = array_merge($param_values, [$records_per_page, $offset]);

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param($param_types_page, ...$param_values_page);
    $stmt->execute();
    $events_result = $stmt->get_result();
} else {
    $events_result = false;
    $error_message = "Gagal mengambil data event: " . $mysqli->error;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Riwayat Event - <?php echo strip_tags($app_name ?? 'Marcomm Apps'); ?></title>
    <?php include 'components/head_shared.php'; ?>
    <script src="assets/js/progress.js"></script>
    <script src="assets/js/search-sort.js?v=<?= time() ?>"></script>
    <style>
        .status-pending { background-color: rgba(254, 243, 199, 0.5); color: #92400e; border: 1px solid rgba(254, 243, 199, 0.8); }
        .status-approved, .status-completed { background-color: rgba(209, 250, 229, 0.5); color: #065f46; border: 1px solid rgba(209, 250, 229, 0.8); }
        .status-rejected { background-color: rgba(254, 226, 226, 0.5); color: #991b1b; border: 1px solid rgba(254, 226, 226, 0.8); }
        
        /* Multi-select styling */
        .multi-select-dropdown .selected-items {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 8px 12px;
            min-height: 48px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            transition: all 0.2s;
        }
        .multi-select-dropdown .selected-items:hover { border-color: #3b82f6; }
        .multi-select-dropdown .dropdown-content {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            margin-top: 8px;
        }
        .multi-select-dropdown .selected-tag {
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 700;
            font-size: 10px;
            padding: 4px 10px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #dbeafe;
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
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Riwayat Event</h2>
                        <p class="text-slate-500 font-medium">Pantau dan kelola laporan event Anda.</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="exportEventActivities()" class="px-6 py-3 bg-emerald-600 text-white font-bold text-sm rounded-2xl hover:bg-emerald-700 shadow-lg shadow-emerald-200 active:scale-95 transition-all flex items-center gap-2">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                </div>
            </header>

            <div class="max-w-7xl mx-auto">
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

                <!-- Search and Filters -->
                <div class="glass-card p-6 mb-8 space-y-6">
                    <form action="" method="GET" class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div class="relative flex-grow max-w-2xl">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" name="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" placeholder="Cari Nama Event, Lokasi..." 
                                   class="w-full bg-slate-50 border border-slate-200 rounded-2xl pl-12 pr-12 py-3.5 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all placeholder:text-slate-400">
                            <?php if(!empty($search_keyword)): ?>
                            <a href="?date_filter=<?php echo htmlspecialchars($date_filter); ?>" class="clear-search absolute right-4 top-1/2 -translate-y-1/2 h-8 w-8 flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition-all">
                                <i class="fas fa-times"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="date_filter" value="<?php echo htmlspecialchars($date_filter); ?>">
                        <button type="submit" class="px-6 py-3.5 bg-slate-800 text-white font-bold rounded-2xl hover:bg-slate-900 transition-all text-sm shadow-md">
                            Cari
                        </button>
                    </form>
                    
                    <div class="flex flex-wrap gap-2">
                        <a href="?date_filter=all&keyword=<?php echo urlencode($search_keyword); ?>" class="filter-chip px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all glass-card <?php echo $date_filter == 'all' ? 'active' : 'text-slate-500 hover:text-blue-600'; ?>">Semua</a>
                        <a href="?date_filter=today&keyword=<?php echo urlencode($search_keyword); ?>" class="filter-chip px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all glass-card <?php echo $date_filter == 'today' ? 'active' : 'text-slate-500 hover:text-blue-600'; ?>">Hari Ini</a>
                        <a href="?date_filter=week&keyword=<?php echo urlencode($search_keyword); ?>" class="filter-chip px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all glass-card <?php echo $date_filter == 'week' ? 'active' : 'text-slate-500 hover:text-blue-600'; ?>">Minggu Ini</a>
                        <a href="?date_filter=month&keyword=<?php echo urlencode($search_keyword); ?>" class="filter-chip px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all glass-card <?php echo $date_filter == 'month' ? 'active' : 'text-slate-500 hover:text-blue-600'; ?>">Bulan Ini</a>
                    </div>
                </div>

                <div class="glass-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200" id="eventActivitiesTable">
                            <thead class="bg-slate-50/50">
                                <tr>
                                    <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Waktu</th>
                                    <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Nama Event</th>
                                    <th class="text-left py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Lokasi</th>
                                    <th class="text-right py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Revenue</th>
                                    <th class="text-center py-4 px-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white/30">
                                <?php if ($events_result && $events_result->num_rows > 0): ?>
                                    <?php while($event = $events_result->fetch_assoc()): ?>
                                    <tr class="hover:bg-blue-50/30 transition-colors">
                                        <td class="py-4 px-6 whitespace-nowrap" data-date="<?php echo strtotime($event['waktu_input']); ?>">
                                            <div class="flex flex-col">
                                                <span class="text-sm font-bold text-slate-700"><?php echo date('d M Y', strtotime($event['waktu_input'])); ?></span>
                                                <span class="text-[11px] font-bold text-slate-400"><?php echo date('H:i', strtotime($event['waktu_input'])); ?></span>
                                            </div>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="flex flex-col">
                                                <span class="text-sm font-extrabold text-slate-800"><?php echo htmlspecialchars($event['event_name']); ?></span>
                                                <span class="text-[10px] font-black text-blue-600 bg-blue-50 px-2 py-0.5 rounded border border-blue-100 inline-block w-max mt-1">
                                                    ID: <?php echo htmlspecialchars($event['unique_id']); ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="flex flex-col">
                                                <span class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($event['site_name'] ?? 'N/A'); ?></span>
                                                <span class="text-[11px] font-medium text-slate-500"><?php echo htmlspecialchars($event['site_code'] ?? 'N/A'); ?></span>
                                            </div>
                                        </td>
                                        <td class="py-4 px-6 text-right whitespace-nowrap">
                                            <span class="text-sm font-black text-emerald-600">Rp <?php echo number_format($event['benefit_total'], 0, ',', '.'); ?></span>
                                        </td>
                                        <td class="py-4 px-6 text-center whitespace-nowrap">
                                            <div class="flex items-center justify-center gap-2">
                                                <a href="user_detail_event.php?id=<?php echo $event['unique_id']; ?>" class="h-9 w-9 flex items-center justify-center bg-blue-50 text-blue-600 rounded-xl hover:bg-blue-600 hover:text-white transition-all border border-blue-100 shadow-sm shadow-blue-50" title="Lihat Detail">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button type="button" class="h-9 w-9 flex items-center justify-center bg-red-50 text-red-600 rounded-xl hover:bg-red-600 hover:text-white transition-all border border-red-100 shadow-sm shadow-red-50 request-btn" title="Request Hapus"
                                                    data-event-id="<?php echo htmlspecialchars($event['unique_id']); ?>" data-request-type="delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="py-12 text-center">
                                            <div class="flex flex-col items-center gap-4">
                                                <div class="h-16 w-16 bg-slate-50 text-slate-300 rounded-2xl flex items-center justify-center text-2xl">
                                                    <i class="fas fa-folder-open"></i>
                                                </div>
                                                <p class="text-slate-400 font-bold text-sm">Anda belum memiliki riwayat event.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-10 flex justify-center">
                    <nav class="flex items-center gap-2" aria-label="Pagination">
                        <a href="?page=<?php echo max(1, $page-1); ?><?php echo $filter_query_string; ?>" 
                           class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-500 hover:text-blue-600 hover:border-blue-200 transition-all <?php echo $page <= 1 ? 'pointer-events-none opacity-50' : ''; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>

                        <div class="flex items-center gap-1.5 px-2 bg-white/50 backdrop-blur-md rounded-2xl border border-white/20 p-1 shadow-sm">
                            <?php
                            $range = 2;
                            for ($i = 1; $i <= $total_pages; $i++) {
                                if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)) {
                                    $activeClass = ($i == $page) ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'text-slate-600 hover:bg-white hover:text-blue-600';
                                    echo '<a href="?page='.$i.$filter_query_string.'" class="h-9 min-w-[36px] px-2 flex items-center justify-center rounded-xl text-sm font-black transition-all '.$activeClass.'">'.$i.'</a>';
                                } elseif ($i == $page - $range - 1 || $i == $page + $range + 1) {
                                    echo '<span class="px-2 text-slate-400 font-bold">...</span>';
                                }
                            }
                            ?>
                        </div>

                        <a href="?page=<?php echo min($total_pages, $page+1); ?><?php echo $filter_query_string; ?>" 
                           class="h-10 w-10 flex items-center justify-center rounded-xl glass-card text-slate-500 hover:text-blue-600 hover:border-blue-200 transition-all <?php echo $page >= $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Request Modal -->
    <div id="request-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center hidden z-[100] p-4 animate-in fade-in duration-300">
        <div class="glass-card w-full max-w-lg overflow-hidden transform transition-all shadow-2xl" id="modal-container">
            <div class="px-8 py-6 border-b border-white/20 flex justify-between items-center bg-white/30">
                <div>
                    <h3 class="text-xl font-extrabold text-slate-800 tracking-tight" id="modal-title">Request</h3>
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-1">Konfirmasi Aksi Field Agent</p>
                </div>
                <button id="modal-close-btn" class="h-10 w-10 flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-white/50 rounded-xl transition-all">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form id="eventRequestForm" action="process/user_event_request_process.php" method="POST" class="p-8">
                <input type="hidden" name="event_id" id="modal_event_id">
                <input type="hidden" name="request_type" id="modal_request_type">
                
                <div class="mb-6 p-4 bg-blue-50/50 rounded-2xl border border-blue-100/50">
                    <div class="flex gap-3">
                        <div class="h-8 w-8 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center shrink-0"><i class="fas fa-info-circle"></i></div>
                        <div>
                            <p class="text-xs font-bold text-blue-800">Event ID:</p>
                            <p id="modal_event_display_id" class="text-sm font-black text-slate-800 font-mono"></p>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div>
                        <label for="reason" class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2 px-1">Alasan Request <span class="text-red-500">*</span></label>
                        <textarea name="reason" id="reason" rows="3" required class="w-full bg-slate-50 border border-slate-200 rounded-2xl p-4 text-sm font-medium focus:ring-4 focus:ring-blue-100 outline-none transition-all placeholder:text-slate-400" placeholder="Jelaskan secara singkat alasan Anda..."></textarea>
                    </div>

                    <div id="editable-columns-section" class="hidden">
                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-3 px-1">Pilih Kolom Edit (Maks. 5)</label>
                        <div class="multi-select-dropdown">
                            <div class="selected-items group" id="selected-columns-display">
                                <span class="placeholder text-slate-400">Pilih kolom...</span>
                            </div>
                            <div class="dropdown-content hidden max-h-48 overflow-y-auto custom-scrollbar" id="columns-dropdown-content">
                                <?php foreach ($editable_columns as $col_name => $col_label): ?>
                                    <label class="flex items-center gap-3 p-3 hover:bg-slate-50 transition-all cursor-pointer group">
                                        <input type="checkbox" name="requested_columns[]" value="<?php echo htmlspecialchars($col_name); ?>" data-label="<?php echo htmlspecialchars($col_label); ?>" class="event-column-checkbox h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                        <span class="text-[11px] font-bold text-slate-600 group-hover:text-blue-600"><?php echo htmlspecialchars($col_label); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <p id="column-selection-error" class="text-red-500 text-[10px] font-bold mt-2 hidden animate-pulse px-1">⚠️ Pilih setidaknya 1 dan maksimal 5 kolom.</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 mt-10">
                    <button type="button" id="modal-cancel" class="flex-grow py-3 bg-slate-100 text-slate-600 font-bold text-sm rounded-2xl hover:bg-slate-200 transition-all">
                        Batal
                    </button>
                    <button type="submit" class="flex-grow py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 active:scale-95 transition-all">
                        Kirim Request
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Rejection Reason Modal -->
    <div id="reasonModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center hidden z-[100] p-4 animate-in fade-in duration-300">
        <div class="glass-card w-full max-w-sm p-8 text-center animate-in zoom-in-95 duration-300 shadow-2xl">
            <div class="h-16 w-16 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-6">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <h3 class="text-lg font-extrabold text-slate-800 mb-2">Alasan Penolakan</h3>
            <p id="rejectionReasonText" class="text-sm font-medium text-slate-600 mb-8 leading-relaxed"></p>
            <button onclick="closeReasonModal()" class="w-full bg-slate-100 text-slate-600 font-bold py-3 rounded-xl hover:bg-slate-200 transition-all">Tutup</button>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('request-modal');
    const modalCancelBtn = document.getElementById('modal-cancel');
    const requestBtns = document.querySelectorAll('.request-btn');
    const eventRequestForm = document.getElementById('eventRequestForm');

    const modalEventId = document.getElementById('modal_event_id');
    const modalRequestType = document.getElementById('modal_request_type');
    const modalEventDisplayId = document.getElementById('modal_event_display_id');
    const modalTitle = document.getElementById('modal-title');

    const editableColumnsSection = document.getElementById('editable-columns-section');
    const selectedColumnsDisplay = document.getElementById('selected-columns-display');
    const columnsDropdownContent = document.getElementById('columns-dropdown-content');
    const columnCheckboxes = columnsDropdownContent.querySelectorAll('.event-column-checkbox');
    const columnSelectionError = document.getElementById('column-selection-error');
    
    const MAX_COLUMNS_ALLOWED = 5;
    let selectedColumnValues = new Set();

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
                <button type="button" class="w-full ${theme.btn} text-white font-bold py-3 rounded-xl shadow-lg active:scale-95 transition-all" onclick="this.closest('.message-box-overlay').remove()">Mengerti</button>
            </div>
        `;
        document.body.appendChild(messageBox);
    }

    function updateSelectedColumnsDisplay() {
        selectedColumnsDisplay.innerHTML = '';
        if (selectedColumnValues.size === 0) {
            selectedColumnsDisplay.innerHTML = '<span class="placeholder text-slate-400">Pilih kolom...</span>';
        } else {
            selectedColumnValues.forEach(value => {
                const checkbox = Array.from(columnCheckboxes).find(cb => cb.value === value);
                if (!checkbox) return;
                const label = checkbox.dataset.label;
                const tag = document.createElement('span');
                tag.className = 'selected-tag';
                tag.innerHTML = `${label} <span class="remove-tag cursor-pointer hover:text-red-500" data-value="${value}">&times;</span>`;
                selectedColumnsDisplay.appendChild(tag);
            });
        }
        validateColumnSelection();
    }

    function validateColumnSelection() {
        if (modalRequestType.value === 'edit' && (selectedColumnValues.size === 0 || selectedColumnValues.size > MAX_COLUMNS_ALLOWED)) {
            columnSelectionError.classList.remove('hidden');
            return false;
        } else {
            columnSelectionError.classList.add('hidden');
            return true;
        }
    }

    selectedColumnsDisplay.addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-tag')) {
            const valueToRemove = e.target.dataset.value;
            selectedColumnValues.delete(valueToRemove);
            const checkbox = Array.from(columnCheckboxes).find(cb => cb.value === valueToRemove);
            if (checkbox) checkbox.checked = false;
            updateSelectedColumnsDisplay();
            e.stopPropagation();
        } else {
            columnsDropdownContent.classList.toggle('hidden');
        }
    });

    columnCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                if (selectedColumnValues.size < MAX_COLUMNS_ALLOWED) {
                    selectedColumnValues.add(this.value);
                } else {
                    this.checked = false;
                    showMessageBox(`Anda hanya dapat memilih maksimal ${MAX_COLUMNS_ALLOWED} kolom.`, 'blue');
                }
            } else {
                selectedColumnValues.delete(this.value);
            }
            updateSelectedColumnsDisplay();
        });
    });

    window.addEventListener('click', (e) => {
        if (!columnsDropdownContent.parentElement.contains(e.target)) {
            columnsDropdownContent.classList.add('hidden');
        }
    });

    const openModal = (btn) => {
        const eventId = btn.dataset.eventId;
        const requestType = btn.dataset.requestType;
        
        modalEventId.value = eventId;
        modalRequestType.value = requestType;
        modalTitle.innerText = 'Request ' + (requestType.charAt(0).toUpperCase() + requestType.slice(1));
        modalEventDisplayId.innerText = eventId;

        if(requestType === 'edit') {
            editableColumnsSection.classList.remove('hidden');
        } else {
            editableColumnsSection.classList.add('hidden');
        }
        modal.classList.remove('hidden');
    };

    const closeModal = () => {
        modal.classList.add('hidden');
        eventRequestForm.reset();
        selectedColumnValues.clear();
        columnCheckboxes.forEach(cb => cb.checked = false);
        updateSelectedColumnsDisplay();
        editableColumnsSection.classList.add('hidden');
    };

    requestBtns.forEach(btn => btn.addEventListener('click', () => openModal(btn)));
    modalCancelBtn.addEventListener('click', closeModal);
    document.getElementById('modal-close-btn').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => (e.target === modal) && closeModal());

    eventRequestForm.addEventListener('submit', function(e) {
        if (modalRequestType.value === 'edit' && !validateColumnSelection()) {
            showMessageBox('Harap pilih setidaknya 1 kolom dan maksimal 5 kolom untuk diedit.', 'red');
            e.preventDefault();
        }
    });

    // Initialize Search and Sort for Event Activities
    const eventSort = new TableSort('eventActivitiesTable');
    
    window.exportEventActivities = function() {
        const urlParams = new URLSearchParams(window.location.search);
        exportTableToCSV('process/export_events_csv.php', Object.fromEntries(urlParams));
    };
});

function showReasonModal(reason) {
    document.getElementById('rejectionReasonText').innerText = reason;
    document.getElementById('reasonModal').classList.remove('hidden');
}

function closeReasonModal() {
    document.getElementById('reasonModal').classList.add('hidden');
}
</script>
</body>
</html>
