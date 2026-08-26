<?php
// File: index.php
// Bertindak sebagai router utama aplikasi.

require_once 'config/database.php';

// Cek apakah pengguna sudah login
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    // Jika sudah login, cek perannya (role)
    if ($_SESSION["role"] === 'admin') {
        // Jika admin, arahkan ke dashboard admin
        header("location: dashboard_admin.php");
        exit;
    } elseif ($_SESSION["role"] === 'user') {
        // Jika user, arahkan ke dashboard user
        header("location: dashboard_user.php");
        exit;
    }
}

// Jika belum login atau tidak memiliki peran yang valid, arahkan ke halaman login
header("location: login.php");
exit;
?>
