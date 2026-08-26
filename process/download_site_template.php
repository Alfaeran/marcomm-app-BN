<?php
// process/download_site_template.php
// Skrip ini membuat dan mengirimkan file template Excel untuk diunduh.

require_once '../vendor/autoload.php'; // Sesuaikan path jika perlu

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Template Import Site');

// PERBAIKAN: Headers untuk template Excel Site disesuaikan dengan urutan kolom database Anda
// Urutan DB: site_id, site_name, brand, branch_id, micro_cluster_id, area, kabupaten, kecamatan, region
$headers = [
    'Site ID',          // A
    'Site Name',        // B
    'Brand',            // C
    'Nama Branch',      // D (nama branch untuk lookup ID)
    'Micro Cluster',    // E (nama micro cluster untuk lookup ID)
    'Area',             // F
    'Kabupaten',        // G
    'Kecamatan',        // H
    'Region'            // I
];

$sheet->fromArray($headers, NULL, 'A1'); // Tulis header mulai dari A1

// Beri contoh data untuk panduan (sesuaikan agar cocok dengan urutan baru)
// PERBAIKAN: Contoh data untuk 'kecamatan' disesuaikan agar lebih relevan secara geografis.
$sheet->setCellValue('A2', '16BAT0013_IM3'); // Contoh site_id
$sheet->setCellValue('B2', 'BATU_BAT3_MT'); // Contoh site_name
$sheet->setCellValue('C2', 'IM3'); // Brand
$sheet->setCellValue('D2', 'BALI BARAT'); // Nama Branch (akan di-lookup ke ID)
$sheet->setCellValue('E2', 'MC-BALI BARAT'); // Nama Micro Cluster (akan di-lookup ke ID)
$sheet->setCellValue('F2', 'KOTA BATU'); // Area
$sheet->setCellValue('G2', 'BATU'); // Kabupaten
$sheet->setCellValue('H2', 'Bumiaji'); // Kecamatan (Contoh nama kecamatan yang lebih umum)
$sheet->setCellValue('I2', 'EASTERN REGION'); // Region

$sheet->setCellValue('A3', '16BAT0011_IM3');
$sheet->setCellValue('B3', 'SIDOMULYOMLG_MT');
$sheet->setCellValue('C3', 'IM3');
$sheet->setCellValue('D3', 'BALI BARAT');
$sheet->setCellValue('E3', 'MC-BALI BARAT');
$sheet->setCellValue('F3', 'KOTA BATU');
$sheet->setCellValue('G3', 'BATU');
$sheet->setCellValue('H3', 'Junrejo'); // Contoh nama kecamatan yang lebih umum
$sheet->setCellValue('I3', 'EASTERN REGION');

// Atur lebar kolom otomatis
foreach (range('A', Coordinate::stringFromColumnIndex(count($headers))) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --- Mengirim File ke Browser untuk Diunduh ---
$filename = 'template_import_site.xlsx';

// Atur header HTTP untuk file download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Buat file dan kirim ke output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
