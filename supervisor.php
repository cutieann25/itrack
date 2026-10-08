<?php
require_once 'auth.php';
$user = require_login(['supervisor']);
$conn = db_connection();
$message = '';
$error = '';
$profile_message = '';
$profile_error = '';

$current_profile_stmt = $conn->prepare('SELECT display_name, profile_image FROM users WHERE id = ? LIMIT 1');
$current_profile_stmt->bind_param('i', $user['id']);
$current_profile_stmt->execute();
$current_profile = $current_profile_stmt->get_result()->fetch_assoc() ?: ['display_name' => $user['display_name'], 'profile_image' => ''];
$user['display_name'] = $current_profile['display_name'];
$_SESSION['user']['display_name'] = $current_profile['display_name'];
$current_profile_image = $current_profile['profile_image'] ?? '';

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
                $current_profile_image = isset($bind_values[2]) && $bind_values[2] !== '' ? $bind_values[2] : $current_profile_image;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $attitude = (float)($_POST['attitude'] ?? 0);
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
    $equivalent_grade = $weighted_average >= 4.20 ? 100 : ($weighted_average >= 3.40 ? 95 : ($weighted_average >= 2.60 ? 90 : ($weighted_average >= 1.80 ? 85 : 80)));

    $assignment_check = $conn->prepare('SELECT assignment_id FROM student_supervisor_assignments WHERE student_id = ? AND supervisor_id = ?');
    $assignment_check->bind_param('si', $student_id, $user['id']);
    $assignment_check->execute();
    $is_assigned = $assignment_check->get_result()->num_rows > 0;

    $grades = [];
    foreach ([$punctuality, $work_quality, $attitude, $teamwork, $overall_performance_rating] as $grade_value) {
        if ($grade_value === '' || $grade_value === null || (string)$grade_value === 'NA') {
            continue;
        }
        $grades[] = (float)$grade_value;
    }
    foreach ($submitted_rubric as $section_key => $section_scores) {
        foreach ($section_scores as $item_index => $raw_score) {
            $normalized_score = is_string($raw_score) ? trim($raw_score) : $raw_score;
            if ($normalized_score === '' || $normalized_score === null || strtoupper((string)$normalized_score) === 'NA') {
                continue;
            }
            $grades[] = (float)$normalized_score;
        }
    }
    $invalid_grade = false;
    foreach ($grades as $grade) {
        $numeric_grade = (float)$grade;
        if (!is_numeric($grade) || !is_finite($numeric_grade) || $numeric_grade < 1 || $numeric_grade > 5) {
            $invalid_grade = true;
            break;
        }
    }

    if (!$is_assigned) {
        $error = 'You can only evaluate students assigned to your account by the coordinator.';
    } elseif ($student_id === '' || $invalid_grade) {
        $error = 'Select a student and enter every grade from 1 (lowest) to 5 (highest).';
    } else {
        $rubric_json = json_encode($rubric_scores, JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare('INSERT INTO performance_evaluations (student_id, evaluator_id, evaluation_date, partner_institution, appraisal_address, contact_number, immersion_supervisor, supervisor_position, training_start_date, training_end_date, total_hours_rendered, strand, punctuality, work_quality, attitude, teamwork, rubric_scores, overall_performance_rating, weighted_average, equivalent_grade, comments, student_signature, supervisor_signature) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sissssssssdsddddsdddsss', $student_id, $user['id'], $evaluation_date, $partner_institution, $appraisal_address, $contact_number, $immersion_supervisor, $supervisor_position, $training_start_date, $training_end_date, $total_hours_rendered, $strand, $punctuality, $work_quality, $attitude, $teamwork, $rubric_json, $overall_performance_rating, $weighted_average, $equivalent_grade, $comments, $student_signature, $supervisor_signature);
        if ($stmt->execute()) {
            $message = 'Performance evaluation submitted.';
        } else {
            $error = 'The evaluation could not be saved.';
        }
    }
}

$students = [];
$student_profile_map = [];
$student_details_lookup = [];
$student_details_stmt = $conn->prepare('SELECT student_name, strand, address, phone, email, parents, gender, dob FROM students');
$student_details_stmt->execute();
$student_details_result = $student_details_stmt->get_result();
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

$student_stmt = $conn->prepare('SELECT x.student_id, x.student_id AS student_name, a.agency_name, u.display_name AS supervisor_name FROM student_supervisor_assignments x LEFT JOIN users u ON u.id = x.supervisor_id LEFT JOIN agencies a ON a.agency_id = x.agency_id WHERE x.supervisor_id = ? ORDER BY x.student_id');
$student_stmt->bind_param('i', $user['id']);
$student_stmt->execute();
$result = $student_stmt->get_result();
$student_hours_map = [];
while ($row = $result->fetch_assoc()) {
    $student_id = (string)($row['student_id'] ?? '');
    $student_name = trim((string)($row['student_name'] ?? $row['student_id'] ?? ''));
    $student_record = $student_details_lookup[strtolower($student_name)] ?? [];
    $strand = $student_record['strand'] ?? '';
    $address = $student_record['address'] ?? '';
    $phone = $student_record['phone'] ?? '';
    $email = $student_record['email'] ?? '';
    $parents = $student_record['parents'] ?? '';
    $gender = $student_record['gender'] ?? '';
    $dob = $student_record['dob'] ?? '';

    $hours_stmt = $conn->prepare('SELECT log_time, status FROM attendance_logs WHERE student_id = ? ORDER BY log_time ASC');
    $hours_stmt->bind_param('s', $student_id);
    $hours_stmt->execute();
    $hours_result = $hours_stmt->get_result();
    $total_minutes = 0;
    $pending_in = null;
    while ($log_row = $hours_result->fetch_assoc()) {
        $status = strtolower(trim((string)($log_row['status'] ?? '')));
        $log_time = $log_row['log_time'];
        $timestamp = strtotime($log_time);
        if ($timestamp === false) {
            continue;
        }

        $is_in = strpos($status, 'in') !== false;
        $is_out = strpos($status, 'out') !== false;

        if ($is_in) {
            $pending_in = $timestamp;
            continue;
        }

        if ($is_out && $pending_in !== null) {
            $elapsed_minutes = (int)max(0, ($timestamp - $pending_in) / 60);
            if ($elapsed_minutes > 0) {
                $total_minutes += $elapsed_minutes;
            }
            $pending_in = null;
        }
    }
    $student_hours_map[$student_id] = round($total_minutes / 60, 2);

    $students[] = [
        'student_id' => $student_id,
        'student_name' => $student_name,
        'strand' => $strand,
        'address' => $address,
        'phone' => $phone,
        'email' => $email,
        'parents' => $parents,
        'gender' => $gender,
        'dob' => $dob,
        'agency_name' => $row['agency_name'] ?? '',
        'supervisor_name' => $row['supervisor_name'] ?? '',
        'total_hours_rendered' => $student_hours_map[$student_id],
    ];

    $student_profile_map[$student_id] = [
        'student_id' => $student_id,
        'student_name' => $student_name,
        'strand' => $strand,
        'address' => $address,
        'phone' => $phone,
        'email' => $email,
        'parents' => $parents,
        'gender' => $gender,
        'dob' => $dob,
        'partner_institution' => $row['agency_name'] ?? '',
        'supervisor_name' => $row['supervisor_name'] ?? '',
        'total_hours_rendered' => $student_hours_map[$student_id],
    ];
}

$evaluations = [];
$evaluation_stmt = $conn->prepare('SELECT e.*, u.display_name AS evaluator_name FROM performance_evaluations e JOIN users u ON u.id = e.evaluator_id JOIN student_supervisor_assignments a ON a.student_id = e.student_id AND a.supervisor_id = e.evaluator_id WHERE e.evaluator_id = ? ORDER BY e.evaluation_date DESC, e.created_at DESC');
$evaluation_stmt->bind_param('i', $user['id']);
$evaluation_stmt->execute();
$result = $evaluation_stmt->get_result();
while ($row = $result->fetch_assoc()) $evaluations[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Supervisor Dashboard - i-Tracker</title>
<style>
body{margin:0;background:#f1f5f9;color:#172554;font-family:"Book Antiqua",Georgia,serif}.page{max-width:1180px;margin:auto;padding:28px}.topbar{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:24px}.topbar h1{margin:0;font-size:2.3rem}.topbar p{margin:6px 0;color:#64748b}.profile-header{display:flex;align-items:center;gap:12px}.profile-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;background:#e2e8f0;border:2px solid #dbeafe;display:flex;align-items:center;justify-content:center;color:#1d4ed8;font-weight:700}.actions{display:flex;gap:10px;flex-wrap:wrap}.btn{padding:11px 16px;border-radius:9px;text-decoration:none;border:1px solid #cbd5e1;background:#fff;color:#1e3a8a;font-weight:700;cursor:pointer}.btn.primary{background:#1d4ed8;color:#fff;border-color:#1d4ed8}.layout{display:grid;grid-template-columns:minmax(300px,380px) 1fr;gap:22px}.card{background:#fff;border:1px solid #dbeafe;border-radius:18px;padding:24px;box-shadow:0 12px 30px #0f172a0d}.card h2{margin-top:0}.field{margin:15px 0}.field label{display:block;font-weight:700;margin-bottom:6px}.field input,.field select,.field textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}.ratings{display:grid;grid-template-columns:1fr 1fr;gap:10px}.message{padding:11px;border-radius:8px;background:#dcfce7;color:#166534}.error{padding:11px;border-radius:8px;background:#fee2e2;color:#991b1b}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top}th{color:#475569;font-size:.82rem;text-transform:uppercase}small{color:#64748b}.score{font-weight:700;color:#1d4ed8}.profile-modal{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:center;justify-content:center;padding:20px;z-index:1000}.profile-modal.visible{display:flex}.profile-modal-content{background:#fff;border-radius:18px;padding:24px;max-width:560px;width:min(100%,560px);box-shadow:0 20px 50px rgba(15,23,42,.2)}.profile-modal-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.profile-modal-header h3{margin:0}.profile-form-grid{display:grid;grid-template-columns:1fr;gap:10px}.profile-upload-preview{width:88px;height:88px;border-radius:50%;object-fit:cover;border:2px solid #dbeafe;background:#f8fafc;display:block;margin-bottom:10px}.profile-upload-placeholder{width:88px;height:88px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#e2e8f0;color:#1d4ed8;font-size:2rem;font-weight:700;margin-bottom:10px}.profile-input-group{display:flex;gap:12px;align-items:center}@media(max-width:800px){.layout{grid-template-columns:1fr}.topbar{align-items:flex-start;flex-direction:column}.ratings{grid-template-columns:1fr}.card{overflow-x:auto}table{min-width:680px}.profile-input-group{flex-direction:column;align-items:flex-start}} 

body {
    background: #f8fafc;
    color: #0f172a;
}
.page {
    max-width: 1280px;
    padding: 36px 32px 48px;
}
.topbar {
    align-items: flex-start;
    margin-bottom: 28px;
}
.topbar h1 {
    color: #0f172a;
    font-size: clamp(1.8rem, 3vw, 2.5rem);
    letter-spacing: -0.02em;
}
.topbar p {
    line-height: 1.6;
}
.actions .btn {
    padding: 10px 14px;
    border-radius: 10px;
    background: #ffffff;
    color: #334155;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
}
.actions .btn:hover {
    background: #eff6ff;
    color: #1d4ed8;
}
.layout {
    grid-template-columns: minmax(320px, 390px) minmax(0, 1fr);
    gap: 24px;
    align-items: start;
}
.card {
    padding: 26px;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
}
.card h2 {
    margin-bottom: 8px;
    color: #0f172a;
    font-size: 1.25rem;
}
.field {
    margin: 16px 0;
}
.field label {
    color: #334155;
    font-size: 0.88rem;
}
.field input,
.field select,
.field textarea {
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background: #ffffff;
    color: #0f172a;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.field input:focus,
.field select:focus,
.field textarea:focus {
    outline: 3px solid rgba(37, 99, 235, 0.12);
    border-color: #2563eb;
}
.ratings {
    gap: 12px;
}
.btn.primary {
    width: 100%;
    border-radius: 10px;
    background: #2563eb;
    box-shadow: 0 8px 16px rgba(37, 99, 235, 0.2);
    cursor: pointer;
}
.btn.primary:hover {
    background: #1d4ed8;
}
.view-evaluation-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 9px 14px;
    border: 1px solid #93c5fd;
    border-radius: 10px;
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s ease, border-color 0.2s ease;
}
.view-evaluation-btn:hover {
    background: #dbeafe;
    border-color: #60a5fa;
}
.message,
.error {
    border: 1px solid transparent;
    border-radius: 10px;
}
.message {
    border-color: #bbf7d0;
}
.error {
    border-color: #fecaca;
}
table {
    border-collapse: separate;
    border-spacing: 0 6px;
    min-width: 680px;
}
th,
td {
    padding: 14px 12px;
    border-bottom: 0;
}
th {
    color: #64748b;
    font-size: 0.72rem;
    letter-spacing: 0.06em;
}
tbody tr {
    background: #f8fafc;
}
tbody tr td:first-child {
    border-radius: 10px 0 0 10px;
}
tbody tr td:last-child {
    border-radius: 0 10px 10px 0;
}
tbody tr:hover {
    background: #eff6ff;
}
#performance-evaluation-table td:last-child {
    white-space: nowrap;
    text-align: center;
}
.score {
    color: #2563eb;
}
.evaluation-signature-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    margin: 20px 0;
}
.signature-preview {
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    background: #fff;
    padding: 12px;
}
.signature-preview label {
    display: block;
    margin-bottom: 10px;
    color: #334155;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.signature-preview img {
    display: block;
    width: 100%;
    max-height: 150px;
    object-fit: contain;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}
.signature-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    color: #64748b;
    background: #f8fafc;
    font-size: 0.9rem;
    text-align: center;
}
.evaluation-view-modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(15, 23, 42, 0.5);
    z-index: 1000;
}
.evaluation-view-modal.visible {
    display: flex;
}
.evaluation-view-content {
    width: min(1100px, calc(100vw - 48px));
    max-height: 88vh;
    overflow: auto;
    background: #ffffff;
    border: 1px solid #dbeafe;
    border-radius: 18px;
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
    padding: 24px;
}
.evaluation-view-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e2e8f0;
}
.evaluation-view-header h3 {
    margin: 0;
    color: #0f172a;
    font-size: 1.4rem;
}
.modal-close-btn {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    color: #334155;
    padding: 8px 12px;
    font-weight: 700;
    cursor: pointer;
}
.evaluation-view-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 18px;
    margin-bottom: 20px;
}
.evaluation-view-grid .field {
    margin: 0;
}
.evaluation-view-grid .field input,
.evaluation-view-grid .field textarea,
.evaluation-view-section,
.evaluation-view-rubric-row {
    border-radius: 10px;
}
.evaluation-view-section {
    margin-top: 16px;
    border: 1px solid #dbe3ee;
    background: #f8fafc;
    padding: 16px;
}
.evaluation-view-section h4 {
    margin: 0 0 12px;
    color: #1d4ed8;
    font-size: 1.05rem;
}
.evaluation-view-rubric-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 74px;
    gap: 12px;
    align-items: center;
    padding: 10px 12px;
    background: #fff;
    border: 1px solid #e2e8f0;
    margin-bottom: 8px;
}
.evaluation-view-rubric-row:last-child {
    margin-bottom: 0;
}
.score-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 54px;
    padding: 7px 10px;
    border-radius: 999px;
    background: #dbeafe;
    color: #1d4ed8;
    font-weight: 700;
}
@media (max-width: 800px) {
    .page {
        padding: 24px 16px 36px;
    }
    .topbar {
        gap: 14px;
    }
    .evaluation-view-grid {
        grid-template-columns: 1fr;
    }
    .layout {
        gap: 16px;
    }
    .card {
        padding: 20px;
    }
}

.rubric-card {
    grid-column: 1 / -1;
}
.rubric-header,
.rubric-summary {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}
.rating-scale-table {
    width: 100%;
    border-collapse: collapse;
    margin: 18px 0 24px;
    background: #ffffff;
    border: 1px solid #111827;
    table-layout: fixed;
    font-family: 'Book Antiqua', Georgia, serif;
}
.rating-scale-table th,
.rating-scale-table td {
    border: 1px solid #111827;
    padding: 12px 14px;
    vertical-align: top;
    text-align: left;
    line-height: 1.4;
}
.rating-scale-table th {
    width: 90px;
    text-align: center;
    font-size: 1.25rem;
    font-weight: 700;
    color: #111827;
    background: #ffffff;
}
.rating-scale-table td:nth-child(2) {
    width: 220px;
    font-weight: 700;
    color: #111827;
    font-size: 1.05rem;
}
.rating-scale-table td:nth-child(3) {
    color: #111827;
    font-size: 1.02rem;
}
.transmutation-table {
    width: 100%;
    max-width: 420px;
    border-collapse: collapse;
    margin-top: 16px;
    border: 1px solid #111827;
    background: #fff;
    font-family: 'Book Antiqua', Georgia, serif;
}
.transmutation-table th,
.transmutation-table td {
    border: 1px solid #111827;
    padding: 10px 12px;
    text-align: center;
    color: #111827;
}
.transmutation-table th {
    font-size: 1.05rem;
    background: #f8fafc;
}
.transmutation-table td:first-child {
    text-align: left;
}
.transmutation-output {
    display: grid;
    grid-template-columns: 1fr auto;
    width: 100%;
    max-width: 420px;
    margin-top: 12px;
    border: 1px solid #111827;
    background: #fff;
    font-family: 'Book Antiqua', Georgia, serif;
}
.transmutation-output .cell {
    padding: 10px 12px;
    border-right: 1px solid #111827;
    border-bottom: 1px solid #111827;
    color: #111827;
    min-height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
}
.transmutation-output .cell:nth-child(2n) {
    border-right: 0;
}
.transmutation-output .cell:nth-last-child(-n+2) {
    border-bottom: 0;
}
.transmutation-output .label {
    font-weight: 700;
    justify-content: flex-start;
}
.transmutation-output .value {
    font-weight: 700;
    min-width: 120px;
}
.rubric-section {
    margin: 22px 0;
    padding: 16px;
    border: 1px solid #dbe3ee;
    border-radius: 12px;
}
.rubric-section legend {
    padding: 0 8px;
    color: #1d4ed8;
    font-weight: 700;
    font-size: 1.05rem;
}
.rubric-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 90px;
    gap: 14px;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #eef2f7;
}
.rubric-row:last-child { border-bottom: 0; }
.rubric-row select { width: 100%; }
.rubric-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.signature-box {
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background: #fff;
    overflow: hidden;
}
.signature-box canvas {
    display: block;
    width: 100%;
    height: 140px;
    background: #ffffff;
    border: 0;
    cursor: crosshair;
}
.signature-box.disabled {
    background: #f8fafc;
    opacity: 0.75;
}
.signature-box.disabled canvas {
    cursor: not-allowed;
    pointer-events: none;
}
.signature-note {
    margin-top: 8px;
    font-size: 0.76rem;
    color: #64748b;
}
.signature-name-wrap {
    margin-top: 10px;
}
.signature-name-wrap label {
    display: block;
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #475569;
    margin-bottom: 6px;
}
.signature-name-wrap input {
    width: 100%;
    box-sizing: border-box;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    color: #0f172a;
    font: inherit;
}
.signature-actions {
    display: flex;
    justify-content: flex-end;
    padding: 8px 10px 10px;
}
.signature-actions .btn.small {
    padding: 7px 12px;
    border-radius: 8px;
    font-size: 0.8rem;
    background: #f8fafc;
    color: #334155;
}
@media (max-width: 800px) {
    .rubric-header,
    .rubric-summary { grid-template-columns: 1fr; }
    .rubric-row { grid-template-columns: 1fr 86px; }
}
</style>
</head>
<body><main class="page">
<div class="topbar"><div class="profile-header"><div><?php if (!empty($current_profile_image)): ?><img class="profile-avatar" src="<?php echo htmlspecialchars($current_profile_image); ?>" alt="Profile photo"><?php else: ?><div class="profile-avatar"><?php echo strtoupper(substr(htmlspecialchars($user['display_name'] ?: $user['username']), 0, 1)); ?></div><?php endif; ?></div><div><h1>Supervisor Dashboard</h1><p>Welcome, <?php echo htmlspecialchars($user['display_name']); ?>. Review work immersion performance.</p></div></div><div class="actions"><button type="button" class="btn" id="open-profile-modal">Edit profile</button><a class="btn" href="logout.php" onclick="return confirm('Are you sure you want to log out?');">Sign out</a></div></div>
<?php if ($message): ?><div class="message"><?php echo htmlspecialchars($message); ?></div><br><?php endif; ?><?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><br><?php endif; ?><?php if ($profile_message): ?><div class="message"><?php echo htmlspecialchars($profile_message); ?></div><br><?php endif; ?><?php if ($profile_error): ?><div class="error"><?php echo htmlspecialchars($profile_error); ?></div><br><?php endif; ?>
<section class="card rubric-card"><h2>Work Immersion Performance Appraisal</h2><p><small>Rate every criterion from 1 (lowest) to 5 (highest), or NA when not applicable.</small></p><form method="post"><div style="margin-bottom: 18px; font-size: 1.2rem; font-style: italic; line-height: 1.4; font-family: 'Book Antiqua', Georgia, serif; color: #111827;">Directions. For each behavioral item listed within the competency bands, with 1 being the lowest and 5 being the highest, please select the evaluation most suited.</div><table class="rating-scale-table" aria-label="Performance rating scale">
    <tbody>
        <tr>
            <th>5</th>
            <td>Outstanding</td>
            <td>Performance exceeds the required standard.</td>
        </tr>
        <tr>
            <th>4</th>
            <td>Very Satisfactory</td>
            <td>Performance fully met job requirements. Was able to perform what was expected of a person in his/her position.</td>
        </tr>
        <tr>
            <th>3</th>
            <td>Satisfactory</td>
            <td>Performance has met the required standard. Can perform duties with minimal supervision.</td>
        </tr>
        <tr>
            <th>2</th>
            <td>Fair</td>
            <td>Performance partially meets the required standard. Less than satisfactory could be doing better.</td>
        </tr>
        <tr>
            <th>1</th>
            <td>Needs Improvement</td>
            <td>Performance does not meet the required standard. Major improvements needed.</td>
        </tr>
        <tr>
            <th>NA</th>
            <td>Not Applicable</td>
            <td>Performance indicator is not relevant to the job.</td>
        </tr>
    </tbody>
</table><div class="rubric-header"><div class="field"><label for="rubric_student_id">Student</label><select id="rubric_student_id" name="student_id" required><option value="">Select student</option><?php foreach($students as $student): ?><option value="<?php echo htmlspecialchars($student['student_id']); ?>"><?php echo htmlspecialchars($student['student_name'] ?: $student['student_id']); ?></option><?php endforeach; ?></select></div><div class="field"><label for="rubric_evaluation_date">Evaluation date</label><input id="rubric_evaluation_date" type="date" name="evaluation_date" value="<?php echo date('Y-m-d'); ?>" required></div><div class="field"><label for="partner_institution">Partner institution</label><input id="partner_institution" name="partner_institution" readonly></div><div class="field"><label for="strand">Strand</label><input id="strand" name="strand" readonly></div><div class="field"><label for="appraisal_address">Address</label><input id="appraisal_address" name="appraisal_address" readonly></div><div class="field"><label for="contact_number">Contact No.</label><input id="contact_number" name="contact_number" readonly></div><div class="field"><label for="immersion_supervisor">Work Immersion Supervisor</label><input id="immersion_supervisor" name="immersion_supervisor" readonly></div><div class="field"><label for="supervisor_position">Position</label><input id="supervisor_position" name="supervisor_position" value="Work Immersion Supervisor" readonly></div><div class="field"><label for="training_start_date">Training period start</label><input id="training_start_date" type="date" name="training_start_date"></div><div class="field"><label for="training_end_date">Training period end</label><input id="training_end_date" type="date" name="training_end_date"></div><div class="field"><label for="total_hours_rendered">Total hours rendered</label><input type="hidden" id="total_hours_rendered_hidden" name="total_hours_rendered"><input id="total_hours_rendered" type="text" readonly></div></div><?php foreach ($rubric_sections as $section_key => $section): ?><fieldset class="rubric-section"><legend><?php echo htmlspecialchars($section['title']); ?></legend><?php foreach ($section['items'] as $item_index => $item): ?><div class="rubric-row"><span><?php echo ($item_index + 1) . '. ' . htmlspecialchars($item); ?></span><select name="rubric[<?php echo htmlspecialchars($section_key); ?>][<?php echo $item_index; ?>]" required><option value="">Score</option><option value="NA">NA</option><?php for ($score = 5; $score >= 1; $score--): ?><option value="<?php echo $score; ?>"><?php echo $score; ?></option><?php endfor; ?></select></div><?php endforeach; ?></fieldset><?php endforeach; ?><div style="display:none;">
    <input id="overall_performance_rating" name="overall_performance_rating" type="number" min="1" max="5" step="0.1" required>
    <input id="equivalent_grade" name="equivalent_grade" type="text" readonly>
    <input type="hidden" id="punctuality" name="punctuality">
    <input type="hidden" id="work_quality" name="work_quality">
    <input type="hidden" id="attitude" name="attitude">
    <input type="hidden" id="teamwork" name="teamwork">
</div>
<div class="transmutation-output" aria-label="Transmutation output">
    <div class="cell label">Computed Weighted Average</div>
    <div class="cell value" id="computed_weighted_average_display">0.00</div>
    <div class="cell label">Equivalent Grade</div>
    <div class="cell value" id="equivalent_grade_display">0</div>
</div>
<table class="transmutation-table" aria-label="Transmutation table">
    <thead>
        <tr>
            <th>Computed Weighted Average</th>
            <th>Equivalent Grade</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>4.50 - 4.99</td><td>100</td></tr>
        <tr><td>3.40 - 4.49</td><td>95</td></tr>
        <tr><td>2.60 - 3.39</td><td>90</td></tr>
        <tr><td>1.80 - 2.59</td><td>85</td></tr>
        <tr><td>1.00 - 1.79</td><td>80</td></tr>
    </tbody>
</table>
<div class="rubric-summary">
    <div class="field">
        <label for="student_signature_canvas">Student signature</label>
        <div class="signature-box disabled">
            <canvas id="student_signature_canvas" width="420" height="140"></canvas>
        </div>
        <div class="signature-note">Student signature will be provided separately by the student.</div>
        <div class="signature-name-wrap">
            <label for="student_printed_name">Printed name</label>
            <input id="student_printed_name" name="student_printed_name" type="text" readonly>
        </div>
        <div class="signature-actions"><button type="button" class="btn small" data-clear="student_signature_canvas" disabled>Clear</button></div>
        <input type="hidden" id="student_signature" name="student_signature">
    </div>
    <div class="field">
        <label for="supervisor_signature_canvas">Supervisor signature</label>
        <div class="signature-box">
            <canvas id="supervisor_signature_canvas" width="420" height="140"></canvas>
        </div>
        <div class="signature-name-wrap">
            <label for="supervisor_printed_name">Printed name</label>
            <input id="supervisor_printed_name" name="supervisor_printed_name" type="text" readonly>
        </div>
        <div class="signature-actions"><button type="button" class="btn small" data-clear="supervisor_signature_canvas">Clear</button></div>
        <input type="hidden" id="supervisor_signature" name="supervisor_signature">
    </div>
</div>
<div class="field"><label for="comments">Remarks / comments</label><textarea id="comments" name="comments" rows="5" placeholder="Comments / suggestions"></textarea></div><button class="btn primary" type="submit">Submit complete appraisal</button></form></section>
<section class="card"><h2 id="evaluation-history-title">Performance Evaluations</h2><?php if (!$evaluations): ?><p><small>No performance evaluations have been submitted yet.</small></p><?php else: ?><p id="selected-student-history-note" style="display:none;"><small>Showing evaluations for the selected student.</small></p><table id="performance-evaluation-table"><thead><tr><th>Student</th><th>Date</th><th>Overall Grade</th><th>Average</th><th>Ratings</th><th>Comments</th><th>Action</th></tr></thead><tbody><?php foreach($evaluations as $evaluation): ?><?php $student_display = $studentProfileMap[$evaluation['student_id']]['student_name'] ?? $evaluation['student_id']; $average=round(($evaluation['punctuality']+$evaluation['work_quality']+$evaluation['attitude']+$evaluation['teamwork'])/4,1); ?><tr data-student-id="<?php echo htmlspecialchars((string)$evaluation['student_id']); ?>" data-student-name="<?php echo htmlspecialchars((string)$student_display); ?>" data-evaluation='<?php echo json_encode($evaluation, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'><td><strong><?php echo htmlspecialchars((string)$student_display); ?></strong><br><small><?php echo htmlspecialchars($evaluation['evaluator_name']); ?></small></td><td><?php echo htmlspecialchars($evaluation['evaluation_date']); ?></td><td class="score"><?php echo number_format((float)$evaluation['overall_performance_rating'], 1); ?>/5.0</td><td><?php echo number_format($average, 1); ?>/5.0</td><td>P <?php echo number_format((float)$evaluation['punctuality'], 1); ?>, Q <?php echo number_format((float)$evaluation['work_quality'], 1); ?><br>A <?php echo number_format((float)$evaluation['attitude'], 1); ?>, T <?php echo number_format((float)$evaluation['teamwork'], 1); ?></td><td><?php echo htmlspecialchars($evaluation['comments'] ?: 'No comments'); ?></td><td><button type="button" class="view-evaluation-btn" data-evaluation-json='<?php echo json_encode($evaluation, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'>View</button></td></tr><?php endforeach; ?></tbody></table><p id="no-selected-evaluation-message" style="display:none;"><small>No performance evaluations found for the selected student.</small></p><?php endif; ?></section>
<div id="evaluation-view-modal" class="evaluation-view-modal" aria-modal="true" role="dialog">
    <div class="evaluation-view-content">
        <div class="evaluation-view-header">
            <h3>Performance Appraisal Details</h3>
            <button type="button" class="modal-close-btn" id="close-view-modal">Close</button>
        </div>
        <div id="evaluation-view-body"></div>
    </div>
</div>
<div id="profile-modal" class="profile-modal" aria-modal="true" role="dialog">
    <div class="profile-modal-content">
        <div class="profile-modal-header">
            <h3>Profile Settings</h3>
            <button type="button" class="modal-close-btn" id="close-profile-modal">Close</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="profile_update" value="1">
            <div class="profile-form-grid">
                <div class="profile-input-group">
                    <?php if (!empty($current_profile_image)): ?>
                        <img class="profile-upload-preview" src="<?php echo htmlspecialchars($current_profile_image); ?>" alt="Current profile image">
                    <?php else: ?>
                        <div class="profile-upload-placeholder"><?php echo strtoupper(substr(htmlspecialchars($user['display_name'] ?: $user['username']), 0, 1)); ?></div>
                    <?php endif; ?>
                    <div style="flex:1;">
                        <label for="profile-display-name">Display name</label>
                        <input id="profile-display-name" type="text" name="display_name" value="<?php echo htmlspecialchars($user['display_name']); ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label for="profile-password">New password</label>
                    <input id="profile-password" type="password" name="password" placeholder="Leave blank to keep current password" autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="profile-confirm-password">Confirm new password</label>
                    <input id="profile-confirm-password" type="password" name="confirm_password" placeholder="Confirm new password" autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="profile-image">Profile image</label>
                    <input id="profile-image" type="file" name="profile_image" accept="image/png,image/jpeg,image/jpg,image/gif,image/webp">
                </div>
                <button type="submit" class="btn primary">Save profile</button>
            </div>
        </form>
    </div>
</div>
</div>
</main>
<script>
(function () {
    var studentProfileMap = <?php echo json_encode($student_profile_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var rubricSections = <?php echo json_encode($rubric_sections, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var studentSelect = document.getElementById('rubric_student_id');
    var partnerInstitutionInput = document.getElementById('partner_institution');
    var strandInput = document.getElementById('strand');
    var addressInput = document.getElementById('appraisal_address');
    var contactInput = document.getElementById('contact_number');
    var supervisorInput = document.getElementById('immersion_supervisor');
    var positionInput = document.getElementById('supervisor_position');
    var studentPrintedNameInput = document.getElementById('student_printed_name');
    var supervisorPrintedNameInput = document.getElementById('supervisor_printed_name');
    var rubricInputs = document.querySelectorAll('select[name^="rubric["]');
    var overallInput = document.getElementById('overall_performance_rating');
    var equivalentGradeInput = document.getElementById('equivalent_grade');
    var computedWeightedAverageDisplay = document.getElementById('computed_weighted_average_display');
    var equivalentGradeDisplay = document.getElementById('equivalent_grade_display');
    var punctualityInput = document.getElementById('punctuality');
    var workQualityInput = document.getElementById('work_quality');
    var attitudeInput = document.getElementById('attitude');
    var teamworkInput = document.getElementById('teamwork');

    function getTransmutationGrade(averageScore) {
        if (averageScore >= 4.50) return 100;
        if (averageScore >= 3.40) return 95;
        if (averageScore >= 2.60) return 90;
        if (averageScore >= 1.80) return 85;
        if (averageScore >= 1.00) return 80;
        return 0;
    }

    function getSectionValues(sectionKey) {
        var values = [];
        var selector = 'select[name^="rubric[' + sectionKey + ']["]';
        Array.prototype.forEach.call(document.querySelectorAll(selector), function (input) {
            var rawValue = input.value;
            var numeric = rawValue === 'NA' ? 0 : Number(rawValue);
            if (!Number.isNaN(numeric) && numeric >= 0 && numeric <= 5) {
                values.push(numeric);
            }
        });
        return values;
    }

    function calculateAverage(values) {
        if (!values.length) {
            return 0;
        }
        var total = values.reduce(function (sum, value) {
            return sum + value;
        }, 0);
        return Number((total / values.length).toFixed(2));
    }

    function populateStudentDetails(studentId) {
        var details = studentProfileMap[studentId] || {};
        if (partnerInstitutionInput) partnerInstitutionInput.value = details.partner_institution || '';
        if (strandInput) strandInput.value = details.strand || '';
        if (addressInput) addressInput.value = details.address || '';
        if (contactInput) contactInput.value = details.phone || '';
        if (supervisorInput) supervisorInput.value = details.supervisor_name || '';
        if (studentPrintedNameInput) studentPrintedNameInput.value = details.student_name || details.student_id || '';
        if (supervisorPrintedNameInput) supervisorPrintedNameInput.value = details.supervisor_name || '';
        if (positionInput) positionInput.value = 'Work Immersion Supervisor';

        var visibleHoursInput = document.getElementById('total_hours_rendered');
        var hiddenHoursInput = document.getElementById('total_hours_rendered_hidden');
        if (visibleHoursInput) {
            var totalHours = details.total_hours_rendered;
            if (totalHours === null || totalHours === undefined || totalHours === '') {
                visibleHoursInput.value = '';
                if (hiddenHoursInput) hiddenHoursInput.value = '';
                return;
            }

            var numericHours = parseFloat(totalHours);
            var totalMinutes = Number.isFinite(numericHours) ? Math.round(numericHours * 60) : 0;
            var displayValue = Number.isFinite(numericHours)
                ? (function () {
                    var hours = Math.floor(totalMinutes / 60);
                    var minutes = totalMinutes % 60;
                    var parts = [hours + ' hour' + (hours === 1 ? '' : 's')];
                    if (minutes > 0 || hours === 0) {
                        parts.push(minutes + ' minute' + (minutes === 1 ? '' : 's'));
                    }
                    return parts.join(' ');
                })()
                : '';

            visibleHoursInput.value = displayValue;
            if (hiddenHoursInput) {
                hiddenHoursInput.value = Number.isFinite(numericHours) ? numericHours.toFixed(2) : '';
            }
        }
    }

    function attachSignaturePad(canvasId, hiddenId, allowDrawing) {
        var canvas = document.getElementById(canvasId);
        var hiddenInput = document.getElementById(hiddenId);
        if (!canvas || !hiddenInput) return;

        if (!allowDrawing) {
            canvas.style.pointerEvents = 'none';
            canvas.style.opacity = '0.65';
            hiddenInput.value = '';
            var readOnlyClear = document.querySelector('[data-clear="' + canvasId + '"]');
            if (readOnlyClear) {
                readOnlyClear.disabled = true;
            }
            return;
        }

        var ctx = canvas.getContext('2d');
        var drawing = false;
        var lastX = 0;
        var lastY = 0;

        function setCanvasSize() {
            var ratio = window.devicePixelRatio || 1;
            var rect = canvas.getBoundingClientRect();
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.lineWidth = 2.2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#111827';
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, rect.width, rect.height);
        }

        function getPoint(event) {
            var rect = canvas.getBoundingClientRect();
            var clientX = event.clientX !== undefined ? event.clientX : event.touches[0].clientX;
            var clientY = event.clientY !== undefined ? event.clientY : event.touches[0].clientY;
            return {
                x: clientX - rect.left,
                y: clientY - rect.top
            };
        }

        function startDraw(event) {
            event.preventDefault();
            drawing = true;
            var point = getPoint(event);
            lastX = point.x;
            lastY = point.y;
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
        }

        function draw(event) {
            if (!drawing) return;
            event.preventDefault();
            var point = getPoint(event);
            ctx.lineTo(point.x, point.y);
            ctx.stroke();
            lastX = point.x;
            lastY = point.y;
        }

        function stopDraw(event) {
            if (!drawing) return;
            drawing = false;
            ctx.beginPath();
            hiddenInput.value = canvas.toDataURL('image/png');
        }

        canvas.addEventListener('pointerdown', startDraw);
        canvas.addEventListener('pointermove', draw);
        canvas.addEventListener('pointerup', stopDraw);
        canvas.addEventListener('pointerleave', stopDraw);
        canvas.addEventListener('pointercancel', stopDraw);
        canvas.addEventListener('touchstart', startDraw, { passive: false });
        canvas.addEventListener('touchmove', draw, { passive: false });
        canvas.addEventListener('touchend', stopDraw, { passive: false });

        var clearButton = document.querySelector('[data-clear="' + canvasId + '"]');
        if (clearButton) {
            clearButton.addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                hiddenInput.value = '';
            });
        }

        setCanvasSize();
        window.addEventListener('resize', setCanvasSize);
    }

    var evaluationHistoryTitle = document.getElementById('evaluation-history-title');
    var evaluationHistoryNote = document.getElementById('selected-student-history-note');
    var noEvaluationMessage = document.getElementById('no-selected-evaluation-message');
    var evaluationRows = Array.prototype.slice.call(document.querySelectorAll('#performance-evaluation-table tbody tr'));
    var evaluationModal = document.getElementById('evaluation-view-modal');
    var evaluationViewBody = document.getElementById('evaluation-view-body');
    var closeViewModalBtn = document.getElementById('close-view-modal');

    function getDisplayValue(value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        return value;
    }

    function formatRenderedHours(value) {
        if (value === null || value === undefined || value === '' || value === 'NA') {
            return '—';
        }

        var numberValue = Number(value);
        if (Number.isNaN(numberValue)) {
            return String(value);
        }

        var totalMinutes = Math.round(numberValue * 60);
        var hours = Math.floor(totalMinutes / 60);
        var minutes = totalMinutes % 60;
        var parts = [];

        parts.push(hours + ' hour' + (hours === 1 ? '' : 's'));
        if (minutes > 0 || hours === 0) {
            parts.push(minutes + ' minute' + (minutes === 1 ? '' : 's'));
        }

        return parts.join(' ');
    }

    function renderEvaluationModal(evaluation) {
        if (!evaluation || !evaluationViewBody) {
            return;
        }

        var rubricData = {};
        try {
            rubricData = typeof evaluation.rubric_scores === 'string' ? JSON.parse(evaluation.rubric_scores) : (evaluation.rubric_scores || {});
        } catch (error) {
            rubricData = {};
        }

        var studentInfo = studentProfileMap[evaluation.student_id] || {};
        var studentName = studentInfo.student_name || evaluation.student_id || 'Student';
        var assignedSupervisorName = studentInfo.supervisor_name || evaluation.immersion_supervisor || 'Supervisor';
        var savedSupervisorPosition = evaluation.supervisor_position && String(evaluation.supervisor_position).toLowerCase() !== 'student'
            ? evaluation.supervisor_position
            : 'Work Immersion Supervisor';
        var overallRating = Number(evaluation.overall_performance_rating || 0);
        var weightedAverage = Number(evaluation.weighted_average || 0);
        var equivalentGrade = Number(evaluation.equivalent_grade || 0);
        var studentSignature = evaluation.student_signature || '';
        var supervisorSignature = evaluation.supervisor_signature || '';

        var rubricMarkup = Object.keys(rubricSections).map(function (sectionKey) {
            var section = rubricSections[sectionKey] || { title: sectionKey, items: [] };
            var items = section.items || [];
            var rows = items.map(function (item, itemIndex) {
                var score = rubricData[sectionKey] && rubricData[sectionKey][itemIndex];
                score = score === 'NA' ? 'NA' : (score === undefined || score === null || score === '' ? '—' : score);
                return '<div class="evaluation-view-rubric-row"><span>' + (itemIndex + 1) + '. ' + item + '</span><div class="score-pill">' + score + '</div></div>';
            }).join('');
            return '<div class="evaluation-view-section"><h4>' + section.title + '</h4>' + rows + '</div>';
        }).join('');

        var signatureMarkup = '<div class="evaluation-signature-grid">' +
            '<div class="signature-preview">' +
                '<label>Student Signature</label>' +
                (studentSignature ? '<img src="' + studentSignature + '" alt="Student signature">' : '<div class="signature-empty">No student signature captured</div>') +
            '</div>' +
            '<div class="signature-preview">' +
                '<label>Supervisor Signature</label>' +
                (supervisorSignature ? '<img src="' + supervisorSignature + '" alt="Supervisor signature">' : '<div class="signature-empty">No supervisor signature captured</div>') +
            '</div>' +
            '</div>';

        evaluationViewBody.innerHTML = '<div class="evaluation-view-grid">' +
            '<div class="field"><label>Student</label><input type="text" value="' + getDisplayValue(studentName) + '" readonly></div>' +
            '<div class="field"><label>Assigned Supervisor</label><input type="text" value="' + getDisplayValue(assignedSupervisorName) + '" readonly></div>' +
            '<div class="field"><label>Evaluation Date</label><input type="text" value="' + getDisplayValue(evaluation.evaluation_date) + '" readonly></div>' +
            '<div class="field"><label>Partner Institution</label><input type="text" value="' + getDisplayValue(evaluation.partner_institution) + '" readonly></div>' +
            '<div class="field"><label>Strand</label><input type="text" value="' + getDisplayValue(studentInfo.strand) + '" readonly></div>' +
            '<div class="field"><label>Address</label><input type="text" value="' + getDisplayValue(studentInfo.address) + '" readonly></div>' +
            '<div class="field"><label>Contact No.</label><input type="text" value="' + getDisplayValue(studentInfo.phone || evaluation.contact_number) + '" readonly></div>' +
            '<div class="field"><label>Work Immersion Supervisor</label><input type="text" value="' + getDisplayValue(assignedSupervisorName) + '" readonly></div>' +
            '<div class="field"><label>Supervisor Position</label><input type="text" value="' + getDisplayValue(savedSupervisorPosition) + '" readonly></div>' +
            '<div class="field"><label>Training Period Start</label><input type="text" value="' + getDisplayValue(evaluation.training_start_date) + '" readonly></div>' +
            '<div class="field"><label>Training Period End</label><input type="text" value="' + getDisplayValue(evaluation.training_end_date) + '" readonly></div>' +
            '<div class="field"><label>Total Hours Rendered</label><input type="text" value="' + formatRenderedHours(evaluation.total_hours_rendered) + '" readonly></div>' +
            '<div class="field"><label>Overall Rating</label><input type="text" value="' + getDisplayValue(overallRating ? overallRating.toFixed(2) : '') + '" readonly></div>' +
            '</div>' +
            '<div class="transmutation-output" aria-label="Transmutation output">' +
                '<div class="cell label">Computed Weighted Average</div>' +
                '<div class="cell value">' + (weightedAverage ? weightedAverage.toFixed(2) : '0.00') + '</div>' +
                '<div class="cell label">Equivalent Grade</div>' +
                '<div class="cell value">' + (equivalentGrade ? equivalentGrade : '0') + '</div>' +
            '</div>' +
            rubricMarkup +
            signatureMarkup +
            '<div class="field" style="margin-top:20px;"><label>Remarks / Comments</label><textarea rows="5" readonly>' + (evaluation.comments || '') + '</textarea></div>';

        if (evaluationModal) {
            evaluationModal.classList.add('visible');
        }
    }

    function updateEvaluationHistory() {
        if (!studentSelect) {
            return;
        }

        var selectedStudentId = studentSelect.value;
        var selectedStudentName = studentProfileMap[selectedStudentId] && studentProfileMap[selectedStudentId].student_name
            ? studentProfileMap[selectedStudentId].student_name
            : '';

        if (evaluationHistoryTitle) {
            evaluationHistoryTitle.textContent = selectedStudentId
                ? 'Performance Evaluations for ' + (selectedStudentName || selectedStudentId)
                : 'Performance Evaluations';
        }

        if (evaluationHistoryNote) {
            evaluationHistoryNote.style.display = selectedStudentId ? 'block' : 'none';
        }

        var visibleCount = 0;
        Array.prototype.forEach.call(evaluationRows, function (row) {
            var matches = !selectedStudentId || row.dataset.studentId === selectedStudentId;
            row.style.display = matches ? '' : 'none';
            if (matches) {
                visibleCount += 1;
            }
        });

        if (noEvaluationMessage) {
            noEvaluationMessage.style.display = selectedStudentId && visibleCount === 0 ? 'block' : 'none';
        }
    }

    if (studentSelect) {
        studentSelect.addEventListener('change', function () {
            populateStudentDetails(this.value);
            updateEvaluationHistory();
        });

        if (studentSelect.value) {
            populateStudentDetails(studentSelect.value);
        }

        updateEvaluationHistory();
    }

    Array.prototype.forEach.call(document.querySelectorAll('.view-evaluation-btn'), function (button) {
        button.addEventListener('click', function () {
            var rawData = button.getAttribute('data-evaluation-json');
            try {
                renderEvaluationModal(JSON.parse(rawData));
            } catch (error) {
                console.error('Failed to parse evaluation data', error);
            }
        });
    });

    function closeEvaluationModal() {
        if (evaluationModal) {
            evaluationModal.classList.remove('visible');
        }
        if (evaluationViewBody) {
            evaluationViewBody.innerHTML = '';
        }
    }

    if (closeViewModalBtn && evaluationModal) {
        closeViewModalBtn.addEventListener('click', closeEvaluationModal);

        evaluationModal.addEventListener('click', function (event) {
            if (event.target === evaluationModal || event.target.closest('.modal-close-btn')) {
                closeEvaluationModal();
            }
        });
    }

    var profileModal = document.getElementById('profile-modal');
    var profileOpenButton = document.getElementById('open-profile-modal');
    var profileCloseButton = document.getElementById('close-profile-modal');
    if (profileOpenButton && profileModal) {
        profileOpenButton.addEventListener('click', function () {
            profileModal.classList.add('visible');
        });
    }
    if (profileCloseButton && profileModal) {
        profileCloseButton.addEventListener('click', function () {
            profileModal.classList.remove('visible');
        });
    }
    if (profileModal) {
        profileModal.addEventListener('click', function (event) {
            if (event.target === profileModal) {
                profileModal.classList.remove('visible');
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (evaluationModal && evaluationModal.classList.contains('visible')) {
                closeEvaluationModal();
            }
            if (profileModal && profileModal.classList.contains('visible')) {
                profileModal.classList.remove('visible');
            }
        }
    });

    attachSignaturePad('student_signature_canvas', 'student_signature', false);
    attachSignaturePad('supervisor_signature_canvas', 'supervisor_signature', true);

    if (overallInput) {
        overallInput.readOnly = true;
        overallInput.setAttribute('aria-readonly', 'true');

        function updateOverallRating() {
            var scores = Array.prototype.map.call(rubricInputs, function (input) {
                return input.value === 'NA' ? 0 : Number(input.value);
            }).filter(function (score) {
                return !Number.isNaN(score) && score >= 0 && score <= 5;
            });
            var average = scores.length ? scores.reduce(function (total, score) {
                return total + score;
            }, 0) / scores.length : 0;
            var grade = getTransmutationGrade(average);

            overallInput.value = average ? average.toFixed(2) : '';
            if (computedWeightedAverageDisplay) {
                computedWeightedAverageDisplay.textContent = average ? average.toFixed(2) : '0.00';
            }
            if (equivalentGradeInput) {
                equivalentGradeInput.value = grade ? String(grade) : '';
            }
            if (equivalentGradeDisplay) {
                equivalentGradeDisplay.textContent = grade ? String(grade) : '0';
            }

            if (punctualityInput) punctualityInput.value = calculateAverage(getSectionValues('attendance_punctuality')).toFixed(2);
            if (workQualityInput) workQualityInput.value = calculateAverage(getSectionValues('productivity_resilience')).toFixed(2);
            if (attitudeInput) attitudeInput.value = calculateAverage(getSectionValues('attitude')).toFixed(2);
            if (teamworkInput) teamworkInput.value = calculateAverage(getSectionValues('team_work')).toFixed(2);
        }

        Array.prototype.forEach.call(rubricInputs, function (input) {
            input.addEventListener('change', updateOverallRating);
        });
        updateOverallRating();
    }
}());
</script>
</body></html>
