<?php
ob_start(); // Buffer output
ini_set('display_errors', 1);
error_reporting(E_ALL);
error_log("Event Download: Starting download_bulk_import_example.php");
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Create temporary directory
$temp_dir = sys_get_temp_dir() . '/bulk_import_example_' . uniqid();
mkdir($temp_dir, 0777, true);

try {
    // ===== 1. CREATE MAIN EXCEL FILE =====
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Headers (sesuai dengan yang dibutuhkan sistem)
    $headers = [
        'Nama Event', 'Kategori Event', 'Site ID', 'Nama Branch', 'Nama Micro Cluster',
        'Latitude', 'Longitude', 'SP 0K', 'SP 3GB', 'SP 5GB', 'SP 7GB', 'SP 100GB',
        'FWA', 'FWA 5G', 'HIT Haji/Umroh', 'Reload', 'Mobo/Paket', 'Cost',
        'Alasan/Feedback', 'Nama File Foto', 'Nama File MSISDN'
    ];
    
    // Write headers
    $col = 1;
    foreach ($headers as $header) {
        $sheet->setCellValueByColumnAndRow($col, 1, $header);
        $col++;
    }
    
    // Add 2 example rows
    $examples = [
        [
            'Event Konser Rock', 'Konser', 'SITE001', 'BALI BARAT', 'MC-BALI BARAT',
            '-8.650000', '115.210000', '10', '5', '3', '2', '1',
            '2', '1', '0', '50000', '30000', '5000000',
            'Event sukses, pengunjung antusias', 'foto_konser.jpg', 'msisdn_konser.xlsx'
        ],
        [
            'Event Bazar Ramadan', 'Bazar', 'SITE002', 'LOMBOK BARAT', 'MC-LOMBOK BARAT',
            '-8.583300', '116.116700', '15', '8', '5', '3', '2',
            '3', '2', '5', '75000', '40000', '7500000',
            'Banyak pengunjung', 'foto_bazar.jpg', 'msisdn_bazar.xlsx'
        ]
    ];
    
    $row = 2;
    foreach ($examples as $example) {
        $col = 1;
        foreach ($example as $value) {
            $sheet->setCellValueByColumnAndRow($col, $row, $value);
            $col++;
        }
        $row++;
    }
    
    // Auto-size columns
    foreach (range(1, count($headers)) as $col) {
        $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
    }
    
    // Save main Excel
    $writer = new Xlsx($spreadsheet);
    $writer->save($temp_dir . '/data_event.xlsx');
    
    // ===== 2. CREATE MSISDN FILES =====
    // MSISDN for Event 1 (Konser)
    $msisdn1 = new Spreadsheet();
    $sheet1 = $msisdn1->getActiveSheet();
    $sheet1->setCellValue('A1', 'MSISDN');
    $sheet1->setCellValue('B1', 'Type (existing/new)');
    $sheet1->setCellValue('A2', '081234567890');
    $sheet1->setCellValue('B2', 'new');
    $sheet1->setCellValue('A3', '081234567891');
    $sheet1->setCellValue('B3', 'existing');
    $sheet1->setCellValue('A4', '081234567892');
    $sheet1->setCellValue('B4', 'new');
    $sheet1->getColumnDimension('A')->setAutoSize(true);
    $sheet1->getColumnDimension('B')->setAutoSize(true);
    $writer1 = new Xlsx($msisdn1);
    $writer1->save($temp_dir . '/msisdn_konser.xlsx');
    
    // MSISDN for Event 2 (Bazar)
    $msisdn2 = new Spreadsheet();
    $sheet2 = $msisdn2->getActiveSheet();
    $sheet2->setCellValue('A1', 'MSISDN');
    $sheet2->setCellValue('B1', 'Type (existing/new)');
    $sheet2->setCellValue('A2', '082345678901');
    $sheet2->setCellValue('B2', 'new');
    $sheet2->setCellValue('A3', '082345678902');
    $sheet2->setCellValue('B3', 'new');
    $sheet2->getColumnDimension('A')->setAutoSize(true);
    $sheet2->getColumnDimension('B')->setAutoSize(true);
    $writer2 = new Xlsx($msisdn2);
    $writer2->save($temp_dir . '/msisdn_bazar.xlsx');
    
    // ===== 3. CREATE DUMMY PHOTOS =====
    // Create simple 1x1 pixel images as placeholders
    $img1 = imagecreatetruecolor(800, 600);
    $bg1 = imagecolorallocate($img1, 255, 100, 100); // Red background
    imagefill($img1, 0, 0, $bg1);
    $text_color = imagecolorallocate($img1, 255, 255, 255);
    imagestring($img1, 5, 300, 290, 'FOTO KONSER', $text_color);
    imagejpeg($img1, $temp_dir . '/foto_konser.jpg', 90);
    imagedestroy($img1);
    
    $img2 = imagecreatetruecolor(800, 600);
    $bg2 = imagecolorallocate($img2, 100, 100, 255); // Blue background
    imagefill($img2, 0, 0, $bg2);
    imagestring($img2, 5, 300, 290, 'FOTO BAZAR', $text_color);
    imagejpeg($img2, $temp_dir . '/foto_bazar.jpg', 90);
    imagedestroy($img2);
    
    // ===== 4. CREATE README FILE =====
    $readme = <<<'README'
# CONTOH BULK IMPORT EVENT

File ZIP ini berisi contoh lengkap untuk bulk import event.

## ISI FILE:
1. data_event.xlsx      - File Excel utama dengan 2 contoh event
2. foto_konser.jpg      - Foto untuk event pertama
3. foto_bazar.jpg       - Foto untuk event kedua
4. msisdn_konser.xlsx   - Data MSISDN untuk event konser
5. msisdn_bazar.xlsx    - Data MSISDN untuk event bazar
6. README.txt           - File ini

## CARA MENGGUNAKAN:
1. Buka data_event.xlsx
2. Edit data sesuai kebutuhan Anda
3. Ganti foto dengan foto event Anda (nama file harus sama!)
4. Edit file MSISDN sesuai data Anda
5. Compress semua file ke dalam ZIP
6. Upload ZIP ke sistem

## PENTING:
- Nama file di Excel HARUS SAMA dengan nama file di ZIP
- Tanggal event otomatis menggunakan waktu submit
- Alamat tidak perlu diisi (akan kosong)
- Format MSISDN: 08xxx atau 62xxx atau 8xxx (akan diformat otomatis)

## KOLOM WAJIB:
- Nama Event
- Site ID
- Latitude
- Longitude
- Cost

Selamat mencoba!
README;
    
    file_put_contents($temp_dir . '/README.txt', $readme);
    
    // ===== 5. CREATE ZIP FILE =====
    $zip_file = sys_get_temp_dir() . '/bulk_import_example_' . time() . '.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zip_file, ZipArchive::CREATE) !== TRUE) {
        throw new Exception("Gagal membuat file ZIP");
    }
    
    // Add all files to ZIP
    $files = scandir($temp_dir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $zip->addFile($temp_dir . '/' . $file, $file);
        }
    }
    
    $zip->close();
    
    // ===== 6. SEND ZIP TO BROWSER =====
    error_log("Event Download: Sending ZIP file - " . filesize($zip_file) . " bytes");
    while (ob_get_level()) ob_end_clean(); // Clear ALL buffers
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="contoh_bulk_import_event.zip"');
    header('Content-Length: ' . filesize($zip_file));
    header('Cache-Control: no-cache, must-revalidate');
    
    readfile($zip_file);
    
    // ===== 7. CLEANUP =====
    // Delete temporary files
    foreach (scandir($temp_dir) as $file) {
        if ($file != '.' && $file != '..') {
            unlink($temp_dir . '/' . $file);
        }
    }
    rmdir($temp_dir);
    unlink($zip_file);
    
} catch (Exception $e) {
    // Cleanup on error
    if (file_exists($temp_dir)) {
        foreach (scandir($temp_dir) as $file) {
            if ($file != '.' && $file != '..') {
                @unlink($temp_dir . '/' . $file);
            }
        }
        @rmdir($temp_dir);
    }
    if (isset($zip_file) && file_exists($zip_file)) {
        @unlink($zip_file);
    }
    
    die("Error: " . $e->getMessage());
}

exit;
