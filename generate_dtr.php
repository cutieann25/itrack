<?php
ob_start();
date_default_timezone_set('Asia/Manila');
require_once('tcpdf/tcpdf.php');
require_once __DIR__ . '/db_config.php';

// Database Connection
$conn = project_db_connection();

$student_name = isset($_GET['student_name']) ? trim($_GET['student_name']) : "________________________";
$strand = isset($_GET['strand']) ? trim($_GET['strand']) : "";
$period_label = 'All Recorded Attendance';

$student_id = isset($_GET['student_id']) ? trim($_GET['student_id']) : null;
$student_lookup = $student_id !== null && $student_id !== '' ? $student_id : $student_name;
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

// Fetch all attendance data for this student, regardless of month.
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
    if ($student_started_on !== null && $date_key < $student_started_on) {
        continue;
    }

    // Number each row from this student's registration/training start date.
    $start_date = new DateTimeImmutable($dtr_start_date);
    $log_date = new DateTimeImmutable($date_key);
    $day = $start_date->diff($log_date)->days + 1;
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

// Custom Size for narrow DTR slip (100mm wide x 285mm tall)
$pdf = new TCPDF('P', 'mm', array(105, 290), true, 'UTF-8', false);
$pdf->SetCreator('i-Tracker');
$pdf->SetTitle('DTR - ' . $student_name);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(8, 10, 8); // Increased top margin to 10mm
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 8);

$html = '
<div style="text-align:center; font-family:helvetica; font-size:8pt; line-height:10pt;">
    Republic of the Philippines<br>
    Department of Education<br>
    Region VIII<br>
    Division of Samar<br>
    <strong style="font-size:9pt; line-height:12pt;">MARABUT NATIONAL HIGH SCHOOL</strong><br>
    Senior High School
</div>

<div style="text-align:center; font-family:helvetica; font-size:12pt; font-weight:bold; margin-top:6px; margin-bottom:8px;">DAILY TIME RECORD</div>

<table width="100%" cellpadding="1" cellspacing="0" style="font-family:helvetica; font-size:8pt; margin-bottom:6px;">
    <tr>
        <td width="20%"><strong>Name:</strong></td>
        <td width="80%"><strong style="text-decoration:underline;">' . strtoupper(htmlspecialchars($student_name)) . '</strong></td>
    </tr>
    <tr>
        <td width="20%"><strong>Period:</strong></td>
        <td width="80%"><strong>' . htmlspecialchars($period_label) . '</strong></td>
    </tr>
    <tr>
        <td width="20%"><strong>Strand:</strong></td>
        <td width="80%"><strong>' . htmlspecialchars($strand) . '</strong></td>
    </tr>
</table>

<div style="font-family:helvetica;">
    <table border="1" cellpadding="3" cellspacing="0" style="font-size:7pt; margin-top:4px; border-collapse:collapse; width:100%; table-layout:fixed;">
        <colgroup>
            <col width="10%" />
            <col width="15%" />
            <col width="15%" />
            <col width="15%" />
            <col width="15%" />
            <col width="15%" />
            <col width="15%" />
        </colgroup>
        <thead>
            <tr style="text-align:center; font-weight:bold; border-bottom:none;">
                <th width="10%" rowspan="2" style="vertical-align:middle; border-bottom:none;">Day</th>
                <th width="30%" colspan="2" style="border-bottom:none;">A. M.</th>
                <th width="30%" colspan="2" style="border-bottom:none;">P. M.</th>
                <th width="30%" colspan="2" style="border-bottom:none;">UNDERTIME</th>
            </tr>
            <tr style="text-align:center; font-weight:bold; border-top:none;">
                <th width="15%" style="border-top:none;">Arrival</th>
                <th width="15%" style="border-top:none;">Depart</th>
                <th width="15%" style="border-top:none;">Arrival</th>
                <th width="15%" style="border-top:none;">Depart</th>
                <th width="15%" style="border-top:none;">Hrs</th>
                <th width="15%" style="border-top:none;">Min</th>
            </tr>
        </thead>
        <tbody>';

function dtr_slot_should_be_blank($date, $slot_type) {
    $today = date('Y-m-d');
    $current_time = date('H:i');

    if ($date > $today) {
        return true;
    }

    if ($date < $today) {
        return false;
    }

    if ($slot_type === 'am') {
        return $current_time < '12:00';
    }

    if ($slot_type === 'pm') {
        return $current_time < '18:00';
    }

    return false;
}

$training_start = new DateTimeImmutable($dtr_start_date);
$day_count = 31;

for ($i = 1; $i <= $day_count; $i++) {
    $row_date = $training_start->modify('+' . ($i - 1) . ' days')->format('Y-m-d');
    $am_in_value = isset($logs[$i]['am_in']) ? $logs[$i]['am_in'] : null;
    $am_out_value = isset($logs[$i]['am_out']) ? $logs[$i]['am_out'] : null;
    $pm_in_value = isset($logs[$i]['pm_in']) ? $logs[$i]['pm_in'] : null;
    $pm_out_value = isset($logs[$i]['pm_out']) ? $logs[$i]['pm_out'] : null;

    $am_in = $am_in_value !== null ? $am_in_value : (dtr_slot_should_be_blank($row_date, 'am') ? '' : 'Absent');
    $am_out = $am_out_value !== null ? $am_out_value : (dtr_slot_should_be_blank($row_date, 'am') ? '' : 'Absent');
    $pm_in = $pm_in_value !== null ? $pm_in_value : (dtr_slot_should_be_blank($row_date, 'pm') ? '' : 'Absent');
    $pm_out = $pm_out_value !== null ? $pm_out_value : (dtr_slot_should_be_blank($row_date, 'pm') ? '' : 'Absent');

    $html .= '<tr style="text-align:center; line-height:7pt;">
                <td width="10%">' . $i . '</td>
                <td width="15%">' . $am_in . '</td>
                <td width="15%">' . $am_out . '</td>
                <td width="15%">' . $pm_in . '</td>
                <td width="15%">' . $pm_out . '</td>
                <td width="15%"></td>
                <td width="15%"></td>
              </tr>';
}

$html .= '
        <tr style="text-align:left; font-weight:bold;">
            <td colspan="2" width="25%" style="white-space:nowrap;">TOTAL</td>
            <td width="15%"></td>
            <td width="15%"></td>
            <td width="15%"></td>
            <td width="15%"></td>
            <td width="15%"></td>
        </tr>
        </tbody>
    </table>

    <div style="text-align:left; font-size:7pt; margin-top:25px; line-height:8pt; font-style:italic;">
        I CERTIFY on my honor that the above is a true and correct report of the hours of work performed,
        record of which was made daily at the time of arrival and departure from office.
    </div>

    <table width="100%" style="margin-top:10px; font-size:9pt; text-align:right;">
        <tr>
            <td style="padding-right:10px;"><br>__________________________<br><b>' . strtoupper(htmlspecialchars($student_name)) . '</b><br><span style="font-size:7pt;">Student Signature</span></td>
        </tr>
        <tr>
            <td style="text-align:right; font-size:7pt; padding-right:10px;"><br>Verified as to the prescribed office hours:</td>
        </tr>
        <tr>
            <td style="padding-right:10px;"><br>__________________________<br><b>MYLENE M. AMANTILLO</b></td>
        </tr>
    </table>
    <div style="font-size:6pt; text-align:right; margin-top:2px; padding-right:10px;">(See Instructions on back)</div>
</div>';

ob_end_clean();
$pdf->writeHTMLCell(0, 0, '', '', $html, 0, 1, 0, true, '', true);
$pdf->Output('DTR_' . str_replace(' ', '_', $student_name) . '.pdf', 'I');
?>