<?php
// process/download_msisdn_template.php

ob_start(); // Buffer output
error_log("Event Download: Starting download_msisdn_template.php");
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Headers untuk template Excel MSISDN (DIUPDATE: TAMBAH KOLOM TYPE)
$headers = [
    'MSISDN',
    'Type (existing/new)' // KOLOM BARU UNTUK TIPE MSISDN
];

// Tulis headers ke sheet
$col = 1;
foreach ($headers as $header) {
    $columnLetter = Coordinate::stringFromColumnIndex($col);
    $sheet->setCellValue($columnLetter . '1', $header);
    $col++;
}

// Tambahkan contoh data
$sheet->setCellValue('A2', '081234567890');
$sheet->setCellValue('B2', 'new');
$sheet->setCellValue('A3', '6281234567891');
$sheet->setCellValue('B3', 'existing');
$sheet->setCellValue('A4', '81234567892');
$sheet->setCellValue('B4', 'new');


// Atur lebar kolom agar terlihat rapi
foreach (range('A', Coordinate::stringFromColumnIndex(count($headers))) as $columnID) {
    $sheet->getColumnDimension($columnID)->setAutoSize(true);
}

// Buat Writer
$writer = new Xlsx($spreadsheet);

// Atur header untuk download
error_log("Event Download: Sending MSISDN XLSX file");
while (ob_get_level()) ob_end_clean(); // Clear ALL buffers
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="msisdn_template.xlsx"');
header('Cache-Control: max-age=0');

// Tulis file ke output PHP
$writer->save('php://output');
exit;
