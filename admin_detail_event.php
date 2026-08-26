<?php
require_once 'config/database.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    // Bisa redirect atau hentikan proses
    $_SESSION['error_message'] = "Akses ditolak.";
    header("location: ../login.php");
    exit;
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("location: dashboard_user.php");
    exit;
}

$event_id = (int)$_GET['id'];
$user_id = $_SESSION['id'];
$user_role = $_SESSION['role'];

// Query untuk mengambil detail event
$sql_event = "SELECT e.*, u.username, u.nama, s.site_id as site_code, s.site_name, cat.nama_kategori
              FROM event_submissions e
              JOIN users u ON e.user_id = u.id
              LEFT JOIN sites s ON e.site_id = s.id
              LEFT JOIN event_categories cat ON e.kategori_event_id = cat.id
              WHERE e.unique_id = ?";
// Jika bukan admin, pastikan dia hanya bisa melihat event miliknya
if ($user_role !== 'admin') {
    $sql_event .= " AND e.user_id = ?";
}
$stmt_event = $mysqli->prepare($sql_event);
if ($user_role !== 'admin') {
    $stmt_event->bind_param("ii", $event_id, $user_id);
} else {
    $stmt_event->bind_param("i", $event_id);
}
$stmt_event->execute();
$result_event = $stmt_event->get_result();
if ($result_event->num_rows !== 1) {
    die("Event tidak ditemukan atau Anda tidak memiliki izin.");
}
$event = $result_event->fetch_assoc();
$stmt_event->close();

// Query untuk mengambil data MSISDN unik yang berhasil diinput
$sql_msisdn = "SELECT msisdn FROM msisdn_data WHERE submission_id = ?";
$stmt_msisdn = $mysqli->prepare($sql_msisdn);
$stmt_msisdn->bind_param("i", $event_id);
$stmt_msisdn->execute();
$msisdns = $stmt_msisdn->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_msisdn->close();

// Query BARU untuk mengambil log MSISDN duplikat
$sql_duplicates = "SELECT 
                        d.duplicate_msisdn,
                        orig_e.event_name AS original_event_name,
                        orig_e.waktu_input AS original_waktu_input,
                        orig_u.username AS original_username
                   FROM duplicate_msisdn_log d
                   JOIN event_submissions orig_e ON d.original_submission_id = orig_e.unique_id
                   JOIN users orig_u ON orig_e.user_id = orig_u.id
                   WHERE d.new_submission_id = ?";
$stmt_duplicates = $mysqli->prepare($sql_duplicates);
$stmt_duplicates->bind_param("i", $event_id);
$stmt_duplicates->execute();
$duplicates = $stmt_duplicates->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_duplicates->close();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Event: <?php echo htmlspecialchars($event['event_name']); ?></title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-5xl mx-auto bg-white p-8 rounded-lg shadow-lg">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-800"><?php echo htmlspecialchars($event['event_name']); ?></h1>
            <p class="text-gray-500">Diinput oleh: <?php echo htmlspecialchars($event['username']); ?> pada <?php echo date('d F Y, H:i', strtotime($event['waktu_input'])); ?></p>
        </div>
        <a href="<?php echo ($user_role == 'admin') ? 'admin_laporan_event.php' : 'dashboard_user.php'; ?>" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg">
            Kembali
        </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Kolom Kiri: Foto & MSISDN -->
        <div class="md:col-span-1">
            <h3 class="text-xl font-semibold mb-4">Dokumentasi</h3>
           <img src="./<?php echo htmlspecialchars($event['foto_event_url']); ?>" alt="Foto Event" class="w-full rounded-lg shadow-md mb-6">
			
            
            <h3 class="text-xl font-semibold mb-4">MSISDN Berhasil Diinput (<?php echo count($msisdns); ?>)</h3>
            <div class="h-48 overflow-y-auto border rounded p-2 bg-gray-50 mb-6">
                <ul class="list-disc list-inside text-gray-700">
                    <?php foreach ($msisdns as $msisdn): ?>
                        <li><?php echo htmlspecialchars($msisdn['msisdn']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if (!empty($duplicates)): ?>
            <h3 class="text-xl font-semibold mb-4 text-red-600">Log MSISDN Duplikat (<?php echo count($duplicates); ?>)</h3>
            <div class="h-48 overflow-y-auto border border-red-300 rounded p-2 bg-red-50">
                <ul class="space-y-2">
                    <?php foreach ($duplicates as $dup): ?>
                        <li class="text-sm">
                            <strong class="font-mono"><?php echo htmlspecialchars($dup['duplicate_msisdn']); ?></strong>
                            <span class="text-red-700">sudah ada di event</span> 
                            "<?php echo htmlspecialchars($dup['original_event_name']); ?>" 
                            <span class="text-gray-500">(oleh <?php echo htmlspecialchars($dup['original_username']); ?> pada <?php echo date('d/m/y', strtotime($dup['original_waktu_input'])); ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <!-- Kolom Kanan: Detail Data -->
        <div class="md:col-span-2">
            <div class="grid grid-cols-2 gap-x-8 gap-y-4">
                <?php 
                function display_detail($label, $value, $is_currency = false) {
                    $formatted_value = $is_currency ? 'Rp ' . number_format($value, 0, ',', '.') : htmlspecialchars($value);
                    echo "<div><p class='text-sm text-gray-500'>$label</p><p class='font-semibold text-lg'>$formatted_value</p></div>";
                }
                
                display_detail("Kategori Event", $event['nama_kategori']);
                display_detail("Site Name", $event['site_name'] . ' (' . $event['site_code'] . ')');
                display_detail("Jumlah Audience", $event['jumlah_audience']);
                display_detail("Jumlah QSC", $event['jumlah_qsc']);
                display_detail("Benefit SP", $event['benefit_sp'], true);
                display_detail("Mobo/Paket", $event['mobo_paket'], true);
                display_detail("Cost", $event['cost'], true);
                display_detail("Benefit Total", $event['benefit_total'], true);
                display_detail("Ratio Cost/Benefit", number_format($event['ratio_cost_benefit'], 2) . ' %');
                ?>
            </div>
            <hr class="my-6">
            <h3 class="text-xl font-semibold mb-4">Detail Survey</h3>
            <div class="grid grid-cols-2 gap-x-8 gap-y-4">
                <?php
                display_detail("Provider Digunakan", $event['provider_digunakan']);
                display_detail("Provider Sinyal Terbaik", $event['provider_terbaik']);
                display_detail("Mengenal IM3?", $event['kenal_im3']);
                display_detail("Sudah Beli IM3?", $event['sudah_beli_im3']);
                if ($event['sudah_beli_im3'] == 'Ya') {
                    display_detail("Lokasi Beli", $event['lokasi_beli']);
                } else {
                    display_detail("Tertarik Beli?", $event['tertarik_beli_im3']);
                }
                ?>
            </div>
             <hr class="my-6">
             <h3 class="text-xl font-semibold mb-4">Alasan / Feedback</h3>
             <div class="p-4 border rounded bg-gray-50">
                <p class="text-gray-700"><?php echo !empty($event['alasan']) ? nl2br(htmlspecialchars($event['alasan'])) : '<em>Tidak ada feedback.</em>'; ?></p>
             </div>
        </div>
    </div>
</div>
</body>
</html>
