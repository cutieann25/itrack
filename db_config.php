<?php
// Aiven MySQL Database Credentials
define('DB_HOST', getenv('DB_HOST') ?: 'mysql-2d11b6ef-itracker-project.f.aivencloud.com');
define('DB_USER', getenv('DB_USER') ?: 'avnadmin');
define('DB_PASSWORD', getenv('DB_PASS') ?: 'AVNS_umvLm9FDKGbII7JhJGs'); // Replace with your password from Aiven
define('DB_NAME', getenv('DB_NAME') ?: 'defaultdb');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 28102));

function project_db_connection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        die('Database connection failed: ' . htmlspecialchars($conn->connect_error));
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
?>