<?php
/**
 * api_helper.php — FINAL (Per-User Stock, Hardened JSON)
 *
 * - Semua response selalu JSON (tidak ada <br><b> HTML error dari PHP)
 * - Pembacaan stok MATPRO benar-benar PER-USER memakai $_SESSION['id'] & $_SESSION['branch_id']
 * - Tanpa dependensi tabel jenis_tipe (pakai kolom string ms.type_name)
 * - Output untuk opsi dropdown distandarkan ke { id, text }
 * - Semua route punya error handling yang konsisten
 */

// ===== Runtime Hardening =====
ini_set('display_errors', '0');     // Jangan bocorkan error ke klien
ini_set('log_errors', '1');         // Simpan ke error_log
error_reporting(E_ALL);

// ===== Util JSON =====
function send_json_response($data) {
    if (ob_get_level()) { @ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function send_json_error($message, $http_code = 500, $extra = []) {
    if (ob_get_level()) { @ob_clean(); }
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['error' => $message] + $extra;
    echo json_encode($payload);
    exit;
}

// Tangkap semua error/exception → JSON (hindari HTML token '<')
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Bisa ditambah logging rinci dengan error_log("[$errno] $errstr @ $errfile:$errline");
    send_json_error('Terjadi kesalahan pada server (PHP Error).', 500);
});
set_exception_handler(function ($ex) {
    send_json_error('Terjadi kesalahan pada server (Exception): ' . $ex->getMessage(), 500);
});

@ob_start();
require_once 'api/cors.php';
require_once 'config/database.php'; // Pastikan file ini tidak echo apapun
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// ===== Auth Guard =====
if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    send_json_error('Akses ditolak. Silakan login kembali.', 403);
}
$ACTION         = $_GET['action']         ?? '';
$SESSION_UID    = (int)($_SESSION['id']   ?? 0);
$SESSION_BID    = (int)($_SESSION['branch_id'] ?? 0);
$SESSION_ROLE   = $_SESSION['role']       ?? 'user';
$SESSION_BRAND  = $_SESSION['brand']      ?? null;
if ($SESSION_UID <= 0) { send_json_error('Sesi pengguna tidak valid.', 403); }

// ===== Helper eksekusi & formatter per action =====
function exec_and_send(?mysqli_stmt $stmt = null, string $action = '') {
    if (!$stmt) { send_json_error('Gagal menyiapkan query.', 500); }
    if (!$stmt->execute()) { send_json_error('Gagal mengeksekusi query.', 500); }
    $res  = $stmt->get_result();
    $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    switch ($action) {
        case 'get_matpro_types_with_stock': {
            $out = [];
            foreach ($data as $row) {
                $txt = $row['text'] ?? $row['type_name'] ?? null;
                if ($txt !== null) { $out[] = ['id' => $txt, 'text' => $txt]; }
            }
            send_json_response($out);
        }
        case 'get_outlets_by_site': {
            $out = [];
            foreach ($data as $row) { $out[] = ['id' => $row['id'], 'text' => $row['text']]; }
            send_json_response($out);
        }
        case 'get_monthly_performance': {
            $labels=[]; $event_counts=[]; $qsc_counts=[]; $benefit_totals=[];
            foreach ($data as $r) {
                $labels[] = $r['period'];
                $event_counts[] = (int)$r['event_count'];
                $qsc_counts[] = (int)$r['qsc_count'];
                $benefit_totals[] = (float)$r['benefit_total'];
            }
            send_json_response([
                'labels' => $labels,
                'event_counts' => $event_counts,
                'qsc_counts' => $qsc_counts,
                'benefit_totals' => $benefit_totals
            ]);
        }
        case 'get_category_distribution': {
            $labels=[]; $values=[];
            foreach ($data as $r){ $labels[]=$r['nama_kategori']; $values[]=(int)$r['event_count']; }
            send_json_response(['labels'=>$labels,'values'=>$values]);
        }
        case 'get_key_metrics': {
            $row = $data[0] ?? ['total_events'=>0,'total_qsc'=>0,'total_benefit'=>0];
            // Cast numbers
            $row['total_events']  = (int)($row['total_events']  ?? 0);
            $row['total_qsc']     = (int)($row['total_qsc']     ?? 0);
            $row['total_benefit'] = (float)($row['total_benefit'] ?? 0);
            send_json_response($row);
        }
        case 'get_site_details':
        case 'get_stock_details': {
            send_json_response($data[0] ?? null);
        }
        case 'get_matpro_stock_quantity': {
            $row = $data[0] ?? ['stock_quantity'=>0];
            send_json_response(['stock_quantity' => (int)($row['stock_quantity'] ?? 0)]);
        }
        case 'get_daily_trend': {
            $labels = []; $counts = [];
            foreach ($data as $r) {
                $labels[] = date('d M', strtotime($r['d']));
                $counts[] = (int)$r['c'];
            }
            send_json_response(['labels' => $labels, 'counts' => $counts]);
        }
        case 'get_recent_activities': {
            send_json_response($data);
        }
        default:
            send_json_response($data);
    }
}

// ===== Routing =====
switch ($ACTION) {
    // ---------- MARPRO RECEIVE & ALLOCATION ----------
    case 'get_active_projects': {
        global $SESSION_BRAND;
        if (empty($SESSION_BRAND)) { send_json_response([]); }
        $stmt = $mysqli->prepare("SELECT DISTINCT project_name AS id, project_name AS text FROM matpro_stocks WHERE is_active = 1 AND (project_brand = ? OR project_brand = 'BOTH') ORDER BY project_name");
        if (!$stmt) { send_json_error("Gagal menyiapkan query get_active_projects: " . $mysqli->error); }
        $stmt->bind_param('s', $SESSION_BRAND);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_active_types_by_project': {
        $project_name = $_GET['project_id'] ?? ''; // JS sends project_id parameter containing the name string
        if (empty($project_name)) { send_json_response([]); }
        $stmt = $mysqli->prepare("SELECT DISTINCT type_name AS id, type_name AS text FROM matpro_stocks WHERE project_name = ? AND is_active = 1 ORDER BY type_name");
        if (!$stmt) { send_json_error("Gagal menyiapkan query get_active_types_by_project: " . $mysqli->error); }
        $stmt->bind_param('s', $project_name);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_receivable_marpros': {
        global $SESSION_BID;
        if ($SESSION_BID === 0) { send_json_response([]); }
        $stmt = $mysqli->prepare("SELECT mr.id, CONCAT('[', mr.tanggal_terima, '] ', mr.project_name, ' - ', mr.type_name, ' (Sisa: ', (mr.qty_branch - mr.qty_allocated), ' Pcs)') AS text 
                                 FROM marpro_receives mr
                                 WHERE mr.branch_id = ? AND (mr.qty_branch - mr.qty_allocated) > 0
                                 ORDER BY mr.tanggal_terima DESC");
        $stmt->bind_param('i', $SESSION_BID);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_branch_stock_by_receive': {
        global $SESSION_UID, $SESSION_BID;
        $receive_id = (int)($_GET['receive_id'] ?? 0);
        if ($receive_id === 0 || $SESSION_BID === 0) { send_json_response(['sisa_receive' => 0, 'stock_matpro' => 0, 'max_qty' => 0]); }

        // Ambil sisa dari marpro_receives
        $stmt_recv = $mysqli->prepare("SELECT (qty_branch - qty_allocated) AS sisa, project_name, type_name FROM marpro_receives WHERE id = ? AND branch_id = ?");
        if (!$stmt_recv) { send_json_error('Gagal query receive.', 500); }
        $stmt_recv->bind_param('ii', $receive_id, $SESSION_BID);
        $stmt_recv->execute();
        $recv = $stmt_recv->get_result()->fetch_assoc();
        $stmt_recv->close();

        if (!$recv) { send_json_response(['sisa_receive' => 0, 'stock_matpro' => 0, 'max_qty' => 0]); }

        $sisa_receive = (int)$recv['sisa'];
        $project_name = $recv['project_name'];
        $type_name    = $recv['type_name'];

        // Ambil total stok aktual di matpro_stocks level branch
        $stmt_stk = $mysqli->prepare(
            "SELECT COALESCE(SUM(stock_quantity), 0) AS stock_total
             FROM matpro_stocks
             WHERE user_id = ? AND project_name = ? AND type_name = ? AND branch_id = ?
               AND (micro_cluster_id IS NULL OR micro_cluster_id = 0) AND is_active = 1"
        );
        if (!$stmt_stk) { send_json_error('Gagal query stock.', 500); }
        $stmt_stk->bind_param('issi', $SESSION_UID, $project_name, $type_name, $SESSION_BID);
        $stmt_stk->execute();
        $stk = $stmt_stk->get_result()->fetch_assoc();
        $stmt_stk->close();

        $stock_matpro = (int)($stk['stock_total'] ?? 0);
        $max_qty      = min($sisa_receive, $stock_matpro);

        send_json_response([
            'sisa_receive'  => $sisa_receive,
            'stock_matpro'  => $stock_matpro,
            'max_qty'       => $max_qty,
            'project_name'  => $project_name,
            'type_name'     => $type_name,
        ]);
    }

    // ---------- MATPRO: Wilayah & Lokasi ----------
    case 'get_branches_by_brand': {
        $brand = $_GET['brand'] ?? '';
        if ($brand === '') { send_json_response([]); }
        $stmt = $mysqli->prepare("SELECT id, nama_branch FROM branches WHERE brand = ? OR brand = 'BOTH' ORDER BY nama_branch");
        $stmt->bind_param('s', $brand);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_micro_clusters_by_branch': {
        $branch_id = (int)($_GET['branch_id'] ?? 0);
        if ($branch_id === 0) { send_json_response([]); }
        $stmt = $mysqli->prepare("SELECT id, nama_micro_cluster FROM micro_clusters WHERE branch_id = ? ORDER BY nama_micro_cluster");
        $stmt->bind_param('i', $branch_id);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_user_micro_clusters': {
        global $SESSION_UID;
        if ($SESSION_UID === 0) { send_json_error('Sesi tidak valid.', 403);} 
        $stmt = $mysqli->prepare("SELECT mc.id, mc.nama_micro_cluster
                                   FROM user_micro_clusters umc
                                   JOIN micro_clusters mc ON umc.micro_cluster_id = mc.id
                                   WHERE umc.user_id = ?
                                   ORDER BY mc.nama_micro_cluster");
        $stmt->bind_param('i', $SESSION_UID);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_sites': {
        $micro_cluster_id = (int)($_GET['micro_cluster_id'] ?? 0);
        if ($micro_cluster_id === 0) { send_json_response([]); }
        $stmt = $mysqli->prepare("SELECT id, site_name FROM sites WHERE micro_cluster_id = ? ORDER BY site_name");
        $stmt->bind_param('i', $micro_cluster_id);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_sites_by_branch': {
        $branch_id = (int)($_GET['branch_id'] ?? 0);
        if ($branch_id === 0) { send_json_response([]); }
        $stmt = $mysqli->prepare("SELECT id, site_name FROM sites WHERE branch_id = ? ORDER BY site_name");
        if (!$stmt) { send_json_error('Gagal menyiapkan query branch.', 500); }
        $stmt->bind_param('i', $branch_id);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_site_details': {
        $site_id = (int)($_GET['site_id'] ?? 0);
        if ($site_id === 0) { send_json_error('Site ID tidak valid.', 400); }
        $stmt = $mysqli->prepare("SELECT kecamatan, kabupaten FROM sites WHERE id = ?");
        $stmt->bind_param('i', $site_id);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_outlets_by_site': {
        $site_id = (int)($_GET['site_id'] ?? 0);
        if ($site_id === 0) { send_json_response([]); }
        // Keluarkan sebagai {id, text} agar konsisten dengan JS
        $stmt = $mysqli->prepare("SELECT id, Id_Outlet_Nama_Outlet AS text FROM outlets WHERE site_id = ? ORDER BY nama_outlet");
        $stmt->bind_param('i', $site_id);
        exec_and_send($stmt, $ACTION);
    }

    // ---------- MATPRO: Stok PER-USER (tanpa jenis_tipe) ----------
    case 'get_matpro_types_with_stock': {
        global $SESSION_BID, $SESSION_UID;
        $project_name = $_GET['project_name'] ?? '';
        $mc_id_param  = $_GET['mc_id'] ?? null; // optional
        if ($project_name === '' || $SESSION_BID === 0 || $SESSION_UID === 0) { send_json_response([]); }
        $sql = "SELECT DISTINCT ms.type_name AS text
                FROM matpro_stocks ms
                WHERE ms.project_name = ?
                  AND ms.branch_id    = ?
                  AND ms.user_id      = ?
                  AND ms.is_active    = 1
                  AND ms.stock_quantity > 0";
        $params = [$project_name, $SESSION_BID, $SESSION_UID];
        $types  = 'sii';
        if ($mc_id_param !== null && $mc_id_param !== '' && (int)$mc_id_param > 0) {
            $sql .= ' AND ms.micro_cluster_id = ?';
            $params[] = (int)$mc_id_param; $types .= 'i';
        } else {
            $sql .= ' AND (ms.micro_cluster_id IS NULL OR ms.micro_cluster_id = 0)';
        }
        $sql .= ' ORDER BY ms.type_name';
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) { send_json_error('Gagal menyiapkan query jenis.', 500); }
        $stmt->bind_param($types, ...$params);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_matpro_stock_quantity': {
        global $SESSION_BID, $SESSION_UID;
        $project_name = $_GET['project_name'] ?? '';
        $type_name    = $_GET['type_name'] ?? '';
        $mc_id_param  = $_GET['mc_id'] ?? null; // optional
        if ($project_name === '' || $type_name === '' || $SESSION_BID === 0 || $SESSION_UID === 0) {
            send_json_response(['stock_quantity' => 0]);
        }
        $sql = "SELECT COALESCE(SUM(ms.stock_quantity),0) AS stock_quantity
                FROM matpro_stocks ms
                WHERE ms.project_name = ?
                  AND ms.type_name    = ?
                  AND ms.branch_id    = ?
                  AND ms.user_id      = ?
                  AND ms.is_active    = 1";
        $params = [$project_name, $type_name, $SESSION_BID, $SESSION_UID];
        $types  = 'ssii';
        if ($mc_id_param !== null && $mc_id_param !== '' && (int)$mc_id_param > 0) {
            $sql .= ' AND ms.micro_cluster_id = ?';
            $params[] = (int)$mc_id_param; $types .= 'i';
        } else {
            $sql .= ' AND (ms.micro_cluster_id IS NULL OR ms.micro_cluster_id = 0)';
        }
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) { send_json_error('Gagal menyiapkan query stok.', 500); }
        $stmt->bind_param($types, ...$params);
        exec_and_send($stmt, $ACTION);
    }

    // ---------- Event & Dashboard (tetap) ----------
    case 'get_all_events_locations': {
        $category = $_GET['category'] ?? '';
        $search   = $_GET['search']   ?? '';
        
        $where = ["es.location_latitude IS NOT NULL", "es.location_longitude IS NOT NULL"];
        $params = [];
        $types = '';

        if ($SESSION_ROLE === 'user') {
            $where[] = "es.user_id = ?";
            $params[] = $SESSION_UID;
            $types .= 'i';
        }

        if ($category !== '' && $category !== 'all') {
            $where[] = "es.kategori_event_id = ?";
            $params[] = (int)$category;
            $types .= 'i';
        }

        if ($search !== '') {
            $where[] = "(es.event_name LIKE ? OR u.username LIKE ?)";
            $search_val = "%$search%";
            $params[] = $search_val;
            $params[] = $search_val;
            $types .= 'ss';
        }

        $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";
        $sql = "SELECT es.unique_id, es.event_name, es.location_latitude AS lat, es.location_longitude AS lon, ec.nama_kategori, u.username
                FROM event_submissions es
                JOIN users u ON es.user_id = u.id
                LEFT JOIN event_categories ec ON es.kategori_event_id = ec.id
                $where_sql";
        
        $stmt = $mysqli->prepare($sql);
        if ($types !== '' && $stmt) { $stmt->bind_param($types, ...$params); }
        exec_and_send($stmt, $ACTION);
    }

    case 'get_categories': {
        $stmt = $mysqli->prepare("SELECT id, nama_kategori FROM event_categories ORDER BY nama_kategori");
        exec_and_send($stmt, $ACTION);
    }

    case 'get_key_metrics': {
        $brand     = $_GET['brand'] ?? '';
        $branch_id = $_GET['branch_id'] ?? '';
        $start_date = $_GET['start_date'] ?? date('Y-m-01');
        $end_date   = $_GET['end_date'] ?? date('Y-m-t');

        $where = ["es.waktu_input BETWEEN ? AND ?"];
        $params = [$start_date . " 00:00:00", $end_date . " 23:59:59"];
        $types = "ss";

        if ($brand !== '')     { $where[] = 'u.brand = ?';            $params[] = $brand;       $types .= 's'; }
        if ($branch_id !== '') { $where[] = 's.branch_id = ?';        $params[] = (int)$branch_id; $types .= 'i'; }
        $where_sql = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT COUNT(es.unique_id) AS total_events,
                       SUM(es.jumlah_qsc) AS total_qsc,
                       SUM(es.benefit_total) AS total_benefit
                FROM event_submissions es
                JOIN users u ON es.user_id = u.id
                LEFT JOIN sites s ON es.site_id = s.id
                $where_sql";
        $stmt = $mysqli->prepare($sql);
        if ($stmt) { $stmt->bind_param($types, ...$params); }
        exec_and_send($stmt, $ACTION);
    }

    case 'get_monthly_performance': {
        $brand     = $_GET['brand'] ?? '';
        $branch_id = $_GET['branch_id'] ?? '';
        $start_date = $_GET['start_date'] ?? date('Y-m-01');
        $end_date   = $_GET['end_date'] ?? date('Y-m-t');

        $where = ["es.waktu_input BETWEEN ? AND ?"];
        $params = [$start_date . " 00:00:00", $end_date . " 23:59:59"];
        $types = "ss";

        if ($brand !== '')     { $where[] = 'u.brand = ?';            $params[] = $brand;       $types .= 's'; }
        if ($branch_id !== '') { $where[] = 's.branch_id = ?';        $params[] = (int)$branch_id; $types .= 'i'; }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        // Calculate grouping: if range is <= 45 days, group by DAY. Otherwise group by MONTH.
        $d1 = new DateTime($start_date);
        $d2 = new DateTime($end_date);
        $diff = $d1->diff($d2)->days;
        $group_by = ($diff <= 45) ? 'DATE(es.waktu_input)' : 'MONTH(es.waktu_input)';

        $sql = "SELECT $group_by AS period,
                       COUNT(es.unique_id) AS event_count,
                       SUM(es.jumlah_qsc) AS qsc_count,
                       SUM(es.benefit_total) AS benefit_total
                FROM event_submissions es
                JOIN users u ON es.user_id = u.id
                LEFT JOIN sites s ON es.site_id = s.id
                $where_sql
                GROUP BY $group_by
                ORDER BY $group_by ASC";
        $stmt = $mysqli->prepare($sql);
        if ($stmt) { $stmt->bind_param($types, ...$params); }
        exec_and_send($stmt, $ACTION);
    }
    
    case 'get_category_distribution': {
        $brand     = $_GET['brand'] ?? '';
        $branch_id = $_GET['branch_id'] ?? '';
        $start_date = $_GET['start_date'] ?? date('Y-m-01');
        $end_date   = $_GET['end_date'] ?? date('Y-m-t');

        $where = ["es.waktu_input BETWEEN ? AND ?"];
        $params = [$start_date . " 00:00:00", $end_date . " 23:59:59"];
        $types = "ss";

        if ($brand !== '')     { $where[] = 'u.brand = ?';            $params[] = $brand;       $types .= 's'; }
        if ($branch_id !== '') { $where[] = 's.branch_id = ?';        $params[] = (int)$branch_id; $types .= 'i'; }
        $where_sql = 'WHERE ' . implode(' AND ', $where);
        $stmt = $mysqli->prepare("SELECT ec.nama_kategori, COUNT(es.unique_id) AS event_count
                                   FROM event_submissions es
                                   JOIN users u ON es.user_id = u.id
                                   LEFT JOIN sites s ON es.site_id = s.id
                                   JOIN event_categories ec ON es.kategori_event_id = ec.id
                                   $where_sql
                                   GROUP BY ec.nama_kategori
                                   ORDER BY event_count DESC");
        if ($stmt) { $stmt->bind_param($types, ...$params); }
        exec_and_send($stmt, $ACTION);
    }

    // ---------- Info stok detail (optional util) ----------
    case 'get_stock_details': {
        $stock_id = (int)($_GET['id'] ?? 0);
        if ($stock_id === 0) { send_json_error('ID Stok tidak valid.', 400); }
        $stmt = $mysqli->prepare("SELECT ms.id, ms.stock_quantity, ms.project_name, ms.project_brand, ms.type_name,
                                         b.nama_branch, mc.nama_micro_cluster
                                  FROM matpro_stocks ms
                                  JOIN branches b ON ms.branch_id = b.id
                                  LEFT JOIN micro_clusters mc ON ms.micro_cluster_id = mc.id
                                  WHERE ms.id = ?");
        $stmt->bind_param('i', $stock_id);
        exec_and_send($stmt, $ACTION);
    }

    case 'get_daily_trend': {
        $brand = $_GET['brand'] ?? '';
        $branch_id = $_GET['branch_id'] ?? '';
        $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-14 days'));
        $end_date = $_GET['end_date'] ?? date('Y-m-d');

        $where = ["es.waktu_input BETWEEN ? AND ?"];
        $params = [$start_date . " 00:00:00", $end_date . " 23:59:59"];
        $types = "ss";

        if ($brand !== '')     { $where[] = 'u.brand = ?';            $params[] = $brand;       $types .= 's'; }
        if ($branch_id !== '') { $where[] = 's.branch_id = ?';        $params[] = (int)$branch_id; $types .= 'i'; }
        $where_sql = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT DATE(es.waktu_input) as d, COUNT(*) as c 
                FROM event_submissions es
                JOIN users u ON es.user_id = u.id
                LEFT JOIN sites s ON es.site_id = s.id
                $where_sql 
                GROUP BY DATE(es.waktu_input) ORDER BY d ASC";
        $stmt = $mysqli->prepare($sql);
        if ($types !== '' && $stmt) { $stmt->bind_param($types, ...$params); }
        exec_and_send($stmt, $ACTION);
    }

    case 'get_recent_activities': {
        $stmt = $mysqli->prepare("SELECT al.username, al.action, al.description, al.timestamp 
                                   FROM activity_logs al 
                                   ORDER BY al.timestamp DESC LIMIT 10");
        exec_and_send($stmt, $ACTION);
    }

    default:
        send_json_error('Aksi tidak valid', 400);
}

// Fallback (tidak seharusnya tercapai)
send_json_error('Tidak ada respons.', 500);
