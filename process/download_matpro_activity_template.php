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
$headers = [
    'Nama Proyek',          // A - Digunakan untuk mencari project_id
    'Nama Jenis Matpro',    // B - Digunakan untuk mencari type_id
    'Nama Branch',          // C - Digunakan untuk mencari branch_id
    'Nama Micro Cluster (Opsional)', // D - Digunakan untuk mencari micro_cluster_id
    'Site ID Code',         // E - Digunakan untuk mencari site_id
    'ID Outlet',            // F - Digunakan untuk mencari outlet_id
    'QTY Digunakan',        // G - QTY Digunakan
    'Latitude',             // H
    'Longitude',            // I
    'Nama File Foto Sebelum', // J - Nama file di dalam ZIP
    'Nama File Foto Sesudah'  // K - Nama file di dalam ZIP
];

$sheet->fromArray($headers, NULL, 'A1');

// Contoh data
$sheet->setCellValue('A2', 'Project A');
$sheet->setCellValue('B2', 'Jenis X');
$sheet->setCellValue('C2', 'BALI BARAT');
$sheet->setCellValue('D2', 'MC-BALI BARAT');
$sheet->setCellValue('E2', 'SITE-001');
$sheet->setCellValue('F2', 'O001');
$sheet->setCellValue('G2', '1');
$sheet->setCellValue('H2', '-8.650000');
$sheet->setCellValue('I2', '115.210000');
$sheet->setCellValue('J2', 'contoh_foto_sebelum.jpg');
$sheet->setCellValue('K2', 'contoh_foto_sesudah.jpg');

foreach (range('A', Coordinate::stringFromColumnIndex(count($headers))) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Tulis Excel ke temporary file
$writer = new Xlsx($spreadsheet);
$temp_excel = tempnam(sys_get_temp_dir(), 'excel');
$writer->save($temp_excel);

// Buat dummy image
$temp_img_before = tempnam(sys_get_temp_dir(), 'imgb') . '.jpg';
$temp_img_after = tempnam(sys_get_temp_dir(), 'imga') . '.jpg';

$img = imagecreatetruecolor(200, 200);
$bg = imagecolorallocate($img, 100, 149, 237); // Cornflower blue
$tc = imagecolorallocate($img, 255, 255, 255);
imagefill($img, 0, 0, $bg);
imagestring($img, 5, 20, 90, 'Foto Sebelum', $tc);
imagejpeg($img, $temp_img_before, 90);

$img2 = imagecreatetruecolor(200, 200);
$bg2 = imagecolorallocate($img2, 46, 139, 87); // SeaGreen
imagefill($img2, 0, 0, $bg2);
imagestring($img2, 5, 20, 90, 'Foto Sesudah', $tc);
imagejpeg($img2, $temp_img_after, 90);

imagedestroy($img);
imagedestroy($img2);

// Buat ZIP file
$zip_filename = 'Template_Lengkap_Import_Matpro.zip';
$temp_zip = tempnam(sys_get_temp_dir(), 'zip');

$zip = new ZipArchive();
if ($zip->open($temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    // Tambahkan Excel dengan nama yang diwajibkan sistem
    $zip->addFile($temp_excel, 'data_aktivitas_matpro.xlsx');
    // Tambahkan dummy foto
    $zip->addFile($temp_img_before, 'contoh_foto_sebelum.jpg');
    $zip->addFile($temp_img_after, 'contoh_foto_sesudah.jpg');
    $zip->close();
}

// Hapus temporary file selain zip
unlink($temp_excel);
unlink($temp_img_before);
unlink($temp_img_after);

// Kirim ZIP ke browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
header('Content-Length: ' . filesize($temp_zip));
header('Pragma: no-cache');
header('Expires: 0');

readfile($temp_zip);
unlink($temp_zip);
exit();
