<?php
// api/header.php

// 1. Set Headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// 2. Handle Preflight Options Request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 3. Include Database
// Adjust path depending on where this file is included from (usually api/filename.php)
include_once '../config/db.php';

// 4. Helper: Get JSON Input
function getJsonInput()
{
    return json_decode(file_get_contents("php://input"), true) ?? [];
}

// 5. Helper: Send Response
function sendResponse($code, $message, $data = null)
{
    http_response_code($code);
    echo json_encode([
        'status' => $code,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}
?>