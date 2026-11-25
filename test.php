<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
try {
    db()->query('SELECT 1');
    echo json_encode(['success'=>true,'message'=>'AQUAR PHP backend connected to MySQL!']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database connection failed. Check API/config.php and XAMPP MySQL.']);
}
