<?php

date_default_timezone_set('Asia/Kolkata');

$host = "127.0.0.1";
$port = 3307;
$username = "root";
$password = "";
$database = "internboot_m6_dev";

$conn = new mysqli(
    $host,
    $username,
    $password,
    $database,
    $port
);

if ($conn->connect_error) {
    http_response_code(500);
    die("Database connection failed");
}

$conn->set_charset("utf8mb4");