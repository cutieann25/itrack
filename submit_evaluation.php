<?php
// submit_evaluation.php
header('Content-Type: application/json');

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';
$conn = project_db_connection();

if (!$conn) {
    echo json_encode(["status" => "ERROR", "message" => "Connection failed: " . mysqli_connect_error()]);
    exit;
}

$action = $_POST['action'] ?? '';
$supervisor_username = $_POST['supervisor_username'] ?? '';

// --- 1. Identify Supervisor ID from Username ---
$supervisor_id = 0;
if (!empty($supervisor_username)) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $supervisor_username);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $supervisor_id = $res['id'] ?? 0;
}

if ($action == "get_dashboard_data") {
    try {
        if ($supervisor_id == 0) {
            throw new Exception("Supervisor not found: " . $supervisor_username);
        }

        $students = [];
        // --- 2. Fetch Students Assigned to this Supervisor ---
        // JOIN logic: x.student_id (Assignment table) matches s.student_name (Students table)
        $sql = "SELECT x.student_id, s.student_name, s.strand, s.address, s.phone, a.agency_name 
                FROM student_supervisor_assignments x 
                JOIN students s ON s.student_name = x.student_id 
                LEFT JOIN agencies a ON a.agency_id = x.agency_id 
                WHERE x.supervisor_id = ? 
                ORDER BY s.student_name ASC";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        
        $stmt->bind_param("i", $supervisor_id);
        $stmt->execute();
        $stud_res = $stmt->get_result();

        while ($row = $stud_res->fetch_assoc()) {
            $sname = $row['student_name'];
            
            // FIX: Changed 'student_name' to 'student_id' to match your attendance_logs table
            $hours_stmt = $conn->prepare("SELECT log_time, status FROM attendance_logs WHERE student_id = ? ORDER BY log_time ASC");
            $total_minutes = 0;
            if ($hours_stmt) {
                $hours_stmt->bind_param('s', $sname);
                $hours_stmt->execute();
                $hours_res = $hours_stmt->get_result();
                $pending_in = null;
                while ($log = $hours_res->fetch_assoc()) {
                    $time = strtotime($log['log_time']);
                    if (stripos($log['status'], 'in') !== false) {
                        $pending_in = $time;
                    } else if (stripos($log['status'], 'out') !== false && $pending_in) {
                        $total_minutes += ($time - $pending_in) / 60;
                        $pending_in = null;
                    }
                }
            }
            
            $students[] = [
                "student_id" => $row['student_id'],
                "student_name" => $sname,
                "strand" => $row['strand'] ?? '',
                "address" => $row['address'] ?? '',
                "phone" => $row['phone'] ?? '',
                "agency_name" => $row['agency_name'] ?? '',
                "total_hours_rendered" => round($total_minutes / 60, 2)
            ];
        }

        // --- 3. Fetch History ---
        $history = [];
        $hist_stmt = $conn->prepare("SELECT * FROM performance_evaluations WHERE evaluator_id = ? ORDER BY id DESC LIMIT 20");
        $hist_stmt->bind_param("i", $supervisor_id);
        $hist_stmt->execute();
        $hist_res = $hist_stmt->get_result();
        while ($hrow = $hist_res->fetch_assoc()) { 
            $history[] = $hrow; 
        }

        echo json_encode([
            "status" => "SUCCESS", 
            "students" => $students, 
            "history" => $history
        ]);

    } catch (Exception $e) {
        echo json_encode(["status" => "ERROR", "message" => $e->getMessage()]);
    }
    
} else if ($action == "submit_appraisal") {
    // --- 4. Handle Appraisal Submission ---
    $sid = $_POST['student_id'] ?? '';
    $edate = $_POST['evaluation_date'] ?? date('Y-m-d');
    $partner = $_POST['partner_institution'] ?? '';
    $addr = $_POST['appraisal_address'] ?? '';
    $cont = $_POST['contact_number'] ?? '';
    $isup = $_POST['immersion_supervisor'] ?? '';
    $spos = $_POST['supervisor_position'] ?? '';
    $tstart = $_POST['training_start_date'] ?? null;
    $tend = $_POST['training_end_date'] ?? null;
    $thours = $_POST['total_hours_rendered'] ?? 0;
    $strand = $_POST['strand'] ?? '';
    $punc = $_POST['punctuality'] ?? 0;
    $qual = $_POST['work_quality'] ?? 0;
    $atti = $_POST['attitude'] ?? 0;
    $team = $_POST['teamwork'] ?? 0;
    $rjson = $_POST['rubric_json'] ?? '';
    $operf = $_POST['overall_performance_rating'] ?? 0;
    $wavg = $_POST['weighted_average'] ?? 0;
    $egrade = $_POST['equivalent_grade'] ?? 0;
    $comm = $_POST['comments'] ?? '';
    $sig = $_POST['supervisor_signature'] ?? '';

    $sql = "INSERT INTO performance_evaluations 
            (student_id, evaluator_id, evaluation_date, partner_institution, appraisal_address, contact_number, 
             immersion_supervisor, supervisor_position, training_start_date, training_end_date, total_hours_rendered, 
             strand, punctuality, work_quality, attitude, teamwork, rubric_scores, 
             overall_performance_rating, weighted_average, equivalent_grade, comments, supervisor_signature) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sissssssssdsddddsdddss", 
            $sid, $supervisor_id, $edate, $partner, $addr, $cont, 
            $isup, $spos, $tstart, $tend, $thours, 
            $strand, $punc, $qual, $atti, $team, $rjson, 
            $operf, $wavg, $egrade, $comm, $sig);
            
        if ($stmt->execute()) {
            echo json_encode(["status" => "SUCCESS", "message" => "Submitted successfully!"]);
        } else {
            echo json_encode(["status" => "ERROR", "message" => "Database Error: " . $stmt->error]);
        }
    } else {
        echo json_encode(["status" => "ERROR", "message" => "SQL Prepare Error: " . $conn->error]);
    }
}

$conn->close();
?>
