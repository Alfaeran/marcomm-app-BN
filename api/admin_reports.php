<?php
require_once 'cors.php';
require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function send_json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    send_json_error('Akses ditolak.', 403);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$admin_id = (int)$_SESSION['id'];

// --- GET METHODS ---
if ($action === 'get_events' && $method === 'GET') {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
    $offset = ($page - 1) * $limit;
    
    $where_clauses = [];
    if (!empty($_GET['keyword'])) {
        $kw = "'%" . $mysqli->real_escape_string($_GET['keyword']) . "%'";
        $where_clauses[] = "(e.event_name LIKE $kw OR u.username LIKE $kw OR s.site_name LIKE $kw)";
    }
    if (!empty($_GET['start_date'])) {
        $sd = "'" . $mysqli->real_escape_string($_GET['start_date'] . " 00:00:00") . "'";
        $where_clauses[] = "e.waktu_input >= $sd";
    }
    if (!empty($_GET['end_date'])) {
        $ed = "'" . $mysqli->real_escape_string($_GET['end_date'] . " 23:59:59") . "'";
        $where_clauses[] = "e.waktu_input <= $ed";
    }
    if (!empty($_GET['brand'])) {
        $br = "'" . $mysqli->real_escape_string($_GET['brand']) . "'";
        $where_clauses[] = "u.brand = $br";
    }
    if (!empty($_GET['branch_id'])) {
        $brid = (int)$_GET['branch_id'];
        $where_clauses[] = "s.branch_id = $brid";
    }
    if (!empty($_GET['mc_id'])) {
        $mcid = (int)$_GET['mc_id'];
        $where_clauses[] = "s.micro_cluster_id = $mcid";
    }
    
    $where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";
    
    $sort_by = $_GET['sort_by'] ?? 'waktu_desc';
    $allowed_sorts = ['waktu_desc' => 'e.waktu_input DESC', 'waktu_asc' => 'e.waktu_input ASC', 'benefit_desc' => 'e.benefit_total DESC', 'benefit_asc' => 'e.benefit_total ASC'];
    $order_by_sql = $allowed_sorts[$sort_by] ?? 'e.waktu_input DESC';
    
    $count_sql = "SELECT COUNT(e.unique_id) as total FROM event_submissions e JOIN users u ON e.user_id = u.id LEFT JOIN sites s ON e.site_id = s.id $where_sql";
    $total = $mysqli->query($count_sql)->fetch_assoc()['total'];
    
    $sql = "SELECT e.unique_id, e.event_name, e.waktu_input, e.benefit_total, u.username AS user_input, 
                   s.site_name, s.site_id as site_code, s.area, b.nama_branch, mc.nama_micro_cluster
            FROM event_submissions e
            JOIN users u ON e.user_id = u.id
            LEFT JOIN sites s ON e.site_id = s.id
            LEFT JOIN branches b ON s.branch_id = b.id
            LEFT JOIN micro_clusters mc ON s.micro_cluster_id = mc.id
            $where_sql ORDER BY $order_by_sql LIMIT $limit OFFSET $offset";
            
    $data = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'data' => $data,
        'pagination' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'total_pages' => ceil($total / $limit)]
    ]);
    exit;
}

if ($action === 'get_monthly_stats' && $method === 'GET') {
    $sql = "SELECT YEAR(waktu_input) as tahun, MONTH(waktu_input) as bulan, COUNT(unique_id) as total_event, SUM(benefit_total) as total_benefit 
            FROM event_submissions GROUP BY YEAR(waktu_input), MONTH(waktu_input) ORDER BY tahun DESC, bulan DESC";
    $data = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['data' => $data]);
    exit;
}

if ($action === 'get_pending_requests' && $method === 'GET') {
    $sql = "SELECT r.*, u.username, e.event_name 
            FROM event_requests r 
            JOIN users u ON r.user_id = u.id 
            JOIN event_submissions e ON r.event_id = e.unique_id 
            WHERE r.status = 'pending' ORDER BY r.requested_at ASC";
    $data = $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
    
    $editable_columns = [
        'sp_0k' => 'SP 0K', 'sp_3gb' => 'SP 3GB', 'sp_5gb' => 'SP 5GB', 'sp_7gb' => 'SP 7GB', 'sp_100gb' => 'SP 100GB',
        'fwa' => 'FWA', 'sp_existing' => 'SP Existing', 'hit_haji_umroh' => 'HIT Haji/Umroh',
        'jumlah_audience' => 'Jumlah Audience', 'reload' => 'Reload', 'mobo_paket' => 'Mobo/Paket', 'cost' => 'Cost',
        'alasan' => 'Alasan / Feedback', 'provider_digunakan' => 'Provider Digunakan',
        'provider_terbaik' => 'Provider Sinyal Terbaik', 'kenal_im3' => 'Mengenal IM3?',
        'sudah_beli_im3' => 'Sudah Beli IM3?', 'lokasi_beli' => 'Lokasi Beli', 'tertarik_beli_im3' => 'Tertarik Beli IM3?',
        'foto_event_url' => 'Foto Event', 'msisdn_file' => 'File MSISDN'
    ];
    
    foreach ($data as &$row) {
        $cols = json_decode($row['requested_columns'], true);
        $display = [];
        if (is_array($cols)) {
            foreach ($cols as $c) {
                $display[] = $editable_columns[$c] ?? $c;
            }
        }
        $row['requested_columns_display'] = implode(', ', $display);
    }
    
    echo json_encode(['data' => $data]);
    exit;
}

// --- POST METHODS ---
if ($action === 'review_request' && $method === 'POST') {
    $mysqli->begin_transaction();
    try {
        $req_id = (int)$_POST['req_id'];
        $decision = $_POST['decision'];
        $reason = $mysqli->real_escape_string($_POST['rejection_reason'] ?? '');
        
        $req = $mysqli->query("SELECT * FROM event_requests WHERE id = $req_id AND status = 'pending'")->fetch_assoc();
        if (!$req) throw new Exception("Permintaan tidak ditemukan.");
        
        $eid = (int)$req['event_id'];
        
        if ($decision === 'approve') {
            if ($req['request_type'] === 'delete') {
                $mysqli->query("DELETE FROM event_submissions WHERE unique_id = $eid");
                $mysqli->query("UPDATE event_requests SET status = 'completed', reviewed_by = $admin_id, reviewed_at = NOW() WHERE id = $req_id");
            } else {
                $mysqli->query("UPDATE event_requests SET status = 'approved', reviewed_by = $admin_id, reviewed_at = NOW() WHERE id = $req_id");
            }
        } else {
            $mysqli->query("UPDATE event_requests SET status = 'rejected', reviewed_by = $admin_id, reviewed_at = NOW(), rejection_reason = '$reason' WHERE id = $req_id");
        }
        $mysqli->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json_error($e->getMessage());
    }
    exit;
}

if ($action === 'approve_all_requests' && $method === 'POST') {
    $mysqli->begin_transaction();
    try {
        $pending = $mysqli->query("SELECT id, event_id, request_type FROM event_requests WHERE status = 'pending'");
        $deletes = [];
        while ($r = $pending->fetch_assoc()) {
            if ($r['request_type'] === 'delete') $deletes[] = $r['event_id'];
        }
        if (!empty($deletes)) {
            $ids = implode(',', $deletes);
            $mysqli->query("DELETE FROM event_submissions WHERE unique_id IN ($ids)");
        }
        $mysqli->query("UPDATE event_requests SET status = 'completed', reviewed_by = $admin_id, reviewed_at = NOW() WHERE status = 'pending' AND request_type = 'delete'");
        $mysqli->query("UPDATE event_requests SET status = 'approved', reviewed_by = $admin_id, reviewed_at = NOW() WHERE status = 'pending' AND request_type = 'edit'");
        $mysqli->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json_error($e->getMessage());
    }
    exit;
}

if ($action === 'reject_all_requests' && $method === 'POST') {
    $reason = $mysqli->real_escape_string($_POST['rejection_reason'] ?? '');
    $mysqli->query("UPDATE event_requests SET status = 'rejected', reviewed_by = $admin_id, reviewed_at = NOW(), rejection_reason = '$reason' WHERE status = 'pending'");
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_event' && $method === 'POST') {
    $id = (int)$_POST['unique_id'];
    $mysqli->query("DELETE FROM event_submissions WHERE unique_id = $id");
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'bulk_delete_events' && $method === 'POST') {
    $ids = $_POST['event_ids'] ?? [];
    if (!empty($ids)) {
        $safe_ids = array_map('intval', $ids);
        $id_list = implode(',', $safe_ids);
        $mysqli->query("DELETE FROM event_submissions WHERE unique_id IN ($id_list)");
    }
    echo json_encode(['success' => true]);
    exit;
}

send_json_error('Aksi tidak valid.');
