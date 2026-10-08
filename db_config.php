<?php
// Aiven MySQL Database Credentials
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', getenv('DB_HOST') ?: 'mysql-2d11b6ef-itracker-project.f.aivencloud.com');
define('DB_USER', getenv('DB_USER') ?: 'avnadmin');
define('DB_PASSWORD', getenv('DB_PASS') ?: 'AVNS_umvLm9FDKGbII7JhJGs');
define('DB_NAME', getenv('DB_NAME') ?: 'defaultdb');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 28102));

function project_db_connection() {
    $conn = mysqli_init();
    
    // Enable SSL mode required by Aiven Cloud MySQL
    $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    
    // Connect with SSL flag
    @$conn->real_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT, NULL, MYSQLI_CLIENT_SSL);

    if ($conn->connect_error || $conn->connect_errno) {
        http_response_code(200); // Return 200 so Android receives structured JSON
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Aiven DB Connection Error: ' . ($conn->connect_error ?: mysqli_connect_error())
        ]);
        exit();
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}
?>
