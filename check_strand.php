<?php
require_once __DIR__ . '/db_config.php';
$conn = project_db_connection();

$total = $conn->query("SELECT COUNT(*) AS c FROM attendance_logs")->fetch_assoc()['c'];
$with = $conn->query("SELECT COUNT(*) AS c FROM attendance_logs WHERE strand IS NOT NULL AND TRIM(strand) != ''")->fetch_assoc()['c'];
$without = $conn->query("SELECT COUNT(*) AS c FROM attendance_logs WHERE strand IS NULL OR TRIM(strand) = ''")->fetch_assoc()['c'];

echo "Total rows: $total\n";
echo "With strand: $with\n";
echo "Without strand: $without\n\n";

echo "Latest 20 rows (student_id, log_time, status, strand, latitude, longitude):\n";
$res = $conn->query("SELECT student_id, log_time, status, strand, latitude, longitude FROM attendance_logs ORDER BY log_time DESC LIMIT 20");
while ($r = $res->fetch_assoc()) {
    printf("%s | %s | %s | %s | %s | %s\n", $r['student_id'], $r['log_time'], $r['status'], $r['strand'] === null ? 'NULL' : $r['strand'], $r['latitude'], $r['longitude']);
}

$conn->close();
