
<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Manila');

// TiDB Cloud connection settings
define('DB_HOST', getenv('DB_HOST') ?: 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com');
define('DB_USER', getenv('DB_USER') ?: 'eECeULhK4dZkEtz.root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'znCdsCjQpbtr34wM');
define('DB_NAME', getenv('DB_NAME') ?: 'attendance_db');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 4000));

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

        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);

        // TiDB Cloud TLS connection
        $caFile = getenv('DB_CA_PATH') ?: (__DIR__ . '/ca.pem');

        if (!file_exists($caFile)) {
            throw new RuntimeException('TiDB CA certificate file not found.');
        }

        $conn->ssl_set(null, null, $caFile, null, null);

        if (!$conn->real_connect(
            DB_HOST,
            DB_USER,
            DB_PASSWORD,
            DB_NAME,
            DB_PORT,
            null,
            MYSQLI_CLIENT_SSL
        )) {
            throw new RuntimeException($conn->connect_error ?: mysqli_connect_error());
        }

        $conn->set_charset('utf8mb4');

        $conn->query("SET time_zone = '+08:00'");

        return $conn;

    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());

        throw new RuntimeException(
            'Database connection failed; check the server error log and database environment variables.',
            0,
            $e
        );
    }
}
