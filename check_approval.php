<?php
header('Content-Type: application/json');

require_once __DIR__ . '/db_config.php';
$conn = project_db_connection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$student_name = trim($_POST['student_name'] ?? $_GET['student_name'] ?? '');
$strand = trim($_POST['strand'] ?? $_GET['strand'] ?? '');
$device_id = trim($_POST['device_id'] ?? $_GET['device_id'] ?? '');

// -------------------------------------------------------------
// 1. ACTION: REGISTER (Submit new student registration)
// -------------------------------------------------------------
if ($action === 'register') {
    if (empty($student_name)) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Student name is required'
        ]);
        $conn->close();
        exit();
    }

    $dob = trim($_POST['dob'] ?? $_GET['dob'] ?? '') ?: null;
    $gender = trim($_POST['gender'] ?? $_GET['gender'] ?? '');
    $address = trim($_POST['address'] ?? $_GET['address'] ?? '');
    $phone = trim($_POST['phone'] ?? $_GET['phone'] ?? '');
    $email = trim($_POST['email'] ?? $_GET['email'] ?? '');
    $parents = trim($_POST['parents'] ?? $_GET['parents'] ?? '');

    // Check if student already exists by name OR device_id
    $check_stmt = $conn->prepare('SELECT status, student_name, strand FROM students WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?)) OR (device_id = ? AND device_id != "") LIMIT 1');
    $check_stmt->bind_param('ss', $student_name, $device_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $row = $check_result->fetch_assoc();
        
        // Sync device_id if account already exists
        if (!empty($device_id)) {
            $update_dev = $conn->prepare('UPDATE students SET device_id = ? WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?))');
            $update_dev->bind_param('ss', $device_id, $student_name);
            $update_dev->execute();
            $update_dev->close();
        }

        echo json_encode([
            'status' => $row['status'],
            'strand' => $row['strand'],
            'student_name' => $row['student_name'],
            'message' => 'Account already exists with status: ' . $row['status']
        ]);
        $check_stmt->close();
        $conn->close();
        exit();
    }

    $status = 'PENDING';
    $insert_stmt = $conn->prepare('INSERT INTO students (student_name, strand, dob, gender, address, phone, email, parents, device_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert_stmt->bind_param('ssssssssss', $student_name, $strand, $dob, $gender, $address, $phone, $email, $parents, $device_id, $status);

    if ($insert_stmt->execute()) {
        echo json_encode([
            'status' => 'PENDING',
            'strand' => $strand,
            'student_name' => $student_name,
            'message' => 'Registration submitted. Waiting for coordinator approval.'
        ]);
    } else {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Failed to submit registration: ' . $insert_stmt->error
        ]);
    }

    $insert_stmt->close();
    $check_stmt->close();
    $conn->close();
    exit();
}

// -------------------------------------------------------------
// 2. ACTION: CHECK (Check approval status on app startup/login)
// -------------------------------------------------------------
if ($action === 'check') {
    if (empty($student_name) && empty($device_id)) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Student name or Device ID is required'
        ]);
        $conn->close();
        exit();
    }

    // Flexible lookup: Match by student_name OR device_id
    $stmt = $conn->prepare('SELECT student_name, status, strand, device_id FROM students WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?)) OR (device_id = ? AND device_id != "") LIMIT 1');
    $stmt->bind_param('ss', $student_name, $device_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        // Update device_id if needed
        if (!empty($device_id) && $row['device_id'] !== $device_id) {
            $upd = $conn->prepare('UPDATE students SET device_id = ? WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?))');
            $upd->bind_param('ss', $device_id, $row['student_name']);
            $upd->execute();
            $upd->close();
        }

        echo json_encode([
            'status' => $row['status'],
            'strand' => $row['strand'],
            'student_name' => $row['student_name'],
            'message' => 'Status retrieved successfully'
        ]);
    } else {
        echo json_encode([
            'status' => 'NOT_REGISTERED',
            'message' => 'No account found for this student.'
        ]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

// -------------------------------------------------------------
// 3. ACTION: APPROVE / UPDATE_STATUS (Coordinator Approval)
// -------------------------------------------------------------
if ($action === 'approve' || $action === 'update_status') {
    $new_status = trim($_POST['status'] ?? $_GET['status'] ?? 'APPROVED');

    if (empty($student_name)) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Student name is required to update status'
        ]);
        $conn->close();
        exit();
    }

    $upd_stmt = $conn->prepare('UPDATE students SET status = ? WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?)) OR (device_id = ? AND device_id != "")');
    $upd_stmt->bind_param('sss', $new_status, $student_name, $device_id);

    if ($upd_stmt->execute()) {
        echo json_encode([
            'status' => 'SUCCESS',
            'message' => 'Student status updated to ' . $new_status
        ]);
    } else {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Failed to update student status'
        ]);
    }

    $upd_stmt->close();
    $conn->close();
    exit();
}

// Default response for unrecognized actions
echo json_encode([
    'status' => 'ERROR',
    'message' => 'Invalid action'
]);

$conn->close();
?>