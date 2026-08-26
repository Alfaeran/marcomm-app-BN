<?php
// process/admin_laporan_matpro_process.php
require_once '../config/database.php';

// DEBUG: Tambahkan baris ini untuk memverifikasi versi file yang sedang berjalan
error_log("DEBUG: admin_laporan_matpro_process.php - Versi 23 Juli 2025 - Menambahkan fitur Tolak Semua");

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_POST['action'])) {
    header("location: ../admin_laporan_matpro.php");
    exit;
}

$action = $_POST['action'];
$admin_id = (int)$_SESSION['id'];

// Helper function for bind_param with dynamic arguments
if (!function_exists('ref_values')) {
    function ref_values(&$arr){
        $refs = [];
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
}

// --- Fungsi untuk membangun klausa WHERE dinamis untuk Ekspor ---
function build_where_clauses($post_data) {
    $where_clauses = [];
    $param_types = "";
    $param_values = [];

    $search_keyword = $post_data['keyword'] ?? '';
    $start_date = $post_data['start_date'] ?? '';
    $end_date = $post_data['end_date'] ?? '';
    $brand_filter = $post_data['brand'] ?? '';
    $branch_filter = $post_data['branch_id'] ?? '';
    $mc_filter = $post_data['mc_id'] ?? '';
    $project_filter = $post_data['project_name'] ?? '';
    $type_filter = $post_data['type_name'] ?? '';

    if (!empty($search_keyword)) {
        $where_clauses[] = "(u.username LIKE ? OR ma.project_name LIKE ? OR ma.type_name LIKE ? OR ma.outlet_snapshot_name LIKE ? OR s.site_name LIKE ?)";
        $param_types .= "sssss";
        $keyword_like = "%" . $search_keyword . "%";
        array_push($param_values, $keyword_like, $keyword_like, $keyword_like, $keyword_like, $keyword_like);
    }
    if (!empty($start_date)) { $where_clauses[] = "ma.activity_datetime >= ?"; $param_types .= "s"; $param_values[] = $start_date . " 00:00:00"; }
    if (!empty($end_date)) { $where_clauses[] = "ma.activity_datetime <= ?"; $param_types .= "s"; $param_values[] = $end_date . " 23:59:59"; }
    if (!empty($brand_filter)) { $where_clauses[] = "b.brand = ?"; $param_types .= "s"; $param_values[] = $brand_filter; }
    if (!empty($branch_filter)) { $where_clauses[] = "ma.branch_id = ?"; $param_types .= "i"; $param_values[] = (int)$branch_filter; }
    if (!empty($mc_filter)) { $where_clauses[] = "ma.micro_cluster_id = ?"; $param_types .= "i"; $param_values[] = (int)$mc_filter; }
    if (!empty($project_filter)) { $where_clauses[] = "ma.project_name = ?"; $param_types .= "s"; $param_values[] = $project_filter; }
    if (!empty($type_filter)) { $where_clauses[] = "ma.type_name = ?"; $param_types .= "s"; $param_values[] = $type_filter; }

    $where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";
    return ['sql' => $where_sql, 'types' => $param_types, 'values' => $param_values];
}

$mysqli->begin_transaction();
try {
    switch ($action) {
        case 'review_request':
            // **PERBAIKAN**: Validasi input diperkuat untuk memastikan semua data diterima dengan benar.
            if (!isset($_POST['request_id'], $_POST['request_table_type'], $_POST['decision']) ||
                $_POST['request_id'] === '' || $_POST['request_table_type'] === '' || $_POST['decision'] === '') {
                throw new Exception("Data review tidak lengkap. Pastikan semua parameter terkirim.");
            }

            $request_id = (int)$_POST['request_id'];
            $request_table_type = $_POST['request_table_type']; // 'deletion' or 'edit'
            $decision = $_POST['decision']; // 'approve' or 'reject'

            // Perbaikan: Pesan error yang lebih informatif
            if ($request_id <= 0) {
                throw new Exception("ID Permintaan tidak valid. Nilai yang diterima: '" . ($_POST['request_id'] ?? 'NULL/Kosong') . "'");
            }

            $table_name = ($request_table_type === 'deletion') ? 'deletion_requests' : 'matpro_edit_requests';

            $stmt_get_req = $mysqli->prepare("SELECT * FROM {$table_name} WHERE id = ? AND status = 'pending'");
            if (!$stmt_get_req) throw new Exception("Gagal menyiapkan query untuk mengambil data permintaan.");
            $stmt_get_req->bind_param("i", $request_id);
            $stmt_get_req->execute();
            $request_data = $stmt_get_req->get_result()->fetch_assoc();
            $stmt_get_req->close();

            if (!$request_data) {
                throw new Exception("Permintaan tidak ditemukan atau sudah diproses sebelumnya.");
            }

            $activity_id = $request_data['activity_id'];

            if ($decision === 'approve') {
                if ($request_table_type === 'edit') {
                    $stmt_approve = $mysqli->prepare("UPDATE {$table_name} SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                    if (!$stmt_approve) throw new Exception("Gagal menyiapkan query persetujuan.");
                    $stmt_approve->bind_param("ii", $admin_id, $request_id);
                    $stmt_approve->execute();
                    if ($stmt_approve->affected_rows === 0) {
                        throw new Exception("Persetujuan permintaan edit gagal: Tidak ada baris yang terpengaruh. Mungkin status sudah berubah atau ID tidak cocok.");
                    }
                    $stmt_approve->close();
                    $_SESSION['success_message'] = "Permintaan edit telah disetujui. Pengguna kini dapat mengedit aktivitas tersebut.";
                } else { // 'deletion'
                    $stmt_get_activity = $mysqli->prepare("SELECT project_name, type_name, branch_id, micro_cluster_id, qty_used FROM matpro_activities WHERE id = ?");
                    if (!$stmt_get_activity) throw new Exception("Gagal mengambil detail aktivitas untuk pengembalian stok: " . $mysqli->error);
                    $stmt_get_activity->bind_param("i", $activity_id);
                    $stmt_get_activity->execute();
                    $activity = $stmt_get_activity->get_result()->fetch_assoc();
                    $stmt_get_activity->close();

                    if ($activity) {
                        $sql_return_stock = "UPDATE matpro_stocks SET stock_quantity = stock_quantity + ?, last_updated_by = ?, last_updated_at = NOW() WHERE project_name = ? AND type_name = ? AND branch_id = ?";
                        $params_return = [$activity['qty_used'], $admin_id, $activity['project_name'], $activity['type_name'], $activity['branch_id']];
                        $types_return = "iissi"; 

                        if ($activity['micro_cluster_id'] !== null && $activity['micro_cluster_id'] != 0) {
                            $sql_return_stock .= " AND micro_cluster_id = ?";
                            $params_return[] = $activity['micro_cluster_id'];
                            $types_return .= "i";
                        } else {
                            $sql_return_stock .= " AND (micro_cluster_id IS NULL OR micro_cluster_id = 0)";
                        }

                        $stmt_return_stock = $mysqli->prepare($sql_return_stock);
                        if (!$stmt_return_stock) throw new Exception("Gagal menyiapkan query pengembalian stok: " . $mysqli->error);
                        call_user_func_array([$stmt_return_stock, 'bind_param'], array_merge([$types_return], ref_values($params_return)));
                        $stmt_return_stock->execute();
                        if ($stmt_return_stock->affected_rows === 0) {
                            error_log("Pengembalian stok gagal: Tidak ada baris stok yang terpengaruh. Pastikan data stok cocok (Proyek: " . $activity['project_name'] . ", Jenis: " . $activity['type_name'] . ", Branch: " . $activity['branch_id'] . ", MC: " . ($activity['micro_cluster_id'] ?? 'NULL') . "). Melanjutkan penghapusan aktivitas.");
                        }
                        $stmt_return_stock->close();
                    }

                    $stmt_delete_activity = $mysqli->prepare("DELETE FROM matpro_activities WHERE id = ?");
                    if (!$stmt_delete_activity) throw new Exception("Gagal menyiapkan query penghapusan aktivitas.");
                    $stmt_delete_activity->bind_param("i", $activity_id);
                    $stmt_delete_activity->execute();
                    if ($stmt_delete_activity->affected_rows === 0) {
                        error_log("Penghapusan aktivitas gagal: Tidak ada baris aktivitas yang terpengaruh. Mungkin aktivitas sudah dihapus.");
                    }
                    $stmt_delete_activity->close();

                    $stmt_complete = $mysqli->prepare("UPDATE {$table_name} SET status = 'completed', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                    if (!$stmt_complete) throw new Exception("Gagal menyelesaikan permintaan.");
                    $stmt_complete->bind_param("ii", $admin_id, $request_id);
                    $stmt_complete->execute();
                    if ($stmt_complete->affected_rows === 0) {
                        throw new Exception("Penyelesaian permintaan hapus gagal: Tidak ada baris permintaan yang terpengaruh. Mungkin status sudah berubah atau ID tidak cocok.");
                    }
                    $stmt_complete->close();
                    $_SESSION['success_message'] = "Permintaan hapus disetujui. Aktivitas telah dihapus dan stok dikembalikan.";
                }
            } else if ($decision === 'reject') {
                $rejection_reason = trim($_POST['reason'] ?? 'Ditolak oleh admin.');
                $stmt_reject = $mysqli->prepare("UPDATE {$table_name} SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                if (!$stmt_reject) throw new Exception("Gagal menyiapkan query penolakan.");
                $stmt_reject->bind_param("sii", $rejection_reason, $admin_id, $request_id);
                $stmt_reject->execute();
                if ($stmt_reject->affected_rows === 0) {
                    throw new Exception("Penolakan permintaan gagal: Tidak ada baris yang terpengaruh. Mungkin status sudah berubah atau ID tidak cocok.");
                }
                $stmt_reject->close();
                $_SESSION['success_message'] = "Permintaan telah ditolak.";
            }
            break;

        case 'approve_all_requests':
            $approved_deletions = 0;
            $approved_edits = 0;

            // --- 1. Proses semua permintaan HAPUS yang tertunda ---
            $stmt_get_pending_deletions = $mysqli->prepare("SELECT id, activity_id FROM deletion_requests WHERE status = 'pending'");
            if (!$stmt_get_pending_deletions) throw new Exception("Gagal menyiapkan query untuk mengambil permintaan hapus.");
            $stmt_get_pending_deletions->execute();
            $pending_deletions = $stmt_get_pending_deletions->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_get_pending_deletions->close();

            foreach ($pending_deletions as $request) {
                $activity_id = $request['activity_id'];
                $request_id = $request['id'];

                $stmt_get_activity = $mysqli->prepare("SELECT project_name, type_name, branch_id, micro_cluster_id, qty_used FROM matpro_activities WHERE id = ?");
                if (!$stmt_get_activity) throw new Exception("Gagal mengambil detail aktivitas #{$activity_id}.");
                $stmt_get_activity->bind_param("i", $activity_id);
                $stmt_get_activity->execute();
                $activity = $stmt_get_activity->get_result()->fetch_assoc();
                $stmt_get_activity->close();

                if ($activity) {
                    // Kembalikan stok
                    $sql_return_stock = "UPDATE matpro_stocks SET stock_quantity = stock_quantity + ?, last_updated_by = ?, last_updated_at = NOW() WHERE project_name = ? AND type_name = ? AND branch_id = ?";
                    $params_return = [$activity['qty_used'], $admin_id, $activity['project_name'], $activity['type_name'], $activity['branch_id']];
                    $types_return = "iissi";

                    if ($activity['micro_cluster_id'] !== null && $activity['micro_cluster_id'] != 0) {
                        $sql_return_stock .= " AND micro_cluster_id = ?";
                        $params_return[] = $activity['micro_cluster_id'];
                        $types_return .= "i";
                    } else {
                        $sql_return_stock .= " AND (micro_cluster_id IS NULL OR micro_cluster_id = 0)";
                    }
                    $stmt_return_stock = $mysqli->prepare($sql_return_stock);
                    if (!$stmt_return_stock) throw new Exception("Gagal menyiapkan query pengembalian stok untuk aktivitas #{$activity_id}.");
                    call_user_func_array([$stmt_return_stock, 'bind_param'], array_merge([$types_return], ref_values($params_return)));
                    $stmt_return_stock->execute();
                    
                    // FIXED: Throw exception instead of silent failure
                    if ($stmt_return_stock->affected_rows === 0) {
                        error_log("Pengembalian stok gagal untuk aktivitas #{$activity_id}. Stok tidak ditemukan atau data tidak cocok (Proyek: {$activity['project_name']}, Jenis: {$activity['type_name']}, Branch: {$activity['branch_id']}, MC: " . ($activity['micro_cluster_id'] ?? 'NULL') . "). Melanjutkan proses.");
                    }
                    $stmt_return_stock->close();

                    // Hapus aktivitas
                    $stmt_delete_activity = $mysqli->prepare("DELETE FROM matpro_activities WHERE id = ?");
                    if (!$stmt_delete_activity) throw new Exception("Gagal menyiapkan query penghapusan aktivitas #{$activity_id}.");
                    $stmt_delete_activity->bind_param("i", $activity_id);
                    $stmt_delete_activity->execute();
                    $stmt_delete_activity->close();
                } else {
                    error_log("Persetujuan massal: Aktivitas #{$activity_id} tidak ditemukan untuk permintaan hapus #{$request_id}. Menandai permintaan sebagai selesai.");
                }

                // Tandai permintaan sebagai selesai
                $stmt_complete = $mysqli->prepare("UPDATE deletion_requests SET status = 'completed', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                if (!$stmt_complete) throw new Exception("Gagal menyelesaikan permintaan hapus #{$request_id}.");
                $stmt_complete->bind_param("ii", $admin_id, $request_id);
                $stmt_complete->execute();
                if ($stmt_complete->affected_rows > 0) {
                    $approved_deletions++;
                }
                $stmt_complete->close();
            }

            // --- 2. Proses semua permintaan EDIT yang tertunda ---
            $stmt_approve_edits = $mysqli->prepare("UPDATE matpro_edit_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE status = 'pending'");
            if (!$stmt_approve_edits) throw new Exception("Gagal menyiapkan query persetujuan edit massal.");
            $stmt_approve_edits->bind_param("i", $admin_id);
            $stmt_approve_edits->execute();
            $approved_edits = $stmt_approve_edits->affected_rows;
            $stmt_approve_edits->close();

            // --- 3. Atur pesan sesi ---
            $total_approved = $approved_deletions + $approved_edits;
            if ($total_approved > 0) {
                $_SESSION['success_message'] = "Berhasil menyetujui semua permintaan tertunda ({$approved_deletions} hapus & {$approved_edits} edit).";
            } else {
                $_SESSION['success_message'] = "Tidak ada permintaan tertunda yang perlu disetujui.";
            }
            break;
            
        case 'reject_all_requests':
            $rejection_reason = trim($_POST['reason'] ?? '');
            if (empty($rejection_reason)) {
                throw new Exception("Alasan penolakan massal wajib diisi.");
            }

            // Tolak semua permintaan hapus
            $stmt_reject_deletions = $mysqli->prepare("UPDATE deletion_requests SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE status = 'pending'");
            if (!$stmt_reject_deletions) throw new Exception("Gagal menyiapkan query penolakan massal untuk permintaan hapus.");
            $stmt_reject_deletions->bind_param("si", $rejection_reason, $admin_id);
            $stmt_reject_deletions->execute();
            $rejected_deletions = $stmt_reject_deletions->affected_rows;
            $stmt_reject_deletions->close();
            
            // Tolak semua permintaan edit
            $stmt_reject_edits = $mysqli->prepare("UPDATE matpro_edit_requests SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE status = 'pending'");
            if (!$stmt_reject_edits) throw new Exception("Gagal menyiapkan query penolakan massal untuk permintaan edit.");
            $stmt_reject_edits->bind_param("si", $rejection_reason, $admin_id);
            $stmt_reject_edits->execute();
            $rejected_edits = $stmt_reject_edits->affected_rows;
            $stmt_reject_edits->close();

            $total_rejected = $rejected_deletions + $rejected_edits;
            if ($total_rejected > 0) {
                $_SESSION['success_message'] = "Berhasil menolak semua {$total_rejected} permintaan yang tertunda.";
            } else {
                $_SESSION['success_message'] = "Tidak ada permintaan tertunda yang perlu ditolak.";
            }
            break;

        case 'export_filtered':
            require_once '../vendor/autoload.php';

            $filters = build_where_clauses($_POST);

            $sql = "SELECT ma.unique_id, ma.activity_datetime, u.username, u.nama as user_nama,
                           ma.project_name, b.brand, ma.type_name,
                           ma.qty_used, b.nama_branch, mc.nama_micro_cluster,
                           s.site_name, s.site_id as site_code,
                           COALESCE(o.Id_Outlet_Nama_Outlet, ma.outlet_snapshot_name, 'Outlet Telah Dihapus') as outlet_display_name,
                           s.kecamatan, s.kabupaten, ma.location_latitude, ma.location_longitude,
                           ma.photo_before_url, ma.photo_after_url
                    FROM matpro_activities ma
                    JOIN users u ON ma.user_id = u.id
                    JOIN branches b ON ma.branch_id = b.id
                    LEFT JOIN micro_clusters mc ON ma.micro_cluster_id = mc.id
                    JOIN sites s ON ma.site_id = s.id
                    LEFT JOIN outlets o ON ma.outlet_id = o.id"
                    . $filters['sql'] . " ORDER BY ma.activity_datetime DESC";

            $stmt = $mysqli->prepare($sql);
            if (!$stmt) die("Error preparing query: " . $mysqli->error);

            if (!empty($filters['values'])) {
                call_user_func_array([$stmt, 'bind_param'], array_merge([$filters['types']], ref_values($filters['values'])));
            }
            $stmt->execute();
            $result = $stmt->get_result();

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Laporan Aktivitas Matpro');

            $headers = ['ID Aktivitas', 'Waktu', 'Username', 'Nama User', 'Proyek', 'Brand', 'Jenis Matpro', 'QTY', 'Branch', 'Micro Cluster', 'Site Name', 'Site Code', 'Outlet', 'Kecamatan', 'Kabupaten', 'Latitude', 'Longitude', 'URL Foto Sebelum', 'URL Foto Sesudah'];
            $sheet->fromArray($headers, NULL, 'A1');

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $domain_name = $_SERVER['HTTP_HOST'];
            $base_url = $protocol . "://" . $domain_name;

            $rowNum = 2;
            while ($row = $result->fetch_assoc()) {
                
                $photo_before_full_url = '';
                if (!empty($row['photo_before_url']) && !preg_match('/^https?:\/\//', $row['photo_before_url'])) {
                    $photo_before_full_url = $base_url . $row['photo_before_url'];
                } else {
                    $photo_before_full_url = $row['photo_before_url'];
                }

                $photo_after_full_url = '';
                if (!empty($row['photo_after_url']) && !preg_match('/^https?:\/\//', $row['photo_after_url'])) {
                    $photo_after_full_url = $base_url . $row['photo_after_url'];
                } else {
                    $photo_after_full_url = $row['photo_after_url'];
                }

                $rowData = [
                    $row['unique_id'], $row['activity_datetime'], $row['username'], $row['user_nama'],
                    $row['project_name'], $row['brand'], $row['type_name'],
                    $row['qty_used'], $row['nama_branch'], $row['nama_micro_cluster'] ?? '-',
                    $row['site_name'], $row['site_code'], $row['outlet_display_name'],
                    $row['kecamatan'], $row['kabupaten'], $row['location_latitude'], $row['location_longitude'],
                    $photo_before_full_url,
                    $photo_after_full_url
                ];

                $sheet->fromArray($rowData, NULL, 'A' . $rowNum);
                $rowNum++;
            }

            $stmt->close();
            $mysqli->commit(); // Commit transaksi read-only
            $mysqli->close();

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="Laporan_Aktivitas_Matpro_' . date('Ymd_His') . '.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;

        case 'delete':
        case 'bulk_delete':
            if ($action === 'delete') {
                $ids_to_delete = [(int)($_POST['delete_single'] ?? 0)];
                if (empty($ids_to_delete[0])) throw new Exception("ID aktivitas tidak valid.");
            } else { // bulk_delete
                $activity_ids = $_POST['activity_ids'] ?? [];
                if (empty($activity_ids)) throw new Exception("Tidak ada aktivitas yang dipilih.");
                $ids_to_delete = array_map('intval', $activity_ids);
            }

            foreach($ids_to_delete as $activity_id) {
                $stmt_get_activity = $mysqli->prepare("SELECT project_name, type_name, branch_id, micro_cluster_id, qty_used, photo_before_url, photo_after_url FROM matpro_activities WHERE id = ?");
                $stmt_get_activity->bind_param("i", $activity_id);
                $stmt_get_activity->execute();
                $activity = $stmt_get_activity->get_result()->fetch_assoc();
                $stmt_get_activity->close();

                if ($activity) {
                    // Kembalikan stok
                    $sql_return_stock = "UPDATE matpro_stocks SET stock_quantity = stock_quantity + ?, last_updated_by = ?, last_updated_at = NOW() WHERE project_name = ? AND type_name = ? AND branch_id = ?";
                    $params_return = [$activity['qty_used'], $admin_id, $activity['project_name'], $activity['type_name'], $activity['branch_id']];
                    $types_return = "iissi";

                    if ($activity['micro_cluster_id'] !== null && $activity['micro_cluster_id'] != 0) {
                        $sql_return_stock .= " AND micro_cluster_id = ?";
                        $types_return .= "i";
                        $params_return[] = $activity['micro_cluster_id'];
                    } else {
                        $sql_return_stock .= " AND (micro_cluster_id IS NULL OR micro_cluster_id = 0)";
                    }
                    $stmt_return = $mysqli->prepare($sql_return_stock);
                    if (!$stmt_return) throw new Exception("Gagal menyiapkan statement pengembalian stok: " . $mysqli->error);
                    call_user_func_array([$stmt_return, 'bind_param'], array_merge([$types_return], ref_values($params_return)));
                    $stmt_return->execute();
                    if ($stmt_return->affected_rows === 0) {
                        error_log("Pengembalian stok untuk aktivitas ID {$activity_id} tidak memengaruhi baris apa pun. Stok mungkin tidak ditemukan. (Proyek: " . $activity['project_name'] . ", Jenis: " . $activity['type_name'] . ", Branch: " . $activity['branch_id'] . ", MC: " . ($activity['micro_cluster_id'] ?? 'NULL') . ")");
                    }
                    $stmt_return->close();
                }
            }

            $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
            $types_string = str_repeat('i', count($ids_to_delete));
            $sql_delete = "DELETE FROM matpro_activities WHERE id IN ($placeholders)";
            $stmt_delete = $mysqli->prepare($sql_delete);
            $stmt_delete->bind_param($types_string, ...$ids_to_delete);
            $stmt_delete->execute();
            $deleted_count = $stmt_delete->affected_rows;
            if ($deleted_count === 0) {
                throw new Exception("Penghapusan aktivitas massal gagal: Tidak ada baris yang terpengaruh. Mungkin aktivitas sudah dihapus.");
            }
            $stmt_delete->close();

            $_SESSION['success_message'] = "$deleted_count aktivitas berhasil dihapus dan stok dikembalikan.";
            break;

        case 'edit_date_activity': // Admin: edit single activity date
            $act_id   = (int)($_POST['activity_id'] ?? 0);
            $new_date = $mysqli->real_escape_string($_POST['new_date'] ?? '');
            $new_time = $mysqli->real_escape_string($_POST['new_time'] ?? '00:00:00');
            if ($act_id <= 0 || empty($new_date)) throw new Exception("ID atau tanggal tidak valid.");
            $new_dt = $new_date . ' ' . $new_time;
            if (!$mysqli->query("UPDATE matpro_activities SET activity_datetime = '$new_dt' WHERE id = $act_id")) {
                throw new Exception("Gagal mengubah tanggal: " . $mysqli->error);
            }
            $_SESSION['success_message'] = "Tanggal aktivitas berhasil diubah.";
            break;

        case 'bulk_edit_date_activities': // Admin: bulk edit activity dates
            $act_ids  = $_POST['activity_ids'] ?? [];
            $new_date_b = $mysqli->real_escape_string($_POST['new_date'] ?? '');
            $new_time_b = $mysqli->real_escape_string($_POST['new_time'] ?? '00:00:00');
            if (empty($act_ids)) throw new Exception("Tidak ada aktivitas yang dipilih.");
            if (empty($new_date_b)) throw new Exception("Tanggal baru tidak boleh kosong.");
            $safe_act_ids = array_map('intval', $act_ids);
            $id_list_act  = implode(',', $safe_act_ids);
            $new_dt_b = $new_date_b . ' ' . $new_time_b;
            if (!$mysqli->query("UPDATE matpro_activities SET activity_datetime = '$new_dt_b' WHERE id IN ($id_list_act)")) {
                throw new Exception("Gagal mengubah tanggal secara massal: " . $mysqli->error);
            }
            $_SESSION['success_message'] = $mysqli->affected_rows . " aktivitas berhasil diubah tanggalnya.";
            break;

        default:
            throw new Exception("Aksi tidak valid.");
            break;
    }

    $mysqli->commit();
} catch (Exception $e) {
    $mysqli->rollback();
    $_SESSION['error_message'] = "Terjadi kesalahan: " . $e->getMessage();
}

header("location: ../admin_laporan_matpro.php");
exit();
