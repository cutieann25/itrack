<?php
/**
 * i-Tracker DTR Generator
 * Fixes: "Dangerous Site" browser warnings by forcing attachment headers.
 */

// 1. Prevent any accidental whitespace/output before headers
ob_start();
date_default_timezone_set('Asia/Manila');

require_once('tcpdf/tcpdf.php');
require_once __DIR__ . '/db_config.php';

// Database Connection
$conn = project_db_connection();

// 2. Sanitize and Get Inputs
$student_name = isset($_GET['student_name']) ? trim($_GET['student_name']) : "________________________";
$strand = isset($_GET['strand']) ? trim($_GET['strand']) : "";
$month_param = isset($_GET['month']) ? trim($_GET['month']) : date('F Y');
$period_label = htmlspecialchars($month_param);

// Resolve student details from DB to ensure Strand is full length
$student_lookup = $student_name;
$student_started_on = null;

if ($student_lookup !== '________________________') {
    $student_stmt = $conn->prepare('SELECT DATE(created_at) AS start_date, student_name, strand FROM students WHERE student_name = ? LIMIT 1');
    $student_stmt->bind_param('s', $student_lookup);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();
    if ($student_result && $student_result->num_rows > 0) {
        $student_record = $student_result->fetch_assoc();
        $student_started_on = $student_record['start_date'] ?? null;
        if ($strand === '' && !empty($student_record['strand'])) {
            $strand = $student_record['strand'];
        }
    }
}

// Format logic for filtering logs by the selected month
$timestamp_month = strtotime($month_param);
$first_day = date('Y-m-01 00:00:00', $timestamp_month);
$last_day = date('Y-m-t 23:59:59', $timestamp_month);

// Fetch logs for the specific student and month
$query = "SELECT log_time, status FROM attendance_logs 
          WHERE student_name = ? AND log_time BETWEEN ? AND ? 
          ORDER BY log_time ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param("sss", $student_name, $first_day, $last_day);
$stmt->execute();
$result = $stmt->get_result();

$logs = [];
while ($row = $result->fetch_assoc()) {
    $ts = strtotime($row['log_time']);
    $day = (int)date('j', $ts);
    $time = date('g:i A', $ts);
    $status = $row['status'];
    $hour = (int)date('H', $ts);
    
    $is_am = $hour < 12;

    if (stripos($status, '(IN)') !== false) {
        if ($is_am) {
            if (!isset($logs[$day]['am_in'])) $logs[$day]['am_in'] = $time;
        } else {
            if (!isset($logs[$day]['pm_in'])) $logs[$day]['pm_in'] = $time;
        }
    } else if (stripos($status, '(OUT)') !== false) {
        if ($is_am) {
            if (!isset($logs[$day]['am_out'])) $logs[$day]['am_out'] = $time;
        } else {
            if (!isset($logs[$day]['pm_out'])) $logs[$day]['pm_out'] = $time;
        }
    }
}

// 3. Setup TCPDF
// Custom Size: 105mm x 290mm (Narrow DTR slip)
$pdf = new TCPDF('P', 'mm', array(105, 290), true, 'UTF-8', false);
$pdf->SetCreator('i-Tracker');
$pdf->SetTitle('DTR - ' . $student_name);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 8);

// 4. HTML Template
$html = '
<div style="text-align:center;">
    <span style="font-size:8pt;">Republic of the Philippines</span><br>
    <span style="font-size:8pt;">Department of Education</span><br>
    <strong style="font-size:9pt;">MARABUT NATIONAL HIGH SCHOOL</strong><br>
    <span style="font-size:8pt;">Senior High School</span>
</div>

<div style="text-align:center; font-size:11pt; font-weight:bold; margin-top:10px;">DAILY TIME RECORD</div>

<table width="100%" cellpadding="2" style="font-size:8pt; margin-top:10px;">
    <tr>
        <td width="20%">Name:</td>
        <td width="80%" style="border-bottom: 0.5px solid black;"><strong>' . strtoupper(htmlspecialchars($student_name)) . '</strong></td>
    </tr>
    <tr>
        <td width="20%">Strand:</td>
        <td width="80%" style="border-bottom: 0.5px solid black;">' . htmlspecialchars($strand) . '</td>
    </tr>
    <tr>
        <td width="20%">Month:</td>
        <td width="80%" style="border-bottom: 0.5px solid black;">' . $period_label . '</td>
    </tr>
</table>

<table border="1" cellpadding="2" cellspacing="0" style="font-size:7pt; margin-top:10px; width:100%; text-align:center;">
    <thead>
        <tr style="font-weight:bold;">
            <th width="10%" rowspan="2"><br><br>Day</th>
            <th width="30%" colspan="2">AM</th>
            <th width="30%" colspan="2">PM</th>
            <th width="30%" colspan="2">Undertime</th>
        </tr>
        <tr style="font-weight:bold;">
            <th width="15%">In</th>
            <th width="15%">Out</th>
            <th width="15%">In</th>
            <th width="15%">Out</th>
            <th width="15%">Hrs</th>
            <th width="15%">Min</th>
        </tr>
    </thead>
    <tbody>';

// Generate 31 rows
for ($i = 1; $i <= 31; $i++) {
    $am_in = $logs[$i]['am_in'] ?? '';
    $am_out = $logs[$i]['am_out'] ?? '';
    $pm_in = $logs[$i]['pm_in'] ?? '';
    $pm_out = $logs[$i]['pm_out'] ?? '';
    
    $html .= '<tr>
                <td>' . $i . '</td>
                <td>' . $am_in . '</td>
                <td>' . $am_out . '</td>
                <td>' . $pm_in . '</td>
                <td>' . $pm_out . '</td>
                <td></td>
                <td></td>
              </tr>';
}

$html .= '
    </tbody>
</table>

<div style="font-size:7pt; font-style:italic; margin-top:10px;">
    I certify on my honor that the above is a true and correct report of the hours of work performed.
</div>

<table width="100%" style="margin-top:20px; text-align:center;">
    <tr>
        <td><br>__________________________<br>Student Signature</td>
    </tr>
    <tr>
        <td><br><br>__________________________<br><strong>MYLENE M. AMANTILLO</strong><br>Authorized Supervisor</td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// 5. Force Download and Clean Buffers
// This prevents the "Dangerous Site" warning by sending the file as a clean binary attachment.
ob_end_clean();

$filename = "DTR_" . str_replace(' ', '_', $student_name) . "_" . date('M_Y', $timestamp_month) . ".pdf";

// 'D' header forces download, 'I' view inline (Safe browsing prefers 'D' for new sites)
$pdf->Output($filename, 'D'); 
exit;
