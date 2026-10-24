<?php

mysqli_report(MYSQLI_REPORT_OFF);

header('Content-Type: application/json; charset=utf-8');

$host = "localhost";
$username = "root";
$password = "";
$database = "aura_haven";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed. Check your database settings."
    ]);

    exit;
}

$conn->set_charset("utf8mb4");
?>