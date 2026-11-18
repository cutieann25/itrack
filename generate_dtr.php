<?php
// Prevent any whitespace before headers
ob_start();
date_default_timezone_set('Asia/Manila');

// 1. SET HEADERS IMMEDIATELY 
// This helps tell the browser this is a legitimate file, not a phishing page
header("X-Content-Type-Options: nosniff");
header("Content-Type: application/pdf");

require_once('tcpdf/tcpdf.php');
require_once __DIR__ . '/db_config.php';

$conn = project_db_connection();

// 2. SANITIZE INPUTS
$student_name = isset($_GET['student_name']) ? trim($_GET['student_name']) : "________________________";
$strand = isset($_GET['strand']) ? trim($_GET['strand']) : "";
$period_label = 'All Recorded Attendance';

$student_id = isset($_GET['student_id']) ? trim($_GET['student_id']) : null;
$student_lookup = ($student_id !== null && $student_id !== '') ? $student_id : $student_name;
$student_started_on = null;

if ($student_lookup !== '________________________') {
    $student_stmt = $conn->prepare('SELECT DATE(created_at) AS start_date, student_name, strand FROM students WHERE id = ? OR student_name = ? LIMIT 1');
    $student_stmt->bind_param('ss', $student_lookup, $student_lookup);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();
    if ($student_result && $student_result->num_rows > 0) {
        $student_record = $student_result->fetch_assoc();
        $student_started_on = $student_record['start_date'] ?? null;
        if (!empty($student_record['student_name'])) {
            $student_name = $student_record['student_name'];
        }
        if ($strand === '' && !empty($student_record['strand'])) {
            $strand = $student_record['strand'];
        }
    }
}
$strand = preg_replace('/^\s*\d+\.\s*/', '', $strand);
$attendance_student_id = $student_lookup;

// Fetch attendance
$query = "SELECT * FROM attendance_logs WHERE student_id = ? ORDER BY log_time ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $attendance_student_id);
$stmt->execute();
$result = $stmt->get_result();

$all_logs = [];
while ($row = $result->fetch_assoc()) {
    $all_logs[] = $row;
}

$logs = [];
$dtr_start_date = $student_started_on;
if ($dtr_start_date === null) {
    foreach ($all_logs as $row) {
        $date_key = date('Y-m-d', strtotime($row['log_time']));
        if ($dtr_start_date === null || $date_key < $dtr_start_date) {
            $dtr_start_date = $date_key;
        }
    }
}
$dtr_start_date = $dtr_start_date ?? date('Y-m-d');

foreach ($all_logs as $row) {
    $ts = strtotime($row['log_time']);
    $date_key = date('Y-m-d', $ts);
    if ($student_started_on !== null && $date_key < $student_started_on) continue;

    $start_date = new DateTimeImmutable($dtr_start_date);
    $log_date = new DateTimeImmutable($date_key);
    $day = $start_date->diff($log_date)->days + 1;
    $time = date('g:i A', $ts);
    $status = $row['status'];
    $hour = (int)date('H', $ts);
    $is_am = $hour < 12;

    if (stripos($status, '(IN)') !== false) {
        if ($is_am) { if (!isset($logs[$day]['am_in'])) $logs[$day]['am_in'] = $time; }
        else { if (!isset($logs[$day]['pm_in'])) $logs[$day]['pm_in'] = $time; }
    } else if (stripos($status, '(OUT)') !== false) {
        if ($is_am) { if (!isset($logs[$day]['am_out'])) $logs[$day]['am_out'] = $time; }
        else { if (!isset($logs[$day]['pm_out'])) $logs[$day]['pm_out'] = $time; }
    }
}

// 3. GENERATE PDF
$pdf = new TCPDF('P', 'mm', array(105, 290), true, 'UTF-8', false);
$pdf->SetCreator('i-Tracker');
$pdf->SetTitle('DTR - ' . $student_name);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(8, 10, 8);
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 8);

$html = '
<div style="text-align:center; font-size:8pt;">
    Republic of the Philippines<br>Department of Education<br>Region VIII<br>Division of Samar<br>
    <strong style="font-size:9pt;">MARABUT NATIONAL HIGH SCHOOL</strong><br>Senior High School
</div>
<div style="text-align:center; font-size:12pt; font-weight:bold; margin-top:10px;">DAILY TIME RECORD</div>
<table width="100%" style="font-size:8pt; margin-top:10px;">
    <tr><td width="20%">Name:</td><td width="80%"><u>' . strtoupper(htmlspecialchars($student_name)) . '</u></td></tr>
    <tr><td width="20%">Strand:</td><td width="80%">' . htmlspecialchars($strand) . '</td></tr>
</table>
<table border="1" cellpadding="3" style="font-size:7pt; margin-top:10px; width:100%;">
    <thead>
        <tr style="text-align:center; font-weight:bold;">
            <th width="10%">Day</th><th width="15%">AM In</th><th width="15%">AM Out</th><th width="15%">PM In</th><th width="15%">PM Out</th><th width="30%" colspan="2">Undertime</th>
        </tr>
    </thead>
    <tbody>';

for ($i = 1; $i <= 31; $i++) {
    $am_in = $logs[$i]['am_in'] ?? '';
    $am_out = $logs[$i]['am_out'] ?? '';
    $pm_in = $logs[$i]['pm_in'] ?? '';
    $pm_out = $logs[$i]['pm_out'] ?? '';
    $html .= '<tr style="text-align:center;"><td>'.$i.'</td><td>'.$am_in.'</td><td>'.$am_out.'</td><td>'.$pm_in.'</td><td>'.$pm_out.'</td><td></td><td></td></tr>';
}

$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');

// 4. CLEAN BUFFER AND OUTPUT
ob_end_clean();

// Mode 'D' forces the browser to download the file, which is safer than 'I' (viewing inline)
$filename = 'DTR_' . str_replace(' ', '_', $student_name) . '.pdf';
$pdf->Output($filename, 'D'); 
exit;
?>
