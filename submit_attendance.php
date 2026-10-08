<?php
// Sample endpoint to insert attendance logs, including `strand`.
// POST params: student_id, status (e.g., 'AM IN'), latitude, longitude, strand (ABM/ICT/HUMSS/HE or full label)

require_once __DIR__ . '/db_config.php';
$conn = project_db_connection();

$student_id = isset($_POST['student_id']) ? $_POST['student_id'] : null;
$status = isset($_POST['status']) ? $_POST['status'] : null;
$lat = isset($_POST['latitude']) ? $_POST['latitude'] : null;
$lng = isset($_POST['longitude']) ? $_POST['longitude'] : null;
$strand = isset($_POST['strand']) ? $_POST['strand'] : null;
$log_time = date('Y-m-d H:i:s');

if (!$student_id || !$status) {
    http_response_code(400);
    echo json_encode(['error' => 'student_id and status are required']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO attendance_logs (student_id, status, latitude, longitude, log_time, strand) VALUES (?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param('ssddss', $student_id, $status, $lat, $lng, $log_time, $strand);
if ($stmt->execute()) {
    echo json_encode(['success' => true, 'insert_id' => $stmt->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(['error' => $stmt->error]);
}

$stmt->close();
$conn->close();
