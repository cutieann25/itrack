<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('DB_HOST', getenv('DB_HOST') ?: 'mysql-2d11b6ef-itracker-project.f.aivencloud.com');
define('DB_USER', getenv('DB_USER') ?: 'avnadmin');
define('DB_PASSWORD', getenv('DB_PASS') ?: (getenv('AVNS_umvLm9FDKGbII7JhJGs') ?: ''));
define('DB_NAME', getenv('DB_NAME') ?: 'defaultdb');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 28102));

function project_db_connection() {
    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('The PHP mysqli extension is not enabled.');
    }

    if (DB_PASSWORD === '') {
        throw new RuntimeException('The database password environment variable is not configured.');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $conn = mysqli_init();
        if (!$conn) {
            throw new RuntimeException('Unable to initialize the database connection.');
        }

        $conn->ssl_set(null, null, null, null, null);
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        if (!$conn->real_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT, null, MYSQLI_CLIENT_SSL)) {
            throw new RuntimeException($conn->connect_error ?: mysqli_connect_error());
        }
        if (!$conn->set_charset('utf8mb4')) {
            throw new RuntimeException($conn->error);
        }
        return $conn;
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed; check the server error log and database environment variables.', 0, $e);
    }
}
