<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_config.php';

try {
    $conn = project_db_connection();

    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $student_name = trim($_POST['student_name'] ?? $_GET['student_name'] ?? '');
    $strand = trim($_POST['strand'] ?? $_GET['strand'] ?? '');
    $device_id = trim($_POST['device_id'] ?? $_GET['device_id'] ?? '');

    // Auto-create students table if it doesn't exist yet in Aiven defaultdb
    if (!$conn->query("CREATE TABLE IF NOT EXISTS students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_name VARCHAR(255) NOT NULL,
        strand VARCHAR(100),
        dob VARCHAR(50),
        gender VARCHAR(20),
        address TEXT,
        phone VARCHAR(50),
        email VARCHAR(100),
        parents VARCHAR(255),
        device_id VARCHAR(255),
        status VARCHAR(50) DEFAULT 'PENDING',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )")) {
        throw new RuntimeException('Unable to prepare student records: ' . $conn->error);
    }

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
        $check_stmt = $conn->prepare("SELECT status, student_name, strand FROM students WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?)) OR (device_id = ? AND device_id != '') LIMIT 1");
        if (!$check_stmt) {
            throw new RuntimeException('Unable to look up existing student: ' . $conn->error);
        }
        $check_stmt->bind_param('ss', $student_name, $device_id);
        if (!$check_stmt->execute()) {
            throw new RuntimeException('Unable to look up existing student: ' . $check_stmt->error);
        }
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $row = $check_result->fetch_assoc();

            if (!empty($device_id)) {
                $update_dev = $conn->prepare('UPDATE students SET device_id = ? WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?))');
                if ($update_dev) {
                    $update_dev->bind_param('ss', $device_id, $student_name);
                    $update_dev->execute();
                    $update_dev->close();
                }
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
        $check_stmt->close();

        $status = 'PENDING';
        $insert_stmt = $conn->prepare('INSERT INTO students (student_name, strand, dob, gender, address, phone, email, parents, device_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if ($insert_stmt) {
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
        } else {
            echo json_encode([
                'status' => 'ERROR',
                'message' => 'Query preparation failed: ' . $conn->error
            ]);
        }

        $conn->close();
        exit();
    }

    // -------------------------------------------------------------
    // 2. ACTION: CHECK (Check approval status)
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

        $stmt = $conn->prepare("SELECT student_name, status, strand, device_id FROM students WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?)) OR (device_id = ? AND device_id != '') LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Unable to look up student approval: ' . $conn->error);
        }
        $stmt->bind_param('ss', $student_name, $device_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to look up student approval: ' . $stmt->error);
        }
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();

            if (!empty($device_id) && $row['device_id'] !== $device_id) {
                $upd = $conn->prepare('UPDATE students SET device_id = ? WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?))');
                if ($upd) {
                    $upd->bind_param('ss', $device_id, $row['student_name']);
                    $upd->execute();
                    $upd->close();
                }
            }

            echo json_encode([
                'status' => strtoupper($row['status']),
                'strand' => $row['strand'],
                'student_name' => $row['student_name'],
                'message' => 'Status retrieved successfully'
            ]);
            $stmt->close();
            $conn->close();
            exit();
        }
        $stmt->close();

        echo json_encode([
            'status' => 'NOT_REGISTERED',
            'message' => 'No account found for this student.'
        ]);
        $conn->close();
        exit();
    }

    // Default response
    echo json_encode([
        'status' => 'ERROR',
        'message' => 'Invalid action: ' . $action
    ]);

    $conn->close();

} catch (Throwable $e) {
    error_log('Approval endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'ERROR',
        'message' => 'The request could not be completed. Check the server error log.'
    ]);
}
?>
