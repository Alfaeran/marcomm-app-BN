<?php
require_once 'cors.php';
require_once '../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');

function send_json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] ?? '') !== 'admin') {
    send_json_error('Akses ditolak. Silakan login kembali.', 403);
}

$action = $_GET['action'] ?? 'get_reports';

if ($action === 'get_pending_requests') {
    $pending_matpro_requests_sql = "
        (SELECT
            dr.id as request_id, dr.activity_id, dr.reason, dr.requested_at, dr.status,
            'delete' as request_type, dr.rejection_reason, u.username,
            ma.project_name, ma.type_name, 'deletion' as request_table_type
        FROM deletion_requests dr
        JOIN matpro_activities ma ON dr.activity_id = ma.id
        JOIN users u ON dr.user_id = u.id
        WHERE dr.status = 'pending')
        UNION ALL
        (SELECT
            mer.id as request_id, mer.activity_id, mer.reason, mer.requested_at, mer.status,
            'edit' as request_type, mer.rejection_reason, u.username,
            ma.project_name, ma.type_name, 'edit' as request_table_type
        FROM matpro_edit_requests mer
        JOIN matpro_activities ma ON mer.activity_id = ma.id
        JOIN users u ON mer.user_id = u.id
        WHERE mer.status = 'pending')
        ORDER BY requested_at ASC
    ";
    $result = $mysqli->query($pending_matpro_requests_sql);
    $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['data' => $data]);
    exit;
}

if ($action === 'get_reports') {
    $records_per_page = isset($_GET['limit']) && in_array($_GET['limit'], [10, 25, 50, 100]) ? (int)$_GET['limit'] : 25;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($page - 1) * $records_per_page;

    $where_clauses = [];
    $param_types = "";
    $param_values = [];

    $search_keyword = $_GET['keyword'] ?? '';
    if (!empty($search_keyword)) {
        $where_clauses[] = "(u.username LIKE ? OR ma.project_name LIKE ? OR ma.type_name LIKE ? OR ma.outlet_snapshot_name LIKE ? OR s.site_name LIKE ?)";
        $param_types .= "sssss";
        $keyword_like = "%" . $search_keyword . "%";
        array_push($param_values, $keyword_like, $keyword_like, $keyword_like, $keyword_like, $keyword_like);
    }
    
    $where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

    $count_sql = "SELECT COUNT(ma.id) as total
                  FROM matpro_activities ma
                  JOIN users u ON ma.user_id = u.id
                  JOIN sites s ON ma.site_id = s.id
                  LEFT JOIN outlets o ON ma.outlet_id = o.id
                  JOIN branches b ON ma.branch_id = b.id" . $where_sql;

    $stmt_count = $mysqli->prepare($count_sql);
    $total_records = 0;
    if ($stmt_count) {
        if (!empty($param_values)) {
            $stmt_count->bind_param($param_types, ...$param_values);
        }
        $stmt_count->execute();
        $total_records = $stmt_count->get_result()->fetch_assoc()['total'];
        $stmt_count->close();
    }
    
    $total_pages = ceil($total_records / $records_per_page);

    $sql = "SELECT ma.id, ma.unique_id, ma.activity_datetime, ma.qty_used,
                   u.username, u.nama as user_nama,
                   ma.project_name, b.brand, ma.type_name,
                   b.nama_branch, mc.nama_micro_cluster,
                   s.site_name, s.site_id as site_code,
                   COALESCE(o.Id_Outlet_Nama_Outlet, ma.outlet_snapshot_name, 'Outlet Telah Dihapus') as outlet_display_name
            FROM matpro_activities ma
            JOIN users u ON ma.user_id = u.id
            JOIN branches b ON ma.branch_id = b.id
            LEFT JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
            JOIN sites s ON ma.site_id = s.id
            LEFT JOIN outlets o ON ma.outlet_id = o.id"
            . $where_sql . " ORDER BY ma.activity_datetime DESC LIMIT ? OFFSET ?";
            
    $param_types_page = $param_types . "ii";
    $param_values_page = array_merge($param_values, [$records_per_page, $offset]);

    $stmt = $mysqli->prepare($sql);
    $activities = [];
    if ($stmt) {
        $stmt->bind_param($param_types_page, ...$param_values_page);
        $stmt->execute();
        $result = $stmt->get_result();
        $activities = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    echo json_encode([
        'data' => $activities,
        'pagination' => [
            'total' => (int)$total_records,
            'page' => $page,
            'limit' => $records_per_page,
            'total_pages' => (int)$total_pages
        ]
    ]);
    exit;
}

if ($action === 'review_request' && $method === 'POST') {
    $mysqli->begin_transaction();
    try {
        $req_id = (int)$_POST['req_id'];
        $decision = $_POST['decision'];
        $req_type = $_POST['request_type']; // 'edit' or 'delete'
        $reason = $mysqli->real_escape_string($_POST['rejection_reason'] ?? '');
        $admin_id = (int)$_SESSION['id'];

        if ($req_type === 'edit') {
            $req = $mysqli->query("SELECT * FROM matpro_edit_requests WHERE id = $req_id AND status = 'pending'")->fetch_assoc();
            if (!$req) throw new Exception("Permintaan tidak ditemukan.");
            
            if ($decision === 'approve') {
                $mysqli->query("UPDATE matpro_edit_requests SET status = 'approved', reviewed_by = $admin_id, reviewed_at = NOW() WHERE id = $req_id");
            } else {
                $mysqli->query("UPDATE matpro_edit_requests SET status = 'rejected', reviewed_by = $admin_id, reviewed_at = NOW(), rejection_reason = '$reason' WHERE id = $req_id");
            }
        } else if ($req_type === 'delete') {
            $req = $mysqli->query("SELECT * FROM deletion_requests WHERE id = $req_id AND status = 'pending'")->fetch_assoc();
            if (!$req) throw new Exception("Permintaan tidak ditemukan.");
            
            $act_id = (int)$req['activity_id'];
            if ($decision === 'approve') {
                $mysqli->query("DELETE FROM matpro_activities WHERE id = $act_id");
                $mysqli->query("UPDATE deletion_requests SET status = 'completed', reviewed_by = $admin_id, reviewed_at = NOW() WHERE id = $req_id");
            } else {
                $mysqli->query("UPDATE deletion_requests SET status = 'rejected', reviewed_by = $admin_id, reviewed_at = NOW(), rejection_reason = '$reason' WHERE id = $req_id");
            }
        }
        $mysqli->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json_error($e->getMessage());
    }
    exit;
}

send_json_error('Aksi tidak dikenali', 400);
