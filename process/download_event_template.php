<?php
// D:\laragon\www\marcomm_bn\process\download_event_template.php

require_once '../vendor/autoload.php'; // Sesuaikan path jika perlu

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate; // Tambahkan baris ini

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Headers untuk template Excel Event (SESUAIKAN DENGAN PEMETAAN DI bulk_import_event.php)
$headers = [
    'Nama Event',
    'Kategori Event', // Nama Kategori, e.g., "Musik", "Olahraga"
    'Site ID',        // ID unik dari tabel `sites`, e.g., "SITE-123", "BALI-001"
    'Nama Branch',    // Nama Branch, e.g., "BALI BARAT", "LOMBOK TIMUR"
    'Nama Micro Cluster', // Nama Micro Cluster, e.g., "MC-BALI BARAT"
    'Latitude',
    'Longitude',
    'SP 0K',
    'SP 3GB',
    'SP 5GB',
    'SP 7GB',
    'SP 100GB',
    'FWA',
    'FWA 5G',
    'HIT Haji/Umroh',
    'Reload',
    'Mobo/Paket',
    'Cost',
    'Alasan / Feedback',
    'Nama File Foto', // Nama file foto di dalam ZIP (e.g., foto_event_1.jpg)
    'Nama File MSISDN' // Nama file MSISDN di dalam ZIP (e.g., msisdn_event_1.xlsx)
];

// Set header
$columnIndex = 1;
foreach ($headers as $header) {
    $column = Coordinate::stringFromColumnIndex($columnIndex);
    $sheet->setCellValue($column . '1', $header);
    // Atur lebar kolom agar terlihat rapi
    $sheet->getColumnDimension($column)->setAutoSize(true);
    $columnIndex++;
}

// Tambahkan beberapa baris data contoh (opsional)
// $sample_data = [
//     'Contoh Event 1', 'Musik', 'SITE-123', 'BALI BARAT', 'MC-BALI BARAT', '-8.65000000', '115.21000000', 10, 5, 5, 2, 1, 0, 10, 50, 20, 100000.00, 'Cuaca mendukung, tapi lokasi kurang strategis.', 'foto_event_1.jpg', 'msisdn_event_1.xlsx'
// ];
// $sheet->fromArray($sample_data, NULL, 'A2');

$writer = new Xlsx($spreadsheet);
$filename = 'template_event_import_' . date('Ymd_His') . '.xlsx';

// Atur header untuk download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
// Jika Anda menggunakan IE 9 atau yang lebih lama
header('Cache-Control: max-age=1');

// Jika Anda menggunakan IE dengan SSL
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
header('Pragma: public'); // HTTP/1.0

// Kosongkan output buffer sebelum menulis file
// ob_end_clean(); // Hanya gunakan jika output buffer sudah diaktifkan

$writer->save('php://output');
exit;
