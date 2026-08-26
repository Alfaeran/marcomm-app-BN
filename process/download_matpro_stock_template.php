<?php
// process/download_matpro_activity_template.php
// Skrip ini membuat dan mengirimkan file template Excel untuk diunduh.

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Template Aktivitas Matpro');

// Headers untuk template Excel Aktivitas Matpro
// Sesuaikan dengan kolom yang relevan di matpro_activities dan lookup
$headers = [
    'Username User Input',  // A - Digunakan untuk mencari user_id
    'Nama Proyek',          // B - Digunakan untuk mencari project_id
    'Nama Jenis Matpro',    // C - Digunakan untuk mencari type_id
    'Nama Branch',          // D - Digunakan untuk mencari branch_id
    'Nama Micro Cluster (Opsional)', // E - Digunakan untuk mencari micro_cluster_id
    'Site ID Code',         // F - Digunakan untuk mencari site_id (site_id dari tabel sites)
    'ID Outlet',            // G - Digunakan untuk mencari outlet_id (id_outlet dari tabel outlets)
    'QTY Digunakan',        // H - Quantity
    'Latitude',             // I
    'Longitude',            // J
    'Nama File Foto Sebelum', // K - Nama file di dalam ZIP (misal: foto1.jpg)
    'Nama File Foto Sesudah'  // L - Nama file di dalam ZIP (misal: foto2.jpg)
];

$sheet->fromArray($headers, NULL, 'A1'); // Tulis header mulai dari A1

// Beri contoh data untuk panduan (opsional, tapi sangat membantu)
$sheet->setCellValue('A2', 'user_test');
$sheet->setCellValue('B2', 'Project A');
$sheet->setCellValue('C2', 'Jenis X');
$sheet->setCellValue('D2', 'BALI BARAT');
$sheet->setCellValue('E2', 'MC-BALI BARAT');
$sheet->setCellValue('F2', 'SITE-001');
$sheet->setCellValue('G2', 'O001');
$sheet->setCellValue('H2', '1');
$sheet->setCellValue('I2', '-8.650000');
$sheet->setCellValue('J2', '115.210000');
$sheet->setCellValue('K2', 'foto_sebelum_1.jpg');
$sheet->setCellValue('L2', 'foto_sesudah_1.jpg');

// Atur lebar kolom otomatis
foreach (range('A', Coordinate::stringFromColumnIndex(count($headers))) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --- Mengirim File ke Browser untuk Diunduh ---
$filename = 'template_import_aktivitas_matpro.xlsx';

// Atur header HTTP untuk file download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Buat file dan kirim ke output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
