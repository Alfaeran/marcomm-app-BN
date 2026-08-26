<?php
// file: process/admin_event_process.php
require_once '../config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'edit') {
    $unique_id = (int)$_POST['unique_id'];
    $admin_id = $_SESSION['id'];
    $admin_username = $_SESSION['username'];
    
    // Ambil data dari POST
    $data_to_update = [
        'event_name' => $_POST['event_name'],
        'kategori_event_id' => (int)$_POST['kategori_event_id'],
        'location_latitude' => $_POST['latitude'],
        'location_longitude' => $_POST['longitude'],
        'sp_0k' => (int)$_POST['sp_0k'],
        'sp_3gb' => (int)$_POST['sp_3gb'],
        'sp_5gb' => (int)$_POST['sp_5gb'],
        'sp_7gb' => (int)$_POST['sp_7gb'],
        'sp_100gb' => (int)($_POST['sp_100gb'] ?? 0),
        'fwa' => (int)($_POST['fwa'] ?? 0),
        'fwa_5g' => (int)($_POST['fwa_5g'] ?? 0),
        'hit_haji_umroh' => (int)($_POST['hit_haji_umroh'] ?? 0),
        'reload' => (int)$_POST['reload'],
        'mobo_paket' => (int)$_POST['mobo_paket'],
        'cost' => (float)$_POST['cost'],
        'alasan' => $_POST['alasan'] ?? ''
    ];

    // Recalculate Benefit and Ratio
    $data_to_update['jumlah_qsc'] = 
        $data_to_update['sp_0k'] + 
        $data_to_update['sp_3gb'] + 
        $data_to_update['sp_5gb'] + 
        $data_to_update['sp_7gb'] + 
        $data_to_update['sp_100gb'];

    $benefit_sp = (
        ($data_to_update['sp_0k'] * 10000) + 
        ($data_to_update['sp_3gb'] * 27000) + 
        ($data_to_update['sp_5gb'] * 35000) + 
        ($data_to_update['sp_7gb'] * 39000)
    );

    $benefit_fwa = ($data_to_update['fwa'] * 150000) + ($data_to_update['fwa_5g'] * 750000);

    $benefit_total = (
        $benefit_sp + 
        $benefit_fwa + 
        (float)$data_to_update['mobo_paket']
    );

    $data_to_update['benefit_sp'] = $benefit_sp;
    $data_to_update['benefit_fwa'] = $benefit_fwa;
    $data_to_update['benefit_total'] = $benefit_total;
    
    $cost = $data_to_update['cost'];
    $ratio_cost_benefit = ($benefit_total > 0) ? round(($cost / $benefit_total) * 100, 2) : 0.00;
    $data_to_update['ratio_cost_benefit'] = $ratio_cost_benefit;

    // Build update query
    $update_parts = [];
    foreach ($data_to_update as $col => $val) {
        $update_parts[] = "$col = ?";
    }
    $sql = "UPDATE event_submissions SET " . implode(', ', $update_parts) . " WHERE unique_id = ?";
    
    $stmt = $mysqli->prepare($sql);
    
    // Dynamically bind params
    $types = str_repeat('s', count($data_to_update)) . 'i'; 
    $params = array_values($data_to_update);
    $params[] = $unique_id;
    
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        log_activity($mysqli, $admin_id, $admin_username, 'EVENT_EDIT_ADMIN', "Admin mengedit event ID: $unique_id - Nama: {$data_to_update['event_name']}");
        $_SESSION['success_message'] = "Event berhasil diperbarui oleh Admin.";
    } else {
        $_SESSION['error_message'] = "Gagal memperbarui event: " . $stmt->error;
    }
    
    $stmt->close();
    header("location: ../admin_detail_event.php?id=$unique_id");
    exit;
} else {
    header("location: ../admin_laporan_event.php");
    exit;
}
