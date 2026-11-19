<?php
/**
 * i-Tracker DTR Generator
 * Fixes: 500 Errors and "Dangerous Site" warnings.
 */

// Start output buffering to catch any accidental echos or whitespace
ob_start();
error_reporting(0); // Suppress errors in production to avoid corrupting PDF binary
date_default_timezone_set('Asia/Manila');

// 1. Check for TCPDF
$tcpdf_path = __DIR__ . '/tcpdf/tcpdf.php';
if (!file_exists($tcpdf_path)) {
    ob_end_clean();
    header("HTTP/1.1 500 Internal Server Error");
    die("TCPDF library not found.");
}
require_once($tcpdf_path);
require_once(__DIR__ . '/db_config.php');

$conn = project_db_connection();
if (!$conn) {
    ob_end_clean();
    header("HTTP/1.1 500 Internal Server Error");
    die("Database connection failed.");
}

// 2. Sanitize Inputs
$student_name = isset($_GET['student_name']) ? trim($_GET['student_name']) : "Student";
$month_param = isset($_GET['month']) ? trim($_GET['month']) : date('F Y');
$strand = "N/A";

// Resolve Strand from DB to handle special characters correctly
$stmt = $conn->prepare("SELECT strand FROM students WHERE student_name = ? LIMIT 1");
$stmt->bind_param("s", $student_name);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $strand = $row['strand'];
}
$stmt->close();

// 3. Fetch logs
$ts = strtotime($month_param);
$start = date('Y-m-01 00:00:00', $ts);
$end = date('Y-m-t 23:59:59', $ts);

$query = "SELECT log_time, status FROM attendance_logs 
          WHERE student_name = ? AND log_time BETWEEN ? AND ? 
          ORDER BY log_time ASC";
$logStmt = $conn->prepare($query);
$logStmt->bind_param("sss", $student_name, $start, $end);
$logStmt->execute();
$result = $logStmt->get_result();

$logs = [];
while ($row = $result->fetch_assoc()) {
    $timeTs = strtotime($row['log_time']);
    $day = (int)date('j', $timeTs);
    $time = date('g:i A', $timeTs);
    $is_am = (int)date('H', $timeTs) < 12;

    if (stripos($row['status'], '(IN)') !== false) {
        $is_am ? ($logs[$day]['am_in'] = $time) : ($logs[$day]['pm_in'] = $time);
    } else {
        $is_am ? ($logs[$day]['am_out'] = $time) : ($logs[$day]['pm_out'] = $time);
    }
}

// 4. Create PDF
$pdf = new TCPDF('P', 'mm', array(105, 290), true, 'UTF-8', false);
$pdf->SetTitle('DTR_' . $student_name);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(8, 10, 8);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 8);

$html = '<div style="text-align:center;"><strong>MARABUT NATIONAL HIGH SCHOOL</strong><br>Daily Time Record</div>';
$html .= '<table width="100%" cellpadding="2" style="font-size:8pt; margin-top:10px;">
    <tr><td>Name: <strong>'.strtoupper(htmlspecialchars($student_name)).'</strong></td></tr>
    <tr><td>Strand: '.htmlspecialchars($strand).'</td></tr>
    <tr><td>Month: '.$month_param.'</td></tr>
</table>';

$html .= '<table border="1" cellpadding="2" cellspacing="0" style="font-size:7pt; margin-top:10px; text-align:center;">
    <thead><tr style="font-weight:bold;">
        <th width="10%">Day</th><th width="22.5%">AM In</th><th width="22.5%">AM Out</th><th width="22.5%">PM In</th><th width="22.5%">PM Out</th>
    </tr></thead><tbody>';
for ($i = 1; $i <= 31; $i++) {
    $html .= '<tr><td>'.$i.'</td>
        <td>'.($logs[$i]['am_in']??'').'</td><td>'.($logs[$i]['am_out']??'').'</td>
        <td>'.($logs[$i]['pm_in']??'').'</td><td>'.($logs[$i]['pm_out']??'').'</td></tr>';
}
$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');

// 5. Output PDF with Strict Headers
$pdf_binary = $pdf->Output('', 'S');

// Clear buffer one last time
while (ob_get_level()) { ob_end_clean(); }

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="DTR_Report.pdf"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . strlen($pdf_binary));
header('Cache-Control: private, max-age=0, must-revalidate');

echo $pdf_binary;
exit;
