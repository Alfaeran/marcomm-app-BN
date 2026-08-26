<?php
// process/download_outlet_template.php
// Skrip ini membuat dan mengirimkan file template Excel untuk diunduh.

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Template Import Outlet');

// Headers untuk template Excel Outlet
// Disesuaikan: Menghapus Organization ID/Name, memastikan ID Outlet dan Nama Outlet
$headers = [
    'ID Outlet',            // A - Corresponds to `id_outlet` in DB
    'Nama Outlet',          // B - Corresponds to `nama_outlet` in DB
    'Site ID Code',         // C - Used to lookup `site_id` (from `sites` table's `site_id` column)
    'Brand'                 // D - Corresponds to `brand` in DB (IM3, 3ID, atau BOTH)
];

$sheet->fromArray($headers, NULL, 'A1'); // Tulis header mulai dari A1

// Beri contoh data untuk panduan (opsional, tapi sangat membantu)
$sheet->setCellValue('A2', 'O-JKT-001');
$sheet->setCellValue('B2', 'Outlet Denpasar Barat');
$sheet->setCellValue('C2', 'SITE-001'); // Contoh Site ID Code yang sudah ada di tabel 'sites'
$sheet->setCellValue('D2', 'IM3');

$sheet->setCellValue('A3', 'O-BDG-002');
$sheet->setCellValue('B3', 'Outlet Bandung Raya');
$sheet->setCellValue('C3', 'SITE-002');
$sheet->setCellValue('D3', '3ID');

$sheet->setCellValue('A4', 'O-BOTH-003');
$sheet->setCellValue('B4', 'Outlet Both Brand');
$sheet->setCellValue('C4', 'SITE-003');
$sheet->setCellValue('D4', 'BOTH');


// Atur lebar kolom otomatis
foreach (range('A', Coordinate::stringFromColumnIndex(count($headers))) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --- Mengirim File ke Browser untuk Diunduh ---
$filename = 'template_import_outlet.xlsx';

// Atur header HTTP untuk file download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Buat file dan kirim ke output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
