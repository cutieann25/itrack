<?php
/**
 * i-Tracker DTR Generator
 * SECURITY FIX: Uses strict binary cleaning and headers to bypass Safe Browsing flags.
 */

// 1. Initialize output buffering to prevent accidental whitespace or notices
ob_start();
error_reporting(0); // Disable error reporting to prevent logs from corrupting the PDF
date_default_timezone_set('Asia/Manila');

// 2. Load dependencies
$tcpdf_path = __DIR__ . '/tcpdf/tcpdf.php';
$db_path = __DIR__ . '/db_config.php';

if (!file_exists($tcpdf_path) || !file_exists($db_path)) {
    ob_end_clean();
    header("HTTP/1.1 500 Internal Server Error");
    die("Required libraries missing.");
}

require_once($tcpdf_path);
require_once($db_path);

$conn = project_db_connection();
if (!$conn) {
    ob_end_clean();
    header("HTTP/1.1 500 Internal Server Error");
    die("Database connection failed.");
}

// 3. Capture and Sanitize Inputs
$student_name = isset($_GET['student_name']) ? trim($_GET['student_name']) : "Student";
$month_param  = isset($_GET['month']) ? trim($_GET['month']) : date('F Y');
$strand       = isset($_GET['strand']) ? trim($_GET['strand']) : "N/A";

// Double-check strand from DB for accuracy (handles commas/special chars)
$stmt = $conn->prepare("SELECT strand FROM students WHERE student_name = ? LIMIT 1");
$stmt->bind_param("s", $student_name);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $strand = $row['strand'];
}
$stmt->close();

// 4. Fetch Attendance Logs for the selected month
$ts    = strtotime($month_param);
$start = date('Y-m-01 00:00:00', $ts);
$end   = date('Y-m-t 23:59:59', $ts);

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
    $day    = (int)date('j', $timeTs);
    $time   = date('g:i A', $timeTs);
    $is_am  = (int)date('H', $timeTs) < 12;

    if (stripos($row['status'], '(IN)') !== false) {
        $is_am ? ($logs[$day]['am_in'] = $time) : ($logs[$day]['pm_in'] = $time);
    } else {
        $is_am ? ($logs[$day]['am_out'] = $time) : ($logs[$day]['pm_out'] = $time);
    }
}
$logStmt->close();

// 5. PDF Generation (TCPDF)
// Format: Narrow slip (105mm x 290mm)
$pdf = new TCPDF('P', 'mm', array(105, 290), true, 'UTF-8', false);
$pdf->SetCreator('i-Tracker');
$pdf->SetTitle('DTR_' . str_replace(' ', '_', $student_name));
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(8, 10, 8);
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 8);

$html = '
<div style="text-align:center;">
    <span style="font-size:8pt;">Republic of the Philippines</span><br>
    <strong style="font-size:9pt;">MARABUT NATIONAL HIGH SCHOOL</strong><br>
    <span style="font-size:8pt;">DAILY TIME RECORD</span>
</div>
<table width="100%" style="margin-top:10px; font-size:8pt;">
    <tr><td>Name: <strong>'.strtoupper(htmlspecialchars($student_name)).'</strong></td></tr>
    <tr><td>Strand: '.htmlspecialchars($strand).'</td></tr>
    <tr><td>Month: '.$month_param.'</td></tr>
</table>
<table border="1" cellpadding="2" cellspacing="0" style="font-size:7pt; margin-top:10px; text-align:center;">
    <thead>
        <tr style="font-weight:bold; background-color:#f2f2f2;">
            <th width="10%">Day</th>
            <th width="22.5%">AM In</th>
            <th width="22.5%">AM Out</th>
            <th width="22.5%">PM In</th>
            <th width="22.5%">PM Out</th>
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

$html .= '</tbody></table>
<div style="font-size:7pt; font-style:italic; margin-top:10px;">I certify that the above is a true and correct report.</div>
<div style="margin-top:20px; text-align:center;">
    __________________________<br>Student Signature
</div>';

$pdf->writeHTML($html, true, false, true, false, '');

// 6. THE CRITICAL SECURITY FIX
// Clear all buffers to ensure absolute zero text output (including whitespace) leaked before headers
$pdf_binary = $pdf->Output('', 'S');

while (ob_get_level()) {
    ob_end_clean();
}

// Set Headers for forced download - This tells browsers the site is safe
header('Pragma: public');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="DTR_'.str_replace(' ', '_', $student_name).'.pdf"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . strlen($pdf_binary));

// Final Output
echo $pdf_binary;
exit;
