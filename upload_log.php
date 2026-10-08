<?php
// SET TIMEZONE TO MATCH YOUR LOCAL TIME
date_default_timezone_set('Asia/Manila'); 

require_once __DIR__ . '/db_config.php';
$conn = project_db_connection();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. COLLECT AND SANITIZE DATA
    $student_name = isset($_POST['student_name']) ? trim($_POST['student_name']) : '';
    $device_id    = isset($_POST['device_id']) ? $_POST['device_id'] : '';
    
    // Convert coordinates to high-precision PHP floats
    $lat          = (isset($_POST['latitude']) && is_numeric($_POST['latitude'])) ? (float)$_POST['latitude'] : 0.0;
    $lng          = (isset($_POST['longitude']) && is_numeric($_POST['longitude'])) ? (float)$_POST['longitude'] : 0.0;
    
    $strand       = isset($_POST['strand']) ? trim($_POST['strand']) : null;
    $status       = isset($_POST['status']) ? $_POST['status'] : ''; // e.g., "Success (IN)"

    if (empty($student_name)) {
        echo "Error: Student name is required.";
        exit;
    }

    // Reject invalid or 0,0 default coordinates to avoid placing inaccurate pins on the website map
    if ($lat == 0.0 || $lng == 0.0) {
        echo "Error: Invalid location coordinates received. Please ensure GPS is enabled.";
        $conn->close();
        exit;
    }

    $current_date = date("Y-m-d");
    $current_hour = (int)date("H");
    $is_in  = (strpos($status, '(IN)') !== false);
    $is_out = (strpos($status, '(OUT)') !== false);

    // 2. DEVICE BINDING CHECK
    $bind_check = $conn->prepare("SELECT student_id FROM attendance_logs WHERE device_id = ? AND student_id != ? LIMIT 1");
    $bind_check->bind_param("ss", $device_id, $student_name);
    $bind_check->execute();
    $bind_check->bind_result($existing_owner);
    
    if ($bind_check->fetch()) {
        echo "Error: This device is registered to $existing_owner. Use your own device.";
        $bind_check->close();
        $conn->close();
        exit;
    }
    $bind_check->close();

    // 3. DUPLICATE LOG CHECK (AM/PM)
    $period_label = "";
    $sql = "";

    if ($is_in) {
        if ($current_hour < 12) {
            $period_label = "AM Time-In";
            $sql = "SELECT id FROM attendance_logs WHERE student_id = ? AND DATE(log_time) = ? AND status LIKE '%(IN)%' AND HOUR(log_time) < 12";
        } else {
            $period_label = "PM Time-In";
            $sql = "SELECT id FROM attendance_logs WHERE student_id = ? AND DATE(log_time) = ? AND status LIKE '%(IN)%' AND HOUR(log_time) >= 12";
        }
    } else if ($is_out) {
        if ($current_hour < 13) {
            $period_label = "AM Time-Out";
            $sql = "SELECT id FROM attendance_logs WHERE student_id = ? AND DATE(log_time) = ? AND status LIKE '%(OUT)%' AND HOUR(log_time) < 13";
        } else {
            $period_label = "PM Time-Out";
            $sql = "SELECT id FROM attendance_logs WHERE student_id = ? AND DATE(log_time) = ? AND status LIKE '%(OUT)%' AND HOUR(log_time) >= 13";
        }
    }

    if (!empty($sql)) {
        $check = $conn->prepare($sql);
        $check->bind_param("ss", $student_name, $current_date);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            echo "Error: Already recorded $period_label for today.";
            $check->close();
            $conn->close();
            exit;
        }
        $check->close();
    }

    // 4. STRAND CONSISTENCY CHECK
    $existing_strand = null;
    $es = $conn->prepare("SELECT strand FROM attendance_logs WHERE student_id = ? AND strand IS NOT NULL AND TRIM(strand) != '' ORDER BY log_time ASC LIMIT 1");
    if ($es) {
        $es->bind_param("s", $student_name);
        $es->execute();
        $es->bind_result($existing_strand);
        if (!$es->fetch()) {
            $existing_strand = null;
        }
        $es->close();
    }

    if ($existing_strand !== null) {
        $posted = ($strand !== null) ? trim($strand) : '';
        $stored = trim($existing_strand);
        
        if ($posted === '') {
            $strand = $existing_strand;
        } elseif (strcasecmp($posted, $stored) !== 0) {
            echo "Error: Strand mismatch. You are registered under: " . htmlspecialchars($existing_strand);
            $conn->close();
            exit;
        }
    }

    // 5. INSERT RECORD WITH FULL DOUBLE PRECISION
    $stmt = $conn->prepare("INSERT INTO attendance_logs (student_id, latitude, longitude, status, device_id, strand) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sddsss", $student_name, $lat, $lng, $status, $device_id, $strand);
    
    if ($stmt->execute()) {
        echo "Success";
    } else {
        echo "Error: " . $stmt->error;
    }
    
    $stmt->close();
}

$conn->close();
?>