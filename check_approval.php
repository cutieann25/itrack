<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_config.php';

try {
    $conn = project_db_connection();
    if (!$conn) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Database connection failed']);
        exit();
    }

    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $raw_name = trim($_POST['student_name'] ?? $_GET['student_name'] ?? '');
    $student_name = preg_replace('/\s+/', ' ', $raw_name);
    $strand = trim($_POST['strand'] ?? $_GET['strand'] ?? '');
    $device_id = trim($_POST['device_id'] ?? $_GET['device_id'] ?? '');

    // Auto-create students table if missing
    $conn->query("CREATE TABLE IF NOT EXISTS students (
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
    )");

    // -------------------------------------------------------------
    // 1. ACTION: REGISTER
    // -------------------------------------------------------------
    if ($action === 'register') {
        if (empty($student_name)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Student name is required']);
            $conn->close();
            exit();
        }

        $dob = trim($_POST['dob'] ?? $_GET['dob'] ?? '') ?: null;
        $gender = trim($_POST['gender'] ?? $_GET['gender'] ?? '');
        $address = trim($_POST['address'] ?? $_GET['address'] ?? '');
        $phone = trim($_POST['phone'] ?? $_GET['phone'] ?? '');
        $email = trim($_POST['email'] ?? $_GET['email'] ?? '');
        $parents = trim($_POST['parents'] ?? $_GET['parents'] ?? '');

        // Check if student already exists using LIKE matching
        $like_name = '%' . $student_name . '%';
        $check_stmt = $conn->prepare('SELECT status, student_name, strand FROM students WHERE student_name LIKE ? OR (device_id = ? AND device_id != "") ORDER BY (CASE WHEN UPPER(TRIM(status)) = "APPROVED" THEN 1 ELSE 2 END), id DESC LIMIT 1');
        if ($check_stmt) {
            $check_stmt->bind_param('ss', $like_name, $device_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result && $check_result->num_rows > 0) {
                $row = $check_result->fetch_assoc();

                if (!empty($device_id)) {
                    $update_dev = $conn->prepare('UPDATE students SET device_id = ? WHERE student_name = ?');
                    if ($update_dev) {
                        $update_dev->bind_param('ss', $device_id, $row['student_name']);
                        $update_dev->execute();
                        $update_dev->close();
                    }
                }

                echo json_encode([
                    'status' => strtoupper(trim($row['status'])),
                    'strand' => $row['strand'],
                    'student_name' => $row['student_name'],
                    'message' => 'Account found with status: ' . strtoupper(trim($row['status']))
                ]);
                $check_stmt->close();
                $conn->close();
                exit();
            }
            $check_stmt->close();
        }

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
                echo json_encode(['status' => 'ERROR', 'message' => 'Failed to submit: ' . $insert_stmt->error]);
            }
            $insert_stmt->close();
        }

        $conn->close();
        exit();
    }

    // -------------------------------------------------------------
    // 2. ACTION: CHECK / LOGIN
    // -------------------------------------------------------------
    if ($action === 'check') {
        if (empty($student_name) && empty($device_id)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Student name or Device ID is required']);
            $conn->close();
            exit();
        }

        $found_row = null;

        // Flexible LIKE search (matches 'kirk', 'kirk ', 'kirk smith', etc.)
        if (!empty($student_name)) {
            $like_pattern = '%' . $student_name . '%';
            $stmt = $conn->prepare('SELECT student_name, status, strand, device_id FROM students WHERE student_name LIKE ? ORDER BY (CASE WHEN UPPER(TRIM(status)) = "APPROVED" THEN 1 ELSE 2 END), id DESC LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $like_pattern);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $found_row = $res->fetch_assoc();
                }
                $stmt->close();
            }
        }

        // Fallback search by device_id
        if (!$found_row && !empty($device_id)) {
            $stmt = $conn->prepare('SELECT student_name, status, strand, device_id FROM students WHERE device_id = ? AND device_id != "" ORDER BY (CASE WHEN UPPER(TRIM(status)) = "APPROVED" THEN 1 ELSE 2 END), id DESC LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $device_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $found_row = $res->fetch_assoc();
                }
                $stmt->close();
            }
        }

        if ($found_row) {
            $ret_status = strtoupper(trim($found_row['status']));

            if (!empty($device_id) && $found_row['device_id'] !== $device_id) {
                $upd = $conn->prepare('UPDATE students SET device_id = ? WHERE student_name = ?');
                if ($upd) {
                    $upd->bind_param('ss', $device_id, $found_row['student_name']);
                    $upd->execute();
                    $upd->close();
                }
            }

            echo json_encode([
                'status' => $ret_status,
                'approval_status' => $ret_status,
                'strand' => $found_row['strand'],
                'student_name' => $found_row['student_name'],
                'message' => 'Status retrieved: ' . $ret_status
            ]);
            $conn->close();
            exit();
        }

        // Diagnostic list of registered names in DB
        $all_names = [];
        $list_res = $conn->query("SELECT student_name, status FROM students LIMIT 5");
        if ($list_res) {
            while ($r = $list_res->fetch_assoc()) {
                $all_names[] = $r['student_name'] . ' (' . strtoupper(trim($r['status'])) . ')';
            }
        }
        $db_names_text = !empty($all_names) ? ' Available in DB: ' . implode(', ', $all_names) : ' (Database table is empty!)';

        echo json_encode([
            'status' => 'NOT_REGISTERED',
            'message' => 'No account found for "' . $student_name . '".' . $db_names_text
        ]);
        $conn->close();
        exit();
    }

    // -------------------------------------------------------------
    // 3. ACTION: APPROVE / UPDATE_STATUS
    // -------------------------------------------------------------
    if ($action === 'approve' || $action === 'update_status') {
        $new_status = strtoupper(trim($_POST['status'] ?? $_GET['status'] ?? 'APPROVED'));

        if (empty($student_name)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Student name is required']);
            $conn->close();
            exit();
        }

        $like_name = '%' . $student_name . '%';
        $upd_stmt = $conn->prepare('UPDATE students SET status = ? WHERE student_name LIKE ? OR (device_id = ? AND device_id != "")');
        if ($upd_stmt) {
            $upd_stmt->bind_param('sss', $new_status, $like_name, $device_id);
            if ($upd_stmt->execute()) {
                echo json_encode([
                    'status' => 'SUCCESS',
                    'message' => 'Student status updated to ' . $new_status
                ]);
                $upd_stmt->close();
                $conn->close();
                exit();
            }
            $upd_stmt->close();
        }

        echo json_encode(['status' => 'ERROR', 'message' => 'Failed to update student status']);
        $conn->close();
        exit();
    }

    echo json_encode(['status' => 'ERROR', 'message' => 'Invalid action: ' . $action]);
    $conn->close();

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['status' => 'ERROR', 'message' => 'Script Exception: ' . $e->getMessage()]);
}
?>
