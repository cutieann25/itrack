<?php
/**
 * i-Tracker DTR Generator
 * Fixes: "Dangerous Site" warnings via strict binary headers.
 */

// 1. Prevent accidental whitespace from corrupting the PDF
ob_start();
date_default_timezone_set('Asia/Manila');

require_once('tcpdf/tcpdf.php');
require_once __DIR__ . '/db_config.php';

$conn = project_db_connection();

// 2. Inputs
$student_name = isset($_GET['student_name']) ? trim($_GET['student_name']) : "________________________";
$strand = isset($_GET['strand']) ? trim($_GET['strand']) : "";
$month_param = isset($_GET['month']) ? trim($_GET['month']) : date('F Y');

// Resolve full strand from DB
$stmt = $conn->prepare('SELECT strand FROM students WHERE student_name = ? LIMIT 1');
$stmt->bind_param('s', $student_name);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $strand = $row['strand'];
}

// Fetch Logs
$timestamp_month = strtotime($month_param);
$start = date('Y-m-01 00:00:00', $timestamp_month);
$end = date('Y-m-t 23:59:59', $timestamp_month);

$query = "SELECT log_time, status FROM attendance_logs WHERE student_name = ? AND log_time BETWEEN ? AND ? ORDER BY log_time ASC";
$logStmt = $conn->prepare($query);
$logStmt->bind_param("sss", $student_name, $start, $end);
$logStmt->execute();
$logRes = $logStmt->get_result();

$logs = [];
while ($row = $logRes->fetch_assoc()) {
    $ts = strtotime($row['log_time']);
    $day = (int)date('j', $ts);
    $time = date('g:i A', $ts);
    $is_am = (int)date('H', $ts) < 12;

    if (stripos($row['status'], '(IN)') !== false) {
        $is_am ? ($logs[$day]['am_in'] = $time) : ($logs[$day]['pm_in'] = $time);
    } else {
        $is_am ? ($logs[$day]['am_out'] = $time) : ($logs[$day]['pm_out'] = $time);
    }
}

// 3. Setup TCPDF
$pdf = new TCPDF('P', 'mm', array(105, 290), true, 'UTF-8', false);
$pdf->SetTitle('DTR - ' . $student_name);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(8, 10, 8);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 8);

$html = '<div style="text-align:center;">
    <strong>MARABUT NATIONAL HIGH SCHOOL</strong><br>
    <span>Daily Time Record</span>
</div>
<table width="100%" style="margin-top:10px;">
    <tr><td>Name: <strong>'.strtoupper(htmlspecialchars($student_name)).'</strong></td></tr>
    <tr><td>Strand: '.htmlspecialchars($strand).'</td></tr>
    <tr><td>Month: '.$month_param.'</td></tr>
</table>
<table border="1" cellpadding="2" style="text-align:center; font-size:7pt;">
    <thead>
        <tr style="font-weight:bold;">
            <th width="10%">Day</th><th width="22%">AM In</th><th width="22%">AM Out</th><th width="22%">PM In</th><th width="24%">PM Out</th>
        </tr>
    </thead>
    <tbody>';

for ($i = 1; $i <= 31; $i++) {
    $html .= '<tr>
        <td>'.$i.'</td>
        <td>'.($logs[$i]['am_in'] ?? '').'</td>
        <td>'.($logs[$i]['am_out'] ?? '').'</td>
        <td>'.($logs[$i]['pm_in'] ?? '').'</td>
        <td>'.($logs[$i]['pm_out'] ?? '').'</td>
    </tr>';
}
$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');

// 4. CRITICAL FIX FOR DANGEROUS SITE WARNING
// Clear all buffers to ensure absolute zero text output before headers
while (ob_get_level()) { ob_end_clean(); }

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="DTR_Report.pdf"');
header('Content-Transfer-Encoding: binary');

echo $pdf->Output('DTR.pdf', 'S');
exit;
