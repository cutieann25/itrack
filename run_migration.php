<?php
require_once __DIR__ . '/db_config.php';
// Simple migration runner: executes all .sql files in migrations/ in order.
$dir = __DIR__ . DIRECTORY_SEPARATOR . 'migrations';
$files = glob($dir . DIRECTORY_SEPARATOR . '*.sql');
if (!$files) {
    echo "No migration files found in migrations/\n";
    exit(0);
}

$conn = project_db_connection();

foreach ($files as $file) {
    echo "Running: " . basename($file) . "\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "Failed to read $file\n";
        continue;
    }
    if ($conn->multi_query($sql)) {
        do {
            if ($res = $conn->store_result()) {
                $res->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        echo "Executed: " . basename($file) . "\n";
    } else {
        echo "Error executing " . basename($file) . ": " . $conn->error . "\n";
    }
}

$conn->close();
echo "Migrations complete.\n";
