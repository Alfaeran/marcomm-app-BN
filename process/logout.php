<?php
// File: process/logout.php
// Memulai sesi
session_start();
 
// Menghapus semua variabel sesi
$_SESSION = array();
 
// Menghancurkan sesi
session_destroy();
 
// Mengarahkan ke halaman login
header("location: login.php");
exit;
?>
