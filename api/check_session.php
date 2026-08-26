<?php
require_once 'cors.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    echo json_encode([
        "status" => "success",
        "loggedin" => true,
        "user" => [
            "id" => $_SESSION["id"] ?? null,
            "username" => $_SESSION["username"] ?? null,
            "role" => $_SESSION["role"] ?? null,
            "brand" => $_SESSION["brand"] ?? null,
            "branch_id" => $_SESSION["branch_id"] ?? null
        ]
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "loggedin" => false,
        "message" => "Sesi tidak ditemukan atau sudah berakhir."
    ]);
}
?>
