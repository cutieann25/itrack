<?php
require_once 'auth.php';
$user = require_login(['supervisor']);
$conn = db_connection();
$message = '';
$error = '';
$profile_message = '';
$profile_error = '';

// Create folders if they don't exist
if (!file_exists(__DIR__ . '/uploads/profile/')) {
    mkdir(__DIR__ . '/uploads/profile/', 0777, true);
}

$current_profile_image = '';
$current_profile_stmt = $conn->prepare('SELECT display_name, profile_image FROM users WHERE id = ? LIMIT 1');
if ($current_profile_stmt) {
    $current_profile_stmt->bind_param('i', $user['id']);
    $current_profile_stmt->execute();
    $current_profile = $current_profile_stmt->get_result()->fetch_assoc();
    if ($current_profile) {
        $user['display_name'] = $current_profile['display_name'];
        $_SESSION['user']['display_name'] = $current_profile['display_name'];
        $current_profile_image = $current_profile['profile_image'] ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_update'])) {
    $new_display_name = trim($_POST['display_name'] ?? '');
    $new_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $profile_image = $_FILES['profile_image'] ?? null;

    if ($new_display_name === '') {
        $profile_error = 'Display name is required.';
    } elseif ($new_password !== '' && strlen($new_password) < 6) {
        $profile_error = 'Password must be at least 6 characters long.';
    } elseif ($new_password !== '' && $new_password !== $confirm_password) {
        $profile_error = 'Passwords do not match.';
    } else {
        $updates = [];
        $bind_values = [];
        $bind_types = '';
        $updates[] = 'display_name = ?';
        $bind_values[] = $new_display_name;
        $bind_types .= 's';

        if ($new_password !== '') {
            $updates[] = 'password_hash = ?';
            $bind_values[] = password_hash($new_password, PASSWORD_DEFAULT);
            $bind_types .= 's';
        }

        if ($profile_image && $profile_image['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
            $file_type = mime_content_type($profile_image['tmp_name']) ?: $profile_image['type'];
            if (!in_array($file_type, $allowed_types, true)) {
                $profile_error = 'Profile image must be a JPEG, PNG, GIF, or WEBP file.';
            } elseif ($profile_image['size'] > 2097152) {
                $profile_error = 'Profile image must be 2MB or smaller.';
            } else {
                $extension = pathinfo($profile_image['name'], PATHINFO_EXTENSION);
                $safe_name = 'profile_' . $user['id'] . '_' . time() . '.' . strtolower($extension ?: 'png');
                $target_path = __DIR__ . '/uploads/profile/' . $safe_name;

                if (move_uploaded_file($profile_image['tmp_name'], $target_path)) {
                    $updates[] = 'profile_image = ?';
                    $bind_values[] = 'uploads/profile/' . $safe_name;
                    $bind_types .= 's';
                } else {
                    $profile_error = 'The profile image could not be uploaded.';
                }
            }
        }

        if ($profile_error === '') {
            $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $conn->prepare($sql);
            $bind_values[] = $user['id'];
            $bind_types .= 'i';
            $stmt->bind_param($bind_types, ...$bind_values);

            if ($stmt->execute()) {
                $_SESSION['user']['display_name'] = $new_display_name;
                $user['display_name'] = $new_display_name;
                $profile_message = 'Profile updated successfully.';
            } else {
                $profile_error = 'The profile could not be saved.';
            }
        }
    }
}

$rubric_sections = [
    'team_work' => ['title' => 'Team Work', 'items' => [
        'Consistently works with others to accomplish goals and tasks.',
        'Treats all team members in a respectful courteous manner.',
        'Actively participates in activities and assigned tasks required.',
        'Willingly works on a continuous basis to improve the team.',
        'Demonstrates a positive attitude and views of team members when completing assigned task.'
    ]],
    'communication' => ['title' => 'Communication', 'items' => [
        'Actively listens to supervisor and/or co-workers.',
        'Comprehends written and oral information.',
        'Consistently delivers accurate information both written and oral.',
        'Reliably provides feedback as required, both internally and externally.'
    ]],
    'attendance_punctuality' => ['title' => 'Attendance and Punctuality', 'items' => [
        'Is punctual on a regular basis.',
        'Maintains good attendance.',
        'Informs supervisor in a timely manner when absenteeism and tardiness may occur.'
    ]],
    'productivity_resilience' => ['title' => 'Productivity / Resilience', 'items' => [
        'Consistently produces quality results.',
        'Meets deadlines and manages time well.',
        'Can do multitasking.',
        'Can work under pressure and delivers the required tasks.',
        'Effective and efficient in time management.',
        'Efficiently informs supervisor of any challenge or hindrance related to given task or assignment.'
    ]],
    'initiative_proactivity' => ['title' => 'Initiative / Proactivity', 'items' => [
        'Completes assignments with minimum supervision.',
        'Completes tasks independently and consistently.',
        'Seeks support as need arises.',
        'Recognizes and takes immediate action to effectively address problems and opportunities.',
        'Engages in continuous learning.',
        'Contributes new ideas and shares skills to improve the department/organization.'
    ]],
    'judgement_decision_making' => ['title' => 'Judgement / Decision Making', 'items' => [
        'Analyzes problems effectively.',
        'Has the ability to make creative and effective solutions to problems.',
        'Demonstrates food judgment in handling routine problems.'
    ]],
    'dependability_reliability' => ['title' => 'Dependability / Reliability', 'items' => [
        'Has the ability to follow through and meet deadlines.',
        'Has commitment for his/her action.',
        'Can adjust easily to changes in workplace.',
        'Displays high level of performance at all times.'
    ]],
    'attitude' => ['title' => 'Attitude', 'items' => [
        'Offers assistance willingly.',
        'Shows a positive work attitude.',
        'Shows sensitivity and consideration for other’s feelings.',
        'Accepts criticism positively.',
        'Shows pride in work.'
    ]],
    'professionalism' => ['title' => 'Professionalism', 'items' => [
        'Respects persons in authority.',
        'Uses all tools, equipment and facilities responsibly.',
        'Follows all policies and procedures when issues and conflict arises.',
        'Physical appearance conforms with the workplace and placement rules.'
    ]]
];

$rubric_scores = [];
foreach ($rubric_sections as $section_key => $section) {
    foreach ($section['items'] as $item_index => $item) {
        $rubric_scores[$section_key][$item_index] = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['profile_update'])) {
    $student_id = trim($_POST['student_id'] ?? '');
    $evaluation_date = $_POST['evaluation_date'] ?? date('Y-m-d');
    $partner_institution = trim($_POST['partner_institution'] ?? '');
    $appraisal_address = trim($_POST['appraisal_address'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $immersion_supervisor = trim($_POST['immersion_supervisor'] ?? '');
    $supervisor_position = trim($_POST['supervisor_position'] ?? '');
    if ($supervisor_position === '' || strtolower($supervisor_position) === 'student') {
        $supervisor_position = 'Work Immersion Supervisor';
    }
    $training_start_date = $_POST['training_start_date'] ?? null;
    $training_end_date = $_POST['training_end_date'] ?? null;
    $total_hours_rendered = (float)($_POST['total_hours_rendered'] ?? 0);
    $strand = trim($_POST['strand'] ?? '');
    $punctuality = (float)($_POST['punctuality'] ?? 0);
    $work_quality = (float)($_POST['work_quality'] ?? 0);
    $rating_attitude = (float)($_POST['attitude'] ?? 0);
    $teamwork = (float)($_POST['teamwork'] ?? 0);
    $overall_performance_rating = (float)($_POST['overall_performance_rating'] ?? 0);
    $comments = trim($_POST['comments'] ?? '');
    $student_signature = trim($_POST['student_signature'] ?? '');
    $supervisor_signature = trim($_POST['supervisor_signature'] ?? '');
    $submitted_rubric = $_POST['rubric'] ?? [];
    $rubric_total = 0;
    $rubric_count = 0;
    
    foreach ($rubric_sections as $section_key => $section) {
        foreach ($section['items'] as $item_index => $item) {
            $raw_score = $submitted_rubric[$section_key][$item_index] ?? '';
            $normalized_score = is_string($raw_score) ? trim($raw_score) : $raw_score;

            if ($normalized_score === '' || $normalized_score === null) {
                $rubric_scores[$section_key][$item_index] = 0;
                continue;
            }

            if (strtoupper((string)$normalized_score) === 'NA') {
                $rubric_scores[$section_key][$item_index] = 'NA';
                continue;
            }

            $score = (float)$normalized_score;
            $rubric_scores[$section_key][$item_index] = $score;
            $rubric_total += $score;
            $rubric_count++;
        }
    }
    $weighted_average = $rubric_count > 0 ? round($rubric_total / $rubric_count, 2) : 0;
    $equivalent_grade = $weighted_average >= 4.50 ? 100 : ($weighted_average >= 3.40 ? 95 : ($weighted_average >= 2.60 ? 90 : ($weighted_average >= 1.80 ? 85 : 80)));

    if ($student_id === '') {
        $error = 'Select a student to complete performance appraisal.';
    } else {
        $rubric_json = json_encode($rubric_scores, JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare('INSERT INTO performance_evaluations (student_id, evaluator_id, evaluation_date, partner_institution, appraisal_address, contact_number, immersion_supervisor, supervisor_position, training_start_date, training_end_date, total_hours_rendered, strand, punctuality, work_quality, attitude, teamwork, rubric_scores, overall_performance_rating, weighted_average, equivalent_grade, comments, student_signature, supervisor_signature) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sissssssssdsddddsdddsss', $student_id, $user['id'], $evaluation_date, $partner_institution, $appraisal_address, $contact_number, $immersion_supervisor, $supervisor_position, $training_start_date, $training_end_date, $total_hours_rendered, $strand, $punctuality, $work_quality, $rating_attitude, $teamwork, $rubric_json, $overall_performance_rating, $weighted_average, $equivalent_grade, $comments, $student_signature, $supervisor_signature);
        if ($stmt->execute()) {
            $message = 'Performance evaluation appraisal sheet submitted successfully.';
        } else {
            $error = 'The evaluation could not be saved to your database.';
        }
    }
}

$students = [];
$student_profile_map = [];
$student_details_lookup = [];
$student_details_result = $conn->query('SELECT student_name, strand, address, phone, email, parents, gender, dob FROM students');
if ($student_details_result) {
    while ($detail_row = $student_details_result->fetch_assoc()) {
        $detail_name = trim((string)($detail_row['student_name'] ?? ''));
        if ($detail_name !== '') {
            $student_details_lookup[strtolower($detail_name)] = [
                'student_name' => $detail_name,
                'strand' => $detail_row['strand'] ?? '',
                'address' => $detail_row['address'] ?? '',
                'phone' => $detail_row['phone'] ?? '',
                'email' => $detail_row['email'] ?? '',
                'parents' => $detail_row['parents'] ?? '',
                'gender' => $detail_row['gender'] ?? '',
                'dob' => $detail_row['dob'] ?? '',
            ];
        }
    }
}

$all_studs = $conn->query("SELECT student_name FROM students WHERE status='APPROVED'");
if ($all_studs) {
    while ($srow = $all_studs->fetch_assoc()) {
        $sname = $srow['student_name'];
        $student_record = $student_details_lookup[strtolower($sname)] ?? [];
        
        $hours_stmt = $conn->prepare("SELECT log_time, status FROM attendance_logs WHERE student_name = ? ORDER BY log_time ASC");
        $rendered_hours = 0;
        if ($hours_stmt) {
            $hours_stmt->bind_param('s', $sname);
            $hours_stmt->execute();
            $hours_result = $hours_stmt->get_result();
            $total_minutes = 0;
            $pending_in = null;
            while ($log_row = $hours_result->fetch_assoc()) {
                $status = strtolower(trim((string)($log_row['status'] ?? '')));
                $timestamp = strtotime($log_row['log_time']);
                if (strpos($status, 'in') !== false) {
                    $pending_in = $timestamp;
                } else if (strpos($status, 'out') !== false && $pending_in !== null) {
                    $total_minutes += ($timestamp - $pending_in) / 60;
                    $pending_in = null;
                }
            }
            $rendered_hours = round($total_minutes / 60, 2);
        }

        $students[] = [
            'student_id' => $sname,
            'student_name' => $sname,
            'strand' => $student_record['strand'] ?? '',
            'address' => $student_record['address'] ?? '',
            'phone' => $student_record['phone'] ?? '',
            'email' => $student_record['email'] ?? '',
            'parents' => $student_record['parents'] ?? '',
            'gender' => $student_record['gender'] ?? '',
            'dob' => $student_record['dob'] ?? '',
            'agency_name' => 'Marabut NHS Partner Office',
            'supervisor_name' => $user['display_name'] ?? 'Supervisor',
            'total_hours_rendered' => $rendered_hours
        ];

        $student_profile_map[$sname] = [
            'student_id' => $sname,
            'student_name' => $sname,
            'strand' => $student_record['strand'] ?? '',
            'address' => $student_record['address'] ?? '',
            'phone' => $student_record['phone'] ?? '',
            'email' => $student_record['email'] ?? '',
            'parents' => $student_record['parents'] ?? '',
            'gender' => $student_record['gender'] ?? '',
            'dob' => $student_record['dob'] ?? '',
            'partner_institution' => 'Marabut NHS Partner Office',
            'supervisor_name' => $user['display_name'] ?? 'Supervisor',
            'total_hours_rendered' => $rendered_hours
        ];
    }
}

$evaluations = [];
$evaluation_result = $conn->query('SELECT * FROM performance_evaluations ORDER BY evaluation_date DESC');
if ($evaluation_result) {
    while ($row = $evaluation_result->fetch_assoc()) {
        $row['evaluator_name'] = $user['display_name'] ?? 'Supervisor';
        $evaluations[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Supervisor Dashboard - i-Tracker</title>
<style>
body{margin:0;background:#f8fafc;color:#0f172a;font-family:"Book Antiqua",Georgia,serif}.page{max-width:1280px;margin:auto;padding:36px 32px 48px}.topbar{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:28px}.topbar h1{margin:0;font-size:2.3rem;letter-spacing:-0.02em;}.topbar p{margin:6px 0;color:#64748b;line-height:1.6}.profile-header{display:flex;align-items:center;gap:12px}.profile-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;background:#e2e8f0;border:2px solid #dbeafe;display:flex;align-items:center;justify-content:center;color:#1d4ed8;font-weight:700}.actions{display:flex;gap:10px;flex-wrap:wrap}.btn{padding:10px 14px;border-radius:10px;text-decoration:none;border:1px solid #cbd5e1;background:#fff;color:#334155;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(15,23,42,0.05)}.btn:hover{background:#eff6ff;color:#1d4ed8}.layout{display:grid;grid-template-columns:1fr;gap:24px;align-items:start}.card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:26px;box-shadow:0 12px 30px rgba(15, 23, 42, 0.06)}.card h2{margin-top:0;margin-bottom:8px;font-size:1.25rem}.field{margin:16px 0}.field label{display:block;font-weight:700;margin-bottom:6px;font-size:0.88rem;color:#334155}.field input,.field select,.field textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;background:#ffffff;color:#0f172a;transition:border-color 0.2s ease}.field input:focus,.field select:focus,.field textarea:focus{outline:3px solid rgba(37,99,235,0.12);border-color:#2563eb}.ratings{display:grid;grid-template-columns:1fr 1fr;gap:12px}.message{padding:11px;border-radius:10px;background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.error{padding:11px;border-radius:10px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca}table{width:100%;border-collapse:separate;border-spacing:0 6px;min-width:680px}th,td{text-align:left;padding:14px 12px;vertical-align:top}th{color:#64748b;font-size:.72rem;text-transform:uppercase;letter-spacing:0.06em}tbody tr{background:#f8fafc}tbody tr td:first-child{border-radius:10px 0 0 10px}tbody tr td:last-child{border-radius:0 10px 10px 0}tbody tr:hover{background:#eff6ff}small{color:#64748b}.score{font-weight:700;color:#2563eb}.profile-modal,.evaluation-view-modal{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:center;justify-content:center;padding:24px;z-index:1000}.profile-modal.visible,.evaluation-view-modal.visible{display:flex}.profile-modal-content,.evaluation-view-content{background:#fff;border-radius:18px;padding:24px;max-width:650px;width:100%;box-shadow:0 20px 40px rgba(15,23,42,0.18);max-height:90vh;overflow-y:auto}.evaluation-view-content{max-width:1000px}.profile-modal-header,.evaluation-view-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid #e2e8f0}.modal-close-btn{border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;padding:8px 12px;font-weight:700;cursor:pointer}.btn.primary{width:100%;background:#2563eb;color:#fff;border-color:#2563eb;border-radius:10px;box-shadow:0 8px 16px rgba(37,99,235,0.2)}.btn.primary:hover{background:#1d4ed8}.view-evaluation-btn{padding:9px 14px;border:1px solid #93c5fd;border-radius:10px;background:#eff6ff;color:#1d4ed8;font-weight:700;cursor:pointer}.view-evaluation-btn:hover{background:#dbeafe}.rating-scale-table,.transmutation-table{width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #111827}.rating-scale-table th,.rating-scale-table td,.transmutation-table th,.transmutation-table td{border:1px solid #111827;padding:12px;vertical-align:top}.rating-scale-table th{background:#f8fafc;width:80px;text-align:center}.transmutation-output{display:grid;grid-template-columns:1fr auto;border:1px solid #111827;background:#fff;margin:12px 0}.transmutation-output .cell{padding:10px 12px;border-bottom:1px solid #111827;border-right:1px solid #111827}.transmutation-output .cell:nth-child(2n){border-right:0}.transmutation-output .label{font-weight:700}.transmutation-output .value{font-weight:700;min-width:100px;text-align:center}.rubric-section{margin:22px 0;padding:16px;border:1px solid #dbe3ee;border-radius:12px}.rubric-section legend{padding:0 8px;color:#1d4ed8;font-weight:700}.rubric-row{display:grid;grid-template-columns:1fr 100px;gap:14px;align-items:center;padding:10px 0;border-bottom:1px solid #eef2f7}.rubric-row:last-child{border-bottom:0}.signature-box{border:1px solid #cbd5e1;border-radius:10px;background:#fff;overflow:hidden;margin-top:6px}.signature-box canvas{display:block;width:100%;height:120px;background:#fff}.evaluation-signature-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:16px 0}.signature-preview{border:1px solid #cbd5e1;border-radius:12px;padding:12px;background:#fff}.signature-preview img{width:100%;max-height:120px;object-fit:contain}
</style>
</head>
<body><main class="page">
<div class="topbar"><div class="profile-header"><div class="profile-avatar"><?php echo strtoupper(substr(htmlspecialchars($user['display_name'] ?? 'S'), 0, 1)); ?></div><div><h1>Supervisor Dashboard</h1><p>Welcome, <?php echo htmlspecialchars($user['display_name'] ?? 'Supervisor'); ?>. Grade student immersion work performance.</p></div></div></div>

<?php if ($message): ?><div class="message"><?php echo htmlspecialchars($message); ?></div><br><?php endif; ?>
<?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><br><?php endif; ?>

<section class="card">
<h2>Work Immersion Performance Appraisal</h2>
<form method="post">
<div class="field">
    <label for="rubric_student_id">Select Immersion Student</label>
    <select id="rubric_student_id" name="student_id" required>
        <option value="">-- Select Student --</option>
        <?php foreach($students as $student): ?>
            <option value="<?php echo htmlspecialchars($student['student_id']); ?>"><?php echo htmlspecialchars($student['student_name']); ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="ratings">
    <div class="field"><label>Partner Institution</label><input id="partner_institution" name="partner_institution" readonly></div>
    <div class="field"><label>Strand</label><input id="strand" name="strand" readonly></div>
    <div class="field"><label>Address</label><input id="appraisal_address" name="appraisal_address" readonly></div>
    <div class="field"><label>Contact No.</label><input id="contact_number" name="contact_number" readonly></div>
    <div class="field"><label>Immersion Supervisor</label><input id="immersion_supervisor" name="immersion_supervisor" readonly></div>
    <div class="field"><label>Position</label><input id="supervisor_position" name="supervisor_position" value="Work Immersion Supervisor" readonly></div>
    <div class="field"><label>Training Period Start</label><input type="date" name="training_start_date" required value="<?php echo date('Y-m-d'); ?>"></div>
    <div class="field"><label>Training Period End</label><input type="date" name="training_end_date" required value="<?php echo date('Y-m-d'); ?>"></div>
    <div class="field"><label>Total Hours Rendered</label><input id="total_hours_rendered" name="total_hours_rendered" readonly></div>
</div>

<?php foreach ($rubric_sections as $section_key => $section): ?>
<fieldset class="rubric-section">
    <legend><?php echo htmlspecialchars($section['title']); ?></legend>
    <?php foreach ($section['items'] as $item_index => $item): ?>
    <div class="rubric-row">
        <span><?php echo ($item_index + 1) . '. ' . htmlspecialchars($item); ?></span>
        <select name="rubric[<?php echo htmlspecialchars($section_key); ?>][<?php echo $item_index; ?>]" class="rubric-input" required>
            <option value="">Score</option>
            <option value="NA">NA</option>
            <?php for ($score = 5; $score >= 1; $score--): ?>
                <option value="<?php echo $score; ?>"><?php echo $score; ?></option>
            <?php endfor; ?></select>
    </div>
    <?php endforeach; ?>
</fieldset>
<?php endforeach; ?>

<div class="transmutation-output">
    <div class="cell label">Computed Weighted Average</div>
    <div class="cell value" id="computed_weighted_average_display">0.00</div>
    <div class="cell label">Equivalent Grade</div>
    <div class="cell value" id="equivalent_grade_display">0</div>
</div>

<input type="hidden" id="overall_performance_rating" name="overall_performance_rating">
<input type="hidden" id="punctuality" name="punctuality">
<input type="hidden" id="work_quality" name="work_quality">
<input type="hidden" id="attitude" name="attitude">
<input type="hidden" id="teamwork" name="teamwork">

<div class="ratings">
    <div class="field">
        <label>Supervisor Signature Pad</label>
        <div class="signature-box"><canvas id="supervisor_signature_canvas"></canvas></div>
        <button type="button" class="btn" id="clear_sig" style="margin-top:5px; padding:4px 8px;">Clear</button>
        <input type="hidden" id="supervisor_signature" name="supervisor_signature">
    </div>
    <div class="field">
        <label>Remarks / Comments</label>
        <textarea id="comments" name="comments" rows="4" placeholder="Enter performance summary remarks..."></textarea>
    </div>
</div>

<button class="btn primary" type="submit" style="margin-top:20px; height:50px;">Submit Appraisal Card</button>
</form>
</section>

<section class="card" style="margin-top:24px;">
<h2>Submitted Logs Record History</h2>
<table>
    <thead>
        <tr>
            <th>Student</th>
            <th>Date</th>
            <th>Rating</th>
            <th>Average</th>
            <th>Comments</th>
        </tr>
    </thead>
    <tbody>
        <?php if(empty($evaluations)): ?>
            <tr><td colspan="5" style="text-align:center;">No grading entries records uploaded yet.</td></tr>
        <?php else: ?>
            <?php foreach($evaluations as $eval): ?>
                <?php 
                $student_display = $student_profile_map[$eval['student_id']]['student_name'] ?? $eval['student_id']; 
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($student_display); ?></strong></td>
                    <td><?php echo htmlspecialchars($eval['evaluation_date']); ?></td>
                    <td class="score"><?php echo htmlspecialchars($eval['equivalent_grade']); ?>%</td>
                    <td><?php echo htmlspecialchars($eval['weighted_average']); ?></td>
                    <td><?php echo htmlspecialchars($eval['comments']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</section>
</main>

<script>
var studentProfileMap = <?php echo json_encode($student_profile_map); ?>;
document.getElementById('rubric_student_id').addEventListener('change', function() {
    var details = studentProfileMap[this.value] || {};
    document.getElementById('partner_institution').value = details.partner_institution || '';
    document.getElementById('strand').value = details.strand || '';
    document.getElementById('appraisal_address').value = details.address || '';
    document.getElementById('contact_number').value = details.phone || '';
    document.getElementById('immersion_supervisor').value = details.supervisor_name || '';
    document.getElementById('total_hours_rendered').value = details.total_hours_rendered ? details.total_hours_rendered + ' hours' : '0 hours';
});

var rubricInputs = document.querySelectorAll('.rubric-input');
rubricInputs.forEach(function(input) {
    input.addEventListener('change', function() {
        var total = 0, count = 0;
        rubricInputs.forEach(function(sel) {
            if(sel.value && sel.value !== 'NA') {
                total += parseFloat(sel.value);
                count++;
            }
        });
        var avg = count > 0 ? (total / count) : 0;
        document.getElementById('computed_weighted_average_display').textContent = avg.toFixed(2);
        document.getElementById('overall_performance_rating').value = avg.toFixed(2);
        
        var grade = 80;
        if(avg >= 4.50) grade = 100;
        else if(avg >= 3.40) grade = 95;
        else if(avg >= 2.60) grade = 90;
        else if(avg >= 1.80) grade = 85;
        document.getElementById('equivalent_grade_display').textContent = grade;
        
        document.getElementById('punctuality').value = avg.toFixed(2);
        document.getElementById('work_quality').value = avg.toFixed(2);
        document.getElementById('attitude').value = avg.toFixed(2);
        document.getElementById('teamwork').value = avg.toFixed(2);
    });
});

var canvas = document.getElementById('supervisor_signature_canvas');
if(canvas) {
    var ctx = canvas.getContext('2d');
    var drawing = false;
    canvas.width = 400;
    canvas.height = 120;
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#000';

    canvas.addEventListener('mousedown', function(e) { drawing = true; ctx.beginPath(); ctx.moveTo(e.offsetX, e.offsetY); });
    canvas.addEventListener('mousemove', function(e) { if(drawing) { ctx.lineTo(e.offsetX, e.offsetY); ctx.stroke(); } });
    canvas.addEventListener('mouseup', function() { drawing = false; document.getElementById('supervisor_signature').value = canvas.toDataURL(); });
    
    canvas.addEventListener('touchstart', function(e) {
        drawing = true;
        var rect = canvas.getBoundingClientRect();
        ctx.beginPath();
        ctx.moveTo(e.touches[0].clientX - rect.left, e.touches[0].clientY - rect.top);
        e.preventDefault();
    }, {passive: false});
    
    canvas.addEventListener('touchmove', function(e) {
        if(drawing) {
            var rect = canvas.getBoundingClientRect();
            ctx.lineTo(e.touches[0].clientX - rect.left, e.touches[0].clientY - rect.top);
            ctx.stroke();
        }
        e.preventDefault();
    }, {passive: false});
    
    canvas.addEventListener('touchend', function() { drawing = false; document.getElementById('supervisor_signature').value = canvas.toDataURL(); });

    document.getElementById('clear_sig').addEventListener('click', function() { ctx.clearRect(0,0,canvas.width,canvas.height); ctx.beginPath(); document.getElementById('supervisor_signature').value = ''; });
}
</script>
</body>
</html>