<?php
ob_start();
date_default_timezone_set('Asia/Manila');
require_once('tcpdf/tcpdf.php');
require_once __DIR__ . '/db_config.php';

// Database Connection
$conn = project_db_connection();

$student_name = isset($_GET['student_name']) ? trim($_GET['student_name']) : "________________________";
$strand = isset($_GET['strand']) ? trim($_GET['strand']) : "";

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
$report_date = $dtr_start_date;

foreach ($all_logs as $row) {
    $ts = strtotime($row['log_time']);
    $date_key = date('Y-m-d', $ts);
    if ($student_started_on !== null && $date_key < $student_started_on) {
        continue;
    }
    $report_date = $date_key;

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
$report_month = date('F', strtotime($report_date));
$report_year = date('Y', strtotime($report_date));

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
        <td colspan="2" style="font-size:7pt;"><i>For the month of <u>' . htmlspecialchars($report_month) . '</u>, <u>' . htmlspecialchars($report_year) . '</u></i></td>
    </tr>
    <tr>
        <td width="47%" style="font-size:6pt; line-height:7pt;">Official hours for arrival<br>and departure</td>
        <td width="53%" style="font-size:6pt; line-height:7pt;">Regular days <u>____________</u><br>Saturdays <u>____________</u></td>
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

    if ((int)(new DateTimeImmutable($date))->format('N') >= 6) {
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

    if ((int)(new DateTimeImmutable($row_date))->format('N') >= 6) {
        $am_in_value = $am_out_value = $pm_in_value = $pm_out_value = null;
    }

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

    <table width="100%" style="margin-top:10px; font-size:9pt;">
        <tr>
            <td style="text-align:right; padding-right:10px;"><br>__________________________<br><b>' . strtoupper(htmlspecialchars($student_name)) . '</b><br><span style="font-size:7pt;">Student Signature</span></td>
        </tr>
        <tr>
            <td style="padding:0;">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr><td height="3" style="border-bottom:0.5px solid #000;"></td></tr>
                    <tr><td height="3" style="border-bottom:0.5px solid #000;"></td></tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="text-align:left; font-size:7pt; font-style:italic; padding-top:2px;">Verified as to the prescribed office hours.</td>
        </tr>
        <tr>
            <td style="text-align:right; padding-right:10px;"><br>__________________________<br><b>MYLENE M. AMANTILLO</b></td>
        </tr>
    </table>
    <div style="font-size:6pt; text-align:right; margin-top:2px; padding-right:10px;">(See Instructions on back)</div>
</div>';

ob_end_clean();
$pdf->writeHTMLCell(0, 0, '', '', $html, 0, 1, 0, true, '', true);

$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);
$instructions_html = <<<'HTML'
<div style="font-family:helvetica; font-size:10pt; line-height:1.3; text-align:justify;">
    <div style="text-align:center; font-size:11pt; font-weight:bold; margin-bottom:10px;">INSTRUCTIONS</div>

    <p style="margin-bottom:8px;">Civil Service Form No. 48, after completion, should be filed in the records of the Bureau or Office which submits the monthly report on Civil Service Form No. 3 to the Bureau of Civil Service.</p>

    <p style="margin-bottom:8px;">In lieu of the above, court interpreters and stenographers who accompany the judges of the Court of First Instance will fill out the daily time reports on this form in triplicate, after which they should be approved by the judge with whom service has been rendered, or by an officer of the Department of Justice authorized to do so. The original should be forwarded promptly after the end of the month to the Bureau of Civil Service, thru the Department of Justice, the duplicate to be kept in the Department of Justice; and the triplicate, in the office of the Clerk of Court where service was rendered.</p>

    <p style="margin-bottom:8px;">In the space provided for the purpose on the other side will be indicated the office hours the employee is required to observe, as for example, "Regular days, 8:00 to 12:00 and 1:00 to 4:00; Saturdays, 8:00 to 1:00."</p>

    <p style="margin-bottom:4px;">Attention is invited to paragraph 3, Civil Service Rule XV, Executive Order No. 5, series of 1909, which reads as follows:</p>

    <p style="margin:0 0 8px 8px;">"Each chief of a Bureau or Office shall require a daily record of attendance of all the officers and employees under him entitled to leave or absence on vacation (including teachers) to be kept on the proper form and also a systematic office record showing for each day all absences from duty from any cause whatever. At the beginning of each month he shall report to the Commission on the proper form of all absences from any cause whatever, including the exact amount of undertime of each person for each day. Officers or employees serving in the field or on the water need not be required to keep a daily record, but all absences of such employees must be included in the monthly report of changes and absences. Falsification of time records will render the offending officer or employee liable to summary removal from the service and criminal prosecution."</p>

    <p style="font-size:7pt; line-height:1.2; margin-top:12px;">(NOTE - A record made from memory at some time subsequent to the occurrence of an event is not reliable. Non observance of office hours deprives the employee of the leave privileges although he may have rendered overtime service. Where service rendered outside of the Office for the whole morning or afternoon, notation to that effect should be made clearly.)</p>
</div>
HTML;
$pdf->writeHTMLCell(0, 0, '', '', $instructions_html, 0, 1, 0, true, '', true);
$pdf->Output('DTR_' . str_replace(' ', '_', $student_name) . '.pdf', 'I');
?>
