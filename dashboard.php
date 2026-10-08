<?php
date_default_timezone_set('Asia/Manila');
require_once 'auth.php';
require_login(['coordinator']);
$conn = db_connection();
$result = $conn->query("SELECT attendance_logs.*, agencies.agency_name
    FROM attendance_logs
    LEFT JOIN student_supervisor_assignments
        ON student_supervisor_assignments.student_id = attendance_logs.student_id
    LEFT JOIN agencies ON agencies.agency_id = student_supervisor_assignments.agency_id
    ORDER BY attendance_logs.log_time DESC");
if (!$result) {
    error_log('Coordinator dashboard attendance query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load the coordinator dashboard. Please contact the administrator.');
}
$locations = [];
$index = 0;

// helper: map stored strand code or label to full display label
function map_strand_label($s) {
    if ($s === null) return null;
    $map = [
        'ABM' => 'ACCOUNTANCY, BUSINESS AND MANAGEMENT',
        'ICT' => 'INFORMATION, COMMUNICATION AND TECHNOLOGY',
        'HUMSS' => 'HUMANITIES AND SOCIAL SCIENCES',
        'HE' => 'HOME ECONOMICS',
        // also accept full labels already stored
        'ACCOUNTANCY, BUSINESS AND MANAGEMENT' => 'ACCOUNTANCY, BUSINESS AND MANAGEMENT',
        'INFORMATION, COMMUNICATION AND TECHNOLOGY' => 'INFORMATION, COMMUNICATION AND TECHNOLOGY',
        'HUMANITIES AND SOCIAL SCIENCES' => 'HUMANITIES AND SOCIAL SCIENCES',
        'HOME ECONOMICS' => 'HOME ECONOMICS'
    ];
    $s_trim = trim($s);
    $s_clean = preg_replace('/^\d+\.\s*/', '', $s_trim);
    $s_up = strtoupper($s_clean);
    // prefer code mapping
    if (isset($map[$s_up])) return $map[$s_up];
    // fallback: if it's already a label but different case
    foreach ($map as $k => $v) {
        if (strtoupper($k) === $s_up) return $v;
        if (strtoupper($v) === $s_up) return $v;
    }
    return $s_clean; // unknown, return without a numeric prefix
}

function select_attendance_event($events, $latest) {
    if (empty($events)) return null;
    usort($events, function ($a, $b) {
        return strcmp($a['time'], $b['time']);
    });
    return $latest ? end($events) : $events[0];
}

function location_access_allowed() {
    $current_time = date('H:i');
    return $current_time >= '06:00' && $current_time < '18:00';
}

function attendance_slot_should_be_blank($date, $attendance_type) {
    $today = date('Y-m-d');
    $current_time = date('H:i');

    if ($date === null || $date > $today) {
        return true;
    }

    if ($date < $today) {
        return false;
    }

    if (stripos($attendance_type, 'AM') !== false) {
        return $current_time < '12:00';
    }

    if (stripos($attendance_type, 'PM') !== false) {
        return $current_time < '18:00';
    }

    return false;
}

function attendance_event_cell($event, $badge_class, $student_id, $attendance_type, $date = null) {
    if ($event === null) {
        if (attendance_slot_should_be_blank($date, $attendance_type)) {
            return '';
        }
        return '<span class="attendance-absent">Absent</span>';
    }
    $time = '<span class="badge ' . $badge_class . '">' . htmlspecialchars(date('g:i A', strtotime($event['time']))) . '</span>';
    if ($event['lat'] === null || $event['lng'] === null) {
        return $time . '<br><span class="print-exclude"><span class="badge bg-secondary mt-1">No Location</span></span>';
    }
    if (!location_access_allowed()) {
        return $time . '<br><span class="print-exclude"><span class="badge bg-secondary mt-1 disabled" aria-disabled="true" tabindex="-1" style="pointer-events:none; cursor:not-allowed;" title="Location access is available from 6:00 AM to 6:00 PM">Location Hidden</span></span>';
    }
    $url = 'map.php?lat=' . rawurlencode((string)$event['lat'])
        . '&lng=' . rawurlencode((string)$event['lng'])
        . '&student_id=' . rawurlencode($student_id)
        . '&date=' . rawurlencode($date ?? '')
        . '&time=' . rawurlencode($event['time'])
        . '&attendance_type=' . rawurlencode($attendance_type)
        . '&strand=' . rawurlencode($event['strand'] ?? '')
        . '&agency=' . rawurlencode($event['agency_name'] ?? '');
    return $time . '<br><span class="print-exclude"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-outline-primary mt-1">View Location</a></span>';
}

function all_locations_link($student_rows) {
    $locations = [];
    $event_labels = [
        'am_in' => 'AM Time In',
        'am_out' => 'AM Time Out',
        'pm_in' => 'PM Time In',
        'pm_out' => 'PM Time Out'
    ];
    foreach ($student_rows as $student_row) {
        foreach ($event_labels as $event_key => $event_label) {
            $event = $student_row[$event_key];
            if ($event !== null && $event['lat'] !== null && $event['lng'] !== null) {
                $locations[] = [
                    'lat' => $event['lat'],
                    'lng' => $event['lng'],
                    'date' => $student_row['date'],
                    'time' => $event['time'],
                    'attendance_type' => $event_label,
                    'strand' => map_strand_label($student_row['strand']) ?? '',
                    'agency' => $event['agency_name'] ?? ''
                ];
            }
        }
    }
    if (empty($locations)) return '<span class="badge bg-secondary">No Locations</span>';
    if (!location_access_allowed()) {
        return '<span class="btn btn-sm btn-secondary disabled" aria-disabled="true" tabindex="-1" style="pointer-events:none; cursor:not-allowed;" title="Location access is available from 6:00 AM to 6:00 PM">View All Locations</span>';
    }
    $url = 'map.php?student_id=' . rawurlencode($student_rows[0]['student_id'])
        . '&locations=' . rawurlencode(json_encode($locations, JSON_UNESCAPED_SLASHES));
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-outline-primary">View All Locations</a>';
}

$student_start_dates = [];
$registered_student_ids = [];
$student_ids_by_name = [];
$student_registry_result = $conn->query('SELECT id, student_name, DATE(created_at) AS start_date FROM students');
if (!$student_registry_result) {
    error_log('Coordinator dashboard student query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load coordinator student records. Please contact the administrator.');
}
while ($student_registry_row = $student_registry_result->fetch_assoc()) {
    $registered_student_id = (string)$student_registry_row['id'];
    $registered_student_ids[$registered_student_id] = true;
    $student_start_dates[$registered_student_id] = $student_registry_row['start_date'];

    $student_name_key = strtolower(trim((string)$student_registry_row['student_name']));
    if ($student_name_key !== '') {
        if (array_key_exists($student_name_key, $student_ids_by_name)) {
            $student_ids_by_name[$student_name_key] = null;
        } else {
            $student_ids_by_name[$student_name_key] = $registered_student_id;
        }
    }
}

// Group logs by student AND date (so each row represents a student for a specific date)
$students = [];
while($row = $result->fetch_assoc()) {
    $stored_student_id = trim((string)$row['student_id']);
    $student_name_key = strtolower($stored_student_id);
    if (isset($registered_student_ids[$stored_student_id])) {
        $sid = $stored_student_id;
    } elseif (isset($student_ids_by_name[$student_name_key]) && $student_ids_by_name[$student_name_key] !== null) {
        $sid = $student_ids_by_name[$student_name_key];
    } else {
        $sid = $stored_student_id;
    }
    $date = date('Y-m-d', strtotime($row['log_time']));
    $student_start_date = $student_start_dates[$sid] ?? null;
    if ($student_start_date !== null && $date < $student_start_date) {
        continue;
    }
    $key = $sid . '|' . $date;
    if (!isset($students[$key])) {
        $students[$key] = [
            'student_id' => $sid,
            'date' => $date,
            'strand' => null,
            'am_in_events' => [],
            'am_out_events' => [],
            'pm_in_events' => [],
            'pm_out_events' => []
        ];
    }
    // store latest strand selection as well (first seen per DESC order)
    if ($students[$key]['strand'] === null && isset($row['strand']) && $row['strand'] !== null) {
        $students[$key]['strand'] = $row['strand'];
    }
    $status = strtolower($row['status']);
    $time = $row['log_time'];
    $event = [
        'time' => $time,
        'lat' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
        'lng' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
        'strand' => isset($row['strand']) ? map_strand_label($row['strand']) : null,
        'agency_name' => $row['agency_name'] ?? null
    ];
    $hour = (int)date('H', strtotime($time));
    if ($hour < 12) {
        if (strpos($status, 'in') !== false) $students[$key]['am_in_events'][] = $event;
        if (strpos($status, 'out') !== false) $students[$key]['am_out_events'][] = $event;
    } else {
        if (strpos($status, 'in') !== false) $students[$key]['pm_in_events'][] = $event;
        if (strpos($status, 'out') !== false) $students[$key]['pm_out_events'][] = $event;
    }
}

// Prepare simplified student/date list and locations
$students_list = [];
foreach ($students as $key => $data) {
    $am_in = select_attendance_event($data['am_in_events'], false);
    $am_out = select_attendance_event($data['am_out_events'], true);
    $pm_in = select_attendance_event($data['pm_in_events'], false);
    $pm_out = select_attendance_event($data['pm_out_events'], true);
    $students_list[] = [
        'group_key' => $key,
        'student_id' => $data['student_id'],
        'date' => $data['date'],
        'day' => date('l', strtotime($data['date'])),
        'strand' => $data['strand'],
        'am_in' => $am_in,
        'am_out' => $am_out,
        'pm_in' => $pm_in,
        'pm_out' => $pm_out
    ];
}

// Group attendance rows by student so each student has their own table
$student_groups = [];
foreach ($students_list as $student_row) {
    $sid = $student_row['student_id'];
    if (!isset($student_groups[$sid])) {
        $student_groups[$sid] = [];
    }
    $student_groups[$sid][] = $student_row;
}

// rebuild locations from students_list
$locations = [];
foreach ($students_list as $s) {
    foreach (['am_in', 'am_out', 'pm_in', 'pm_out'] as $event_type) {
        $event = $s[$event_type];
        if ($event !== null && $event['lat'] !== null && $event['lng'] !== null) {
            $locations[] = ['lat' => $event['lat'], 'lng' => $event['lng'], 'student_id' => $s['student_id'], 'group_key' => $s['group_key'], 'event_type' => $event_type];
        }
    }
}
$default_map_location = !empty($locations) ? $locations[0] : null;

// map student_id to location index for quick lookup when rendering buttons
$loc_index_map = [];
foreach ($locations as $i => $l) {
    // map by group_key (student+date) so per-date rows find their location
    if (isset($l['group_key'])) {
        $loc_index_map[$l['group_key']] = $i;
    } else {
        $loc_index_map[$l['student_id']] = $i;
    }
}

$selected_student_id = isset($_GET['student_id']) ? trim($_GET['student_id']) : null;
$selected_student_id = $selected_student_id !== '' ? $selected_student_id : null;
$student_exists = $selected_student_id !== null
    && (isset($student_groups[$selected_student_id]) || isset($registered_student_ids[$selected_student_id]));

$requested_view = isset($_GET['view']) ? $_GET['view'] : null;
$valid_views = ['overview', 'students', 'reports', 'detail'];
if ($requested_view !== null && in_array($requested_view, $valid_views, true)) {
    $current_view = $requested_view;
} else {
    $current_view = $student_exists ? 'detail' : 'overview';
}
if ($current_view === 'detail' && !$student_exists) {
    $current_view = 'overview';
}

function nav_item_class($view) {
    global $current_view;
    return $current_view === $view ? 'active' : '';
}

function add_attendance_calendar_rows($student_rows, $student_id) {
    $rows_by_date = [];
    $student_strand = null;
    foreach ($student_rows as $student_row) {
        $rows_by_date[$student_row['date']] = $student_row;
        if ($student_strand === null && !empty($student_row['strand'])) {
            $student_strand = $student_row['strand'];
        }
    }

    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $first_logged_date = !empty($rows_by_date)
        ? new DateTimeImmutable(min(array_keys($rows_by_date)), new DateTimeZone('Asia/Manila'))
        : $today;

    $student_start_date = null;
    $student_start_result = db_connection()->query('SELECT DATE(created_at) AS start_date FROM students WHERE id = ' . (int)$student_id . ' LIMIT 1');
    if ($student_start_result && $student_start_result->num_rows > 0) {
        $student_start_row = $student_start_result->fetch_assoc();
        if (!empty($student_start_row['start_date'])) {
            $student_start_date = new DateTimeImmutable($student_start_row['start_date'], new DateTimeZone('Asia/Manila'));
        }
    }

    $start_date = $student_start_date ?: $first_logged_date;
    if ($start_date > $today) {
        $start_date = $today;
    }

    for ($date = $start_date; $date <= $today; $date = $date->modify('+1 day')) {
        $date_string = $date->format('Y-m-d');
        if (!isset($rows_by_date[$date_string])) {
            $rows_by_date[$date_string] = [
                'group_key' => $student_id . '|' . $date_string,
                'student_id' => $student_id,
                'date' => $date_string,
                'day' => $date->format('l'),
                'strand' => $student_strand,
                'am_in' => null,
                'am_out' => null,
                'pm_in' => null,
                'pm_out' => null
            ];
        }
    }

    uasort($rows_by_date, function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    return array_values($rows_by_date);
}

$selected_rows = $student_exists ? ($student_groups[$selected_student_id] ?? []) : [];
if ($student_exists) {
    $selected_rows = add_attendance_calendar_rows($selected_rows, $selected_student_id);
}

$student_profile_map = [];
$student_details_stmt = $conn->prepare('SELECT s.id AS student_id, s.student_name, s.strand, s.address, s.phone, s.email, s.parents, s.gender, s.dob, a.agency_name, u.display_name AS supervisor_name FROM students s LEFT JOIN student_supervisor_assignments x ON x.student_id = s.id LEFT JOIN users u ON u.id = x.supervisor_id LEFT JOIN agencies a ON a.agency_id = x.agency_id ORDER BY s.student_name ASC, s.id ASC');
$student_details_stmt->execute();
$student_details_result = $student_details_stmt->get_result();
while ($student_row = $student_details_result->fetch_assoc()) {
    $student_id = (string)($student_row['student_id'] ?? '');
    $student_name = trim((string)($student_row['student_name'] ?? ''));
    $student_profile_map[$student_id] = [
        'student_id' => $student_id,
        'student_name' => $student_name !== '' ? $student_name : $student_id,
        'strand' => $student_row['strand'] ?? '',
        'address' => $student_row['address'] ?? '',
        'phone' => $student_row['phone'] ?? '',
        'email' => $student_row['email'] ?? '',
        'parents' => $student_row['parents'] ?? '',
        'gender' => $student_row['gender'] ?? '',
        'dob' => $student_row['dob'] ?? '',
        'partner_institution' => $student_row['agency_name'] ?? '',
        'supervisor_name' => $student_row['supervisor_name'] ?? '',
    ];
}
$total_students = count($student_profile_map);
$strand_roster = [
    'ACCOUNTANCY, BUSINESS AND MANAGEMENT' => [],
    'INFORMATION, COMMUNICATION AND TECHNOLOGY' => [],
    'HUMANITIES AND SOCIAL SCIENCES' => [],
    'HOME ECONOMICS' => [],
];
foreach ($student_profile_map as $student_id => $student_profile) {
    $strand_label = map_strand_label($student_profile['strand']);
    if (isset($strand_roster[$strand_label])) {
        $strand_roster[$strand_label][$student_id] = $student_profile;
    }
}
foreach ($strand_roster as &$strand_students) {
    uasort($strand_students, function ($a, $b) {
        return strcasecmp($a['student_name'], $b['student_name']);
    });
}
unset($strand_students);
$selected_student_name = $student_exists ? ($student_profile_map[$selected_student_id]['student_name'] ?? $selected_student_id) : null;

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
$selected_evaluations = [];
if ($student_exists) {
    $evaluation_stmt = $conn->prepare('SELECT e.*, u.display_name AS evaluator_name FROM performance_evaluations e JOIN users u ON u.id = e.evaluator_id WHERE e.student_id = ? ORDER BY e.evaluation_date DESC, e.created_at DESC');
    $evaluation_stmt->bind_param('s', $selected_student_id);
    $evaluation_stmt->execute();
    $evaluation_result = $evaluation_stmt->get_result();
    while ($evaluation_row = $evaluation_result->fetch_assoc()) {
        $selected_evaluations[] = $evaluation_row;
    }
}
$selected_locations = [];
if ($student_exists) {
    foreach ($locations as $loc) {
        if ($loc['student_id'] === $selected_student_id) {
            $selected_locations[] = $loc;
        }
    }
}

$total_students = count($student_groups);
$total_tracked_days = count(array_unique(array_column($students_list, 'date')));
$total_rows = count($students_list);
$total_locations = count($locations);
$distinct_strands = count(array_filter(array_unique(array_map(function ($s) {
    return $s['strand'];
}, $students_list))));

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>i-Tracker Dashboard</title>
    <link href="bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet" />
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            color-scheme: light;
            font-family:"Book Antiqua", sans-serif;
            color: #0f172a;
            background: #6eb1f4;
            --surface: transparent;
            --surface-strong: transparent;
            --surface-muted: transparent;
            --border: rgba(148, 163, 184, 0.24);
            --text-muted: #64748b;
            --text-primary: #102a43;
            --accent: #dc2626;
            --accent-soft: #fef2f2;
            --accent-dark: #991b1b;
        }
        html, body {
            margin: 0;
            min-height: 100%;
            background: linear-gradient(180deg, #eef2ff 0%, #f8fafc 100%);
        }
        body {
            padding: 0;
            background-image: radial-gradient(circle at top left, rgba(220, 38, 38, 0.12), transparent 18%),
                              radial-gradient(circle at bottom right, rgba(59, 130, 246, 0.12), transparent 20%);
        }
        .container {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        .dashboard-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            align-items: stretch;
            min-height: 100vh;
            gap: 0;
            background: rgba(255, 255, 255, 0.82);
            border: 0;
            border-radius: 0;
            box-shadow: none;
            overflow: hidden;
        }
        .dashboard-layout.sidebar-collapsed {
            grid-template-columns: 72px 1fr;
        }
        .dashboard-main {
            display: flex;
            flex-direction: column;
            background: transparent;
            min-height: 100%;
        }
        .dashboard-sidebar {
            position: sticky;
            top: 0;
            align-self: stretch;
            background: linear-gradient(180deg, rgba(59, 130, 246, 0.96), rgba(220, 38, 38, 0.95));
            border: none;
            border-radius: 0;
            box-shadow: none;
            padding: 28px 24px;
            color: #f8fafc;
            backdrop-filter: blur(16px);
            min-height: 100%;
            transition: width 0.2s ease, padding 0.2s ease;
            display: flex;
            flex-direction: column;
            justify-content: start;
        }
        .dashboard-layout.sidebar-collapsed .dashboard-sidebar {
            width: 72px;
            padding-left: 16px;
            padding-right: 16px;
        }
        .dashboard-layout.sidebar-collapsed .sidebar-brand h2,
        .dashboard-layout.sidebar-collapsed .sidebar-brand p,
        .dashboard-layout.sidebar-collapsed .sidebar-link-description,
        .dashboard-layout.sidebar-collapsed .dashboard-nav,
        .dashboard-layout.sidebar-collapsed .sidebar-section-title,
        .dashboard-layout.sidebar-collapsed .sidebar-toggle-btn span.label {
            display: none;
        }
        .dashboard-layout.sidebar-collapsed .sidebar-link {
            grid-template-columns: 1fr;
            justify-items: center;
            text-align: center;
        }
        .dashboard-layout.sidebar-collapsed .sidebar-link-icon {
            margin: 0 auto;
        }
        .dashboard-layout.sidebar-collapsed .sidebar-link-text {
            display: none;
        }
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
        }
        .sidebar-toggle-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 12px 16px;
            margin-bottom: 22px;
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
        }
        .sidebar-toggle-btn:hover {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, 0.22);
        }
        .sidebar-toggle-btn .material-symbols-outlined {
            font-size: 1.4rem;
        }
        .sidebar-logo {
            width: 46px;
            height: 46px;
            border-radius: 18px;
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 1.2rem;
            color: #ffffff;
            background: linear-gradient(135deg, var(--accent) 0%, #2563eb 100%);
            box-shadow: 0 16px 30px rgba(220, 38, 38, 0.2);
        }
        .sidebar-brand h2 {
            margin: 0;
            font-size: 1.2rem;
            line-height: 1.15;
            color: #ffffff;
        }
        .sidebar-brand p {
            margin: 0;
            color: rgba(255, 255, 255, 0.78);
            font-size: 0.92rem;
            line-height: 1.4;
        }
        .sidebar-section-title {
            margin: 12px 0 10px;
            color: #475569;
            font-size: 0.82rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            font-weight: 700;
        }
        .sidebar-nav {
            display: grid;
            gap: 12px;
        }
        .sidebar-menu-wrapper {
            display: grid;
            gap: 8px;
        }
        .sidebar-menu-trigger {
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            cursor: pointer;
            text-align: left;
            font: inherit;
        }
        .sidebar-submenu {
            display: none;
            background: rgba(15, 23, 42, 0.22);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 8px;
            margin-top: -4px;
            gap: 6px;
        }
        .sidebar-menu-wrapper.expanded .sidebar-submenu {
            display: grid;
        }
        .sidebar-submenu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 42px;
            padding: 10px 12px;
            border-radius: 10px;
            color: #e2e8f0;
            text-decoration: none;
            font-size: 0.86rem;
            border: 1px solid transparent;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .sidebar-submenu-link:hover,
        .sidebar-submenu-link.active {
            background: #1e293b;
            border-color: rgba(96, 165, 250, 0.28);
            color: #ffffff;
            text-decoration: none;
        }
        .sidebar-submenu-link .sidebar-link-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.08);
            color: #94a3b8;
            font-size: 1rem;
        }
        .sidebar-submenu-link:hover .sidebar-link-icon,
        .sidebar-submenu-link.active .sidebar-link-icon {
            background: rgba(37, 99, 235, 0.35);
            color: #ffffff;
        }
        .sidebar-submenu-link .sidebar-link-label {
            font-size: 0.86rem;
            color: inherit;
        }
        .sidebar-link {
            display: grid;
            grid-template-columns: auto 1fr;
            align-items: center;
            gap: 14px;
            width: 100%;
            padding: 16px 18px;
            border-radius: 20px;
            color: #ffffff;
            background: rgba(255, 255, 255, 0.12);
            text-decoration: none;
            font-weight: 600;
            transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }
        .sidebar-link:hover {
            transform: translateX(2px);
            background: rgba(16, 185, 129, 0.20);
            border-color: rgba(16, 185, 129, 0.42);
            color: #0f172a;
        }
        .sidebar-link:hover .sidebar-link-icon {
            background: rgba(16, 185, 129, 0.24);
            color: #ffffff;
        }
        .sidebar-link.active {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.24);
            border-color: rgba(255, 255, 255, 0.32);
            box-shadow: 0 18px 30px rgba(220, 38, 38, 0.16);
        }
        .sidebar-link.active .sidebar-link-icon {
            background: rgba(255, 255, 255, 0.38);
        }
        .sidebar-link-icon {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.22);
            color: #0f172a;
            font-size: 1.2rem;
        }
        .sidebar-link-text {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .sidebar-link-label {
            font-size: 0.98rem;
            color: #ffffff;
        }
        .sidebar-link-description {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.82);
            line-height: 1.3;
        }
        .dashboard-card {
            background: transparent;
            backdrop-filter: none;
            border-radius: 0;
            box-shadow: none;
            overflow: visible;
            border: none;
            width: 100%;
        }
        .dashboard-nav {
            display: flex;
            gap: 12px;
            padding: 20px 36px 0;
            align-items: center;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        }
        .dashboard-nav-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            border-radius: 999px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.95rem;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }
        .dashboard-nav-item:hover,
        .dashboard-nav-item.active {
            color: var(--accent-dark);
            border-color: rgba(220, 38, 38, 0.18);
            background: rgba(220, 38, 38, 0.08);
        }
        .dashboard-header {
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 28px;
            padding: 32px 36px 20px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(12px);
            border-radius: 0;
        }
        .dashboard-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            border-radius: 999px;
            background: rgba(220, 38, 38, 0.14);
            color: var(--accent-dark);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            width: fit-content;
        }
        .dashboard-title {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .dashboard-title h1 {
            font-size: clamp(2rem, 3vw, 2.7rem);
            line-height: 1.04;
            margin: 0;
            letter-spacing: -0.04em;
            background: linear-gradient(90deg, #2563eb 0%, #dc2626 45%, #0ea5e9 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: transparent;
        }
        .dashboard-title p {
            margin: 0;
            color: var(--text-muted);
            font-size: 1rem;
            line-height: 1.75;
            max-width: 780px;
        }
        .header-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 14px;
        }
        .sidebar-toggle-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 12px 16px;
            margin-bottom: 22px;
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
        }
        .sidebar-toggle-btn:hover {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, 0.22);
        }
        .sidebar-toggle-btn .material-symbols-outlined {
            font-size: 1.4rem;
        }
        .dashboard-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: flex-end;
        }
        .btn-secondary,
        .btn-map-link,
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 14px 24px;
            border-radius: 999px;
            border: none;
            font-size: 0.95rem;
            font-weight: 600;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-secondary {
            color: #0f172a;
            background: transparent;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        }
        .btn-secondary:hover {
            transform: translateY(-1px);
            background: rgba(220, 38, 38, 0.08);
        }
        .btn-map-link {
            color: #ffffff;
            background: linear-gradient(135deg, var(--accent) 0%, #2563eb 100%);
            min-width: 150px;
        }
        .btn-map-link:hover {
            transform: translateY(-1px);
            background: linear-gradient(135deg, #b91c1c 0%, #1d4ed8 100%);
        }
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(240px, 1fr));
            gap: 18px;
            margin: 20px 0 0;
            align-items: stretch;
        }
        .dashboard-stats > .card {
            width: 100%;
            min-width: 0;
            height: 100%;
            display: flex;
        }
        .stat-card {
            flex: 1;
            height: 100%;
            min-height: 170px;
            padding: 24px 24px;
            border-radius: 24px;
            background: #ffffff;
            border: 1px solid rgba(220, 38, 38, 0.18);
            box-shadow: 0 16px 35px rgba(15, 23, 42, 0.06);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-card .card-body {
            flex: 1;
            min-height: 0;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .stat-card .card-body > div {
            min-width: 0;
        }
        .stat-card small {
            display: block;
            color: var(--text-muted);
            margin-bottom: 8px;
            font-size: 0.92rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            white-space: nowrap;
        }
        .stat-card h3 {
            font-size: 2rem;
            margin: 0;
            line-height: 1;
            color: var(--text-primary);
            white-space: nowrap;
        }
        .stat-card .material-symbols-outlined {
            flex-shrink: 0;
            width: 52px;
            height: 52px;
            font-size: 2.5rem;
            display: grid;
            place-items: center;
        }
        .stat-card .card-body > div {
            min-width: 0;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 18px 40px rgba(220, 38, 38, 0.18);
        }
        .stat-card--students:hover {
            border-color: rgba(59, 130, 246, 0.65);
            background: linear-gradient(135deg, rgba(24, 104, 232, 0.32), rgba(220, 38, 38, 0.16));
            box-shadow: 0 20px 45px rgba(24, 104, 232, 0.18);
            color: #45d81d;
        }
        .stat-card--students:hover .card-body,
        .stat-card--students:hover .material-symbols-outlined,
        .stat-card--students:hover .card-body h3,
        .stat-card--students:hover .card-body small {
            color: #45d81d !important;
        }
        .stat-card--days:hover {
            border-color: rgba(16, 185, 129, 0.65);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.32), rgba(94, 234, 212, 0.16));
            box-shadow: 0 20px 45px rgba(16, 185, 129, 0.18);
            color: #16a34a;
        }
        .stat-card--days:hover .card-body,
        .stat-card--days:hover .material-symbols-outlined,
        .stat-card--days:hover .card-body h3,
        .stat-card--days:hover .card-body small {
            color: #16a34a !important;
        }
        .stat-card--locations:hover {
            border-color: rgba(234, 88, 12, 0.65);
            background: linear-gradient(135deg, rgba(251, 63, 229, 0.32), rgba(168, 85, 247, 0.16));
            box-shadow: 0 20px 45px rgba(234, 88, 12, 0.18);
            color: #be185d;
        }
        .stat-card--locations:hover .card-body,
        .stat-card--locations:hover .material-symbols-outlined,
        .stat-card--locations:hover .card-body h3,
        .stat-card--locations:hover .card-body small {
            color: #be185d !important;
        }
        .stat-card small {
            display: block;
            color: var(--text-muted);
            margin-bottom: 10px;
            font-size: 0.92rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .stat-card strong {
            display: block;
            font-size: 2.1rem;
            color: var(--text-primary);
            line-height: 1;
        }
        .student-card-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            padding: 18px 0 24px;
        }
        .student-card {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 110px;
            padding: 24px 22px;
            border-radius: 24px;
            background: linear-gradient(135deg, rgba(59, 159, 246, 0.95), rgba(81, 158, 246, 0.95));
            border: none;
            text-decoration: none;
            color: #ffffff;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .student-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 35px rgba(15, 23, 42, 0.08);
            border-color: rgba(220, 38, 38, 0.22);
        }
        .student-card strong {
            display: block;
            font-size: 1.05rem;
            line-height: 1.4;
            margin-bottom: 8px;
            color: #ffffff;
        }
        .student-card small {
            display: block;
            color: rgba(255, 255, 255, 0.85);
            font-size: 0.9rem;
        }
        .student-card-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 80px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .student-detail-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 24px;
        }
        .student-detail-title h2 {
            margin: 0 0 8px;
            font-size: 1.7rem;
            color: var(--text-primary);
        }
        .student-detail-title p {
            margin: 0;
            color: var(--text-muted);
            font-size: 0.97rem;
        }
        .student-detail-meta {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            color: var(--text-muted);
            font-size: 0.92rem;
        }
        .student-detail-meta span {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(220, 38, 38, 0.08);
        }
        .search-panel {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
        }
        .search-panel input {
            width: 240px;
            min-width: 180px;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            padding: 12px 16px;
            color: var(--text-primary);
            background: transparent;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.06);
        }
        .table-wrap {
            overflow-x: auto;
            padding: 18px 24px 24px;
            background: rgba(244, 244, 247, 0.95);
            border-radius: 28px;
            border: 1px solid rgba(15, 23, 42, 0.08);
        }
        .table-card {
            background: transparent;
            border: none;
            box-shadow: none;
        }
        .student-table-section {
            margin-bottom: 34px;
            padding: 28px 0 0;
            border-top: 1px solid rgba(15, 23, 42, 0.08);
        }
        .student-table-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
            font-size: 1rem;
            color: var(--text-primary);
        }
        .student-table-title span {
            color: var(--text-muted);
        }
        .evaluation-print-heading {
            display: none;
        }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 12px;
            min-width: 860px;
        }
        thead {
            background: transparent;
        }
        th, td {
            padding: 18px 18px;
            text-align: left;
            border: none;
            vertical-align: middle;
            font-size: 0.95rem;
        }
        thead th {
            color: #0f172a;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding-bottom: 12px;
        }
        tbody tr {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 18px;
        }
        tbody tr td:first-child {
            width: 42px;
            font-weight: 700;
            color: var(--accent-dark);
        }
        tbody tr:hover {
            background: #8fa4ed;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent-dark);
            font-size: 0.85rem;
            font-weight: 700;
        }
        .evaluation-view-modal {
            position: fixed;
            inset: 0;
            display: none;
            z-index: 1050;
        }
        .evaluation-view-modal.show {
            display: block;
        }
        .evaluation-view-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.58);
            backdrop-filter: blur(2px);
        }
        .evaluation-view-modal-dialog {
            position: relative;
            z-index: 1;
            width: min(760px, calc(100% - 32px));
            max-height: calc(100vh - 96px);
            margin: 72px auto;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 24px;
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.22);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .evaluation-view-modal-content {
            border: none;
            display: flex;
            flex-direction: column;
            max-height: inherit;
        }
        .evaluation-view-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.08), rgba(14, 165, 233, 0.06));
            border-bottom: 1px solid rgba(148, 163, 184, 0.22);
        }
        .evaluation-view-modal-header h5 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: 0.01em;
        }
        .evaluation-view-modal-body {
            padding: 24px;
            color: #334155;
            line-height: 1.7;
            overflow-y: auto;
            max-height: calc(100vh - 200px);
            -webkit-overflow-scrolling: touch;
        }
        .evaluation-view-modal-body .row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .evaluation-view-modal-body .row > div {
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 14px;
            padding: 14px 16px;
            min-height: 72px;
        }
        .evaluation-view-modal-body .row > .col-12 {
            grid-column: 1 / -1;
            min-height: auto;
        }
        .evaluation-view-modal-body strong {
            display: block;
            margin-bottom: 6px;
            color: #0f172a;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
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
        .evaluation-view-grid .field label {
            display: block;
            color: #334155;
            font-size: 0.88rem;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .evaluation-view-grid .field input,
        .evaluation-view-grid .field textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            color: #0f172a;
            font: inherit;
        }
        .evaluation-view-grid .field textarea {
            min-height: 120px;
            resize: vertical;
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
        .evaluation-view-rubric-row span {
            color: #0f172a;
            line-height: 1.5;
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
        .evaluation-signature-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin: 20px 0;
        }
        .signature-preview {
            border: none;
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
            border: none;
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
        .evaluation-view-modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 18px 24px 22px;
            border-top: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(248, 250, 252, 0.9);
        }
        .evaluation-view-modal .btn-close {
            border: none;
            background: rgba(15, 23, 42, 0.04);
            border-radius: 999px;
            width: 36px;
            height: 36px;
            font-size: 1.6rem;
            line-height: 1;
            color: #475569;
            cursor: pointer;
        }
        .evaluation-view-modal .btn-close:hover {
            background: rgba(220, 38, 38, 0.08);
            color: #7f1d1d;
        }
        .view-evaluation-btn {
            white-space: nowrap;
        }
        .no-location {
            width: 100%;
            max-width: 150px;
            border: 1px solid #cbd5e1;
            color: #94a3b8;
            border-radius: 999px;
            padding: 10px 14px;
            background: transparent;
            cursor: default;
            font-weight: 600;
        }
        .attendance-absent {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: #fee2e2;
            color: #b91c1c;
            font-size: 0.82rem;
            font-weight: 700;
        }
        .btn.disabled,
        .disabled {
            pointer-events: none !important;
            cursor: not-allowed !important;
            opacity: 0.7;
        }
        .control-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
            margin: 24px 0 0;
            padding: 0 36px 28px;
            justify-content: flex-end;
        }
        .control-row label {
            color: #475569;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .control-row input[type="number"] {
            width: 84px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 999px;
            padding: 10px 14px;
            font-size: 0.95rem;
            color: #0f172a;
            background: rgba(255, 255, 255, 0.75);
        }
        .control-row small {
            color: var(--text-muted);
            font-size: 0.88rem;
        }
        @media (max-width: 1100px) {
            .dashboard-layout {
                grid-template-columns: 1fr;
            }
            .dashboard-sidebar {
                position: relative;
                top: auto;
                min-height: auto;
                margin-bottom: 24px;
            }
        }
        @media (max-width: 980px) {
            .dashboard-header {
                grid-template-columns: 1fr;
            }
            .search-panel {
                justify-content: flex-start;
            }
            .dashboard-actions {
                justify-content: flex-start;
            }
        }
        @media (max-width: 720px) {
            .container {
                padding: 0;
            }
            .dashboard-card {
                border-radius: 24px;
            }
            .dashboard-header {
                padding: 24px 20px 18px;
            }
            .dashboard-stats {
                grid-template-columns: 1fr;
            }
            .evaluation-view-grid,
            .evaluation-signature-grid {
                grid-template-columns: 1fr;
            }
            .student-table-section {
                padding: 24px 20px 0;
            }
            .control-row {
                padding: 0 20px 20px;
                justify-content: flex-start;
            }
            .search-panel input {
                width: 100%;
                min-width: 0;
            }
            table {
                min-width: 0;
            }
            .btn-map-link,
            .btn-secondary {
                width: 100%;
            }
        }

        /* Modern coordinator sidebar theme */
        .dashboard-sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            box-sizing: border-box;
            overflow-y: auto;
            min-height: 0;
            padding: 24px 16px;
            background: #0f172a;
            color: #f8fafc;
            border-right: 1px solid rgba(148, 163, 184, 0.16);
            box-shadow: 14px 0 36px rgba(15, 23, 42, 0.08);
            backdrop-filter: none;
        }
        .dashboard-layout.sidebar-collapsed .dashboard-sidebar {
            width: 72px;
            padding-left: 12px;
            padding-right: 12px;
            box-sizing: border-box;
        }
        .dashboard-layout.sidebar-collapsed .sidebar-toggle-btn {
            width: 48px;
            height: 48px;
            padding: 0;
            margin: 0 auto 18px;
            flex-shrink: 0;
        }
        .dashboard-layout.sidebar-collapsed .sidebar-toggle-btn .material-symbols-outlined {
            display: inline-grid;
            place-items: center;
        }
        .sidebar-brand {
            gap: 12px;
            padding: 8px 12px 24px;
            margin-bottom: 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }
        .sidebar-logo {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #2563eb;
            box-shadow: none;
        }
        .sidebar-brand h2 {
            font-size: 1.08rem;
        }
        .sidebar-brand p {
            color: #94a3b8;
            font-size: 0.78rem;
        }
        .sidebar-section-title {
            margin: 24px 12px 10px;
            color: #64748b;
            font-size: 0.68rem;
            letter-spacing: 0.14em;
        }
        .sidebar-nav {
            gap: 6px;
            min-width: 0;
        }
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid transparent;
            border-radius: 12px;
            color: #cbd5e1;
            background: transparent;
            box-shadow: none;
        }
        .sidebar-link:hover {
            transform: none;
            color: #ffffff;
            background: #1e293b;
            border-color: transparent;
        }
        .sidebar-link.active {
            color: #ffffff;
            background: #2563eb;
            border-color: #3b82f6;
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.3);
        }
        .sidebar-link-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            color: #000000;
            font-size: 1.1rem;
        }
        .sidebar-link:hover .sidebar-link-icon,
        .sidebar-link.active .sidebar-link-icon {
            background: rgba(255, 255, 255, 0.16);
            color: #000000;
        }
        .sidebar-link-label {
            font-size: 0.9rem;
        }
        .sidebar-link-description {
            color: #94a3b8;
            font-size: 0.74rem;
        }
        .sidebar-link.active .sidebar-link-description {
            color: #dbeafe;
        }

        .sidebar-logout {
            margin-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 0 0 12px 12px;
            padding-top: 16px;
        }

        .sidebar-logout:hover {
            background: #b91c1c;
            border-color: #dc2626;
        }
        .sidebar-toggle-btn {
            margin-bottom: 18px;
            padding: 10px 12px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            background: #1e293b;
            color: #cbd5e1;
        }
        .sidebar-toggle-btn:hover {
            transform: none;
            background: #334155;
        }

        @media (max-width: 1100px) {
            .dashboard-sidebar {
                position: relative;
                top: auto;
                height: auto;
                min-height: auto;
                border-right: 0;
                box-shadow: 0 14px 30px rgba(15, 23, 42, 0.12);
            }
        }

        /* Modern main content theme */
        .dashboard-main {
            background: #f8fafc;
        }
        .dashboard-header {
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 24px;
            padding: 34px 40px 28px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            backdrop-filter: none;
        }
        .dashboard-title {
            gap: 12px;
        }
        .dashboard-title h1 {
            font-size: clamp(1.8rem, 3vw, 2.45rem);
            letter-spacing: -0.02em;
            background: none;
            color: #0f172a;
            -webkit-text-fill-color: initial;
        }
        .dashboard-title p {
            color: #64748b;
            font-size: 0.96rem;
            line-height: 1.6;
        }
        .header-actions {
            align-items: flex-end;
            justify-content: space-between;
        }
        .dashboard-actions {
            gap: 8px;
        }
        .dashboard-actions .btn-secondary {
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #ffffff;
            color: #334155;
            box-shadow: none;
        }
        .dashboard-actions .btn-secondary:hover {
            background: #f1f5f9;
            color: #1d4ed8;
            transform: none;
        }
        .dashboard-stats {
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            gap: 14px;
            margin-top: 24px;
        }
        .stat-card {
            min-height: 132px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #bfdbfe;
            box-shadow: 0 14px 30px rgba(37, 99, 235, 0.1);
        }
        .stat-card .card-body {
            gap: 12px;
        }
        .stat-card .material-symbols-outlined {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #eff6ff;
            color: #2563eb !important;
            font-size: 1.7rem;
        }
        .stat-card--days .material-symbols-outlined {
            background: #ecfdf5;
            color: #059669 !important;
        }
        .stat-card--locations .material-symbols-outlined {
            background: #fff7ed;
            color: #ea580c !important;
        }
        .stat-card small {
            margin-bottom: 6px;
            color: #64748b;
            font-size: 0.7rem;
            letter-spacing: 0.08em;
        }
        .stat-card h3 {
            color: #0f172a;
            font-size: 1.8rem;
        }
        .search-panel input {
            width: 230px;
            padding: 11px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: none;
        }
        .search-panel input:focus {
            outline: 3px solid rgba(37, 99, 235, 0.12);
            border-color: #2563eb;
        }
        .table-wrap {
            margin: 28px 40px 0;
            padding: 24px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.05);
        }
        .table-wrap > .row {
            margin: 0;
        }
        .student-card {
            min-height: 150px;
            padding: 20px;
            border: 1px solid #dbeafe;
            border-radius: 16px;
            background: linear-gradient(145deg, #2563eb, #1d4ed8);
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.12);
        }
        .student-card:hover {
            transform: translateY(-3px);
            border-color: #93c5fd;
            box-shadow: 0 16px 28px rgba(37, 99, 235, 0.2);
        }
        .student-card .material-symbols-outlined {
            margin-bottom: 12px !important;
            font-size: 2rem !important;
            opacity: 0.9;
        }
        .student-card h5 {
            font-size: 1rem;
        }
        .student-card p {
            color: #dbeafe !important;
            font-size: 0.82rem;
        }
        .student-card .badge {
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 0.72rem;
        }
        .student-detail-header {
            padding-bottom: 20px;
            margin-bottom: 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .student-detail-title h2 {
            font-size: 1.4rem;
            color: #0f172a;
        }
        .student-detail-meta span {
            padding: 7px 10px;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 0.8rem;
        }
        .student-table-section {
            padding-top: 24px;
            border-top: 0;
        }
        .student-table-title {
            margin-bottom: 10px;
            color: #1e293b;
        }
        .student-table-title span {
            color: #64748b;
        }
        table {
            border-spacing: 0 6px;
        }
        th, td {
            padding: 13px 14px;
            font-size: 0.86rem;
        }
        thead th {
            color: #64748b;
            font-size: 0.72rem;
        }
        tbody tr {
            background: #f8fafc;
        }
        tbody tr:hover {
            background: #eff6ff;
        }
        .control-row {
            padding: 0 40px 28px;
        }

        @media print {
            @page {
                size: landscape;
                margin: 12mm;
            }
            html,
            body {
                background: #ffffff !important;
            }
            .container,
            .dashboard-layout,
            .dashboard-main {
                display: block;
                min-height: 0;
                padding: 0;
                margin: 0;
                border: 0;
                box-shadow: none;
            }
            .dashboard-sidebar,
            .dashboard-header,
            .header-actions,
            .control-row,
            .student-detail-meta,
            .print-exclude,
            .dashboard-stats {
                display: none !important;
            }
            body:not(.print-evaluations) .evaluation-print-section {
                display: none !important;
            }
            body:not(.print-evaluations) .attendance-print-section {
                display: block !important;
                visibility: visible !important;
            }
            body:not(.print-evaluations) .attendance-print-section .table-responsive {
                display: block !important;
                overflow: visible !important;
            }
            body:not(.print-evaluations) .attendance-print-section table {
                display: table !important;
            }
            body.print-evaluations .attendance-print-section {
                display: none !important;
            }
            body.print-evaluations .evaluation-print-section {
                display: block !important;
            }
            body.print-evaluations .student-detail-header {
                display: none !important;
            }
            body.print-evaluations .evaluation-print-heading {
                display: block !important;
                padding: 0 0 12px;
                margin-bottom: 12px;
                border-bottom: 1px solid #cbd5e1;
            }
            body.print-evaluations .evaluation-print-section .table-responsive {
                display: block !important;
                overflow: visible !important;
            }
            body.print-evaluations .evaluation-print-section table {
                display: table !important;
            }
            body.print-evaluations .evaluation-print-section th:last-child,
            body.print-evaluations .evaluation-print-section td:last-child {
                display: none !important;
            }
            .table-wrap {
                margin: 0;
                padding: 0;
                border: 0;
                border-radius: 0 !important;
                box-shadow: none;
                overflow: visible;
            }
            .student-table-section,
            table,
            tbody tr,
            th,
            td {
                border-radius: 0 !important;
            }
            .student-detail-header {
                display: block;
                padding: 0 0 12px;
                border-bottom: 1px solid #cbd5e1;
            }
            .student-detail-title h2 {
                margin: 0;
                color: #000000;
            }
            .student-detail-title p {
                margin-top: 6px;
                color: #475569;
            }
            .dashboard-tag {
                display: none;
            }
            .student-table-section {
                padding-top: 12px;
            }
            table {
                min-width: 0;
                border-spacing: 0;
            }
            th,
            td {
                padding: 8px 10px;
                border-bottom: 1px solid #cbd5e1;
                color: #000000 !important;
            }
            .attendance-absent,
            .badge {
                background: transparent !important;
                color: #000000 !important;
                padding: 0;
            }
        }

        @media (max-width: 980px) {
            .dashboard-header {
                grid-template-columns: 1fr;
            }
            .header-actions {
                align-items: flex-start;
            }
        }

        @media (max-width: 720px) {
            .dashboard-header {
                padding: 26px 20px 22px;
            }
            .dashboard-stats {
                grid-template-columns: 1fr;
            }
            .table-wrap {
                margin: 20px 16px 0;
                padding: 16px;
            }
            .control-row {
                padding: 0 20px 20px;
            }
        }

        /* Reference-inspired visual refresh: existing dashboard content only. */
        :root {
            --reference-purple: #dc2626;
            --reference-purple-dark: #991b1b;
            --reference-purple-soft: #fef2f2;
            --reference-ink: #3f1d1d;
            --reference-muted: #786d6d;
            --reference-line: #fee2e2;
            --reference-surface: #ffffff;
            --reference-page: #fff7f7;
        }

        html,
        body {
            background: var(--reference-page);
            color: var(--reference-ink);
        }

        body {
            background-image: none;
            font-family: "Book Antiqua", Georgia, serif;
        }

        .dashboard-layout {
            grid-template-columns: 248px minmax(0, 1fr);
            background: var(--reference-page);
            overflow: visible;
        }

        .dashboard-sidebar {
            align-self: start;
            position: sticky;
            top: 0;
            height: 100vh;
            max-height: 100vh;
            overflow-y: auto;
            background: var(--reference-purple);
            padding: 26px 16px;
            box-shadow: 8px 0 24px rgba(127, 29, 29, 0.12);
        }

        .sidebar-brand {
            padding: 4px 12px 22px;
            border-bottom-color: rgba(255, 255, 255, 0.22);
        }

        .sidebar-logo {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #ffffff;
            color: var(--reference-purple);
        }

        .sidebar-brand h2 {
            font-size: 1.16rem;
        }

        .sidebar-brand p,
        .sidebar-link-description {
            color: rgba(255, 255, 255, 0.76);
        }

        .sidebar-section-title {
            margin: 26px 12px 12px;
            color: rgba(255, 255, 255, 0.7);
        }

        .sidebar-nav {
            gap: 8px;
        }

        .sidebar-link {
            padding: 10px 12px;
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.86);
        }

        .sidebar-link:hover {
            background: #b91c1c;
            border-color: #dc2626;
            color: #ffffff;
        }

        .sidebar-link.active {
            background: #ffffff;
            border-color: #ffffff;
            color: var(--reference-purple-dark);
            box-shadow: 0 8px 18px rgba(127, 29, 29, 0.18);
        }

        .sidebar-link-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
        }

        .sidebar-link.active .sidebar-link-icon {
            background: var(--reference-purple-soft);
            color: var(--reference-purple);
        }

        .sidebar-link.active .sidebar-link-description {
            color: var(--reference-muted);
        }

        .sidebar-menu-trigger {
            color: rgba(255, 255, 255, 0.86);
        }

        .sidebar-submenu {
            background: rgba(47, 18, 91, 0.34);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .sidebar-submenu-link:hover,
        .sidebar-submenu-link.active {
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.22);
        }

        .sidebar-toggle-btn {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
        }

        .dashboard-main {
            background: var(--reference-page);
        }

        .dashboard-card {
            background: transparent;
        }

        .dashboard-header {
            grid-template-columns: 1fr;
            gap: 20px;
            padding: 34px 42px 26px;
            background: var(--reference-surface);
            border-bottom: 1px solid var(--reference-line);
        }

        .dashboard-title {
            align-items: center;
            text-align: center;
        }

        .dashboard-title h1 {
            color: var(--reference-purple);
            font-size: clamp(2rem, 3vw, 2.7rem);
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .dashboard-title p {
            color: var(--reference-muted);
        }

        .dashboard-stats {
            width: 100%;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 18px;
        }

        .stat-card {
            min-height: 116px;
            padding: 18px;
            border: 1px solid var(--reference-line);
            border-top: 4px solid var(--reference-purple);
            border-radius: 10px;
            background: var(--reference-surface);
            box-shadow: 0 8px 20px rgba(127, 29, 29, 0.07);
        }

        .stat-card:hover,
        .stat-card--students:hover,
        .stat-card--days:hover,
        .stat-card--locations:hover {
            transform: translateY(-2px);
            border-color: #fecaca;
            background: var(--reference-surface);
            box-shadow: 0 12px 24px rgba(127, 29, 29, 0.1);
            color: inherit;
        }

        .stat-card--students:hover .card-body,
        .stat-card--students:hover .material-symbols-outlined,
        .stat-card--students:hover .card-body h3,
        .stat-card--students:hover .card-body small,
        .stat-card--days:hover .card-body,
        .stat-card--days:hover .material-symbols-outlined,
        .stat-card--days:hover .card-body h3,
        .stat-card--days:hover .card-body small,
        .stat-card--locations:hover .card-body,
        .stat-card--locations:hover .material-symbols-outlined,
        .stat-card--locations:hover .card-body h3,
        .stat-card--locations:hover .card-body small {
            color: inherit !important;
        }

        .stat-card small {
            color: var(--reference-muted);
            font-size: 0.7rem;
        }

        .stat-card h3 {
            color: var(--reference-ink);
            font-size: 1.8rem;
        }

        .stat-card .material-symbols-outlined {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--reference-purple-soft);
            color: var(--reference-purple) !important;
            font-size: 1.6rem;
        }

        .stat-card--days .material-symbols-outlined,
        .stat-card--locations .material-symbols-outlined {
            background: var(--reference-purple-soft);
            color: var(--reference-purple) !important;
        }

        .header-actions {
            align-items: center;
        }

        .search-panel {
            justify-content: center;
        }

        .search-panel input {
            background: #ffffff;
            border-color: #fecaca;
            color: var(--reference-ink);
        }

        .dashboard-actions {
            justify-content: center;
        }

        .dashboard-actions .btn-secondary {
            border-color: #fecaca;
            background: #ffffff;
            color: var(--reference-purple-dark);
        }

        .dashboard-actions .btn-secondary:hover {
            background: var(--reference-purple-soft);
            color: var(--reference-purple);
        }

        .table-wrap {
            margin: 28px 42px 0;
            padding: 24px;
            border: 1px solid var(--reference-line);
            border-radius: 12px;
            background: var(--reference-surface);
            box-shadow: 0 10px 26px rgba(127, 29, 29, 0.06);
        }

        .student-card {
            min-height: 138px;
            padding: 20px;
            border: 1px solid #fecaca;
            border-radius: 10px;
            background: var(--reference-purple-soft);
            color: var(--reference-purple-dark);
            box-shadow: none;
        }

        .student-card:hover {
            border-color: var(--reference-purple);
            background: #fee2e2;
            box-shadow: 0 10px 22px rgba(127, 29, 29, 0.1);
        }

        .student-card strong,
        .student-card small {
            color: var(--reference-purple-dark);
        }

        .student-card .badge {
            background: #ffffff;
            color: var(--reference-purple);
        }

        table {
            border-spacing: 0 6px;
        }

        thead th {
            color: var(--reference-muted);
        }

        tbody tr {
            background: #fffafa;
        }

        tbody tr:hover {
            background: var(--reference-purple-soft);
        }

        .badge {
            background: var(--reference-purple-soft);
            color: var(--reference-purple-dark);
        }

        @media (max-width: 720px) {
            .dashboard-header {
                padding: 28px 20px 22px;
            }

            .dashboard-stats {
                grid-template-columns: 1fr;
            }

            .table-wrap {
                margin: 20px 16px 0;
            }
        }

        /* Final card contrast pass for the existing dashboard cards. */
        .stat-card,
        .stat-card .card-body,
        .stat-card .card-body small,
        .stat-card .card-body h3 {
            color: var(--reference-ink) !important;
        }

        .stat-card .card-body small {
            color: var(--reference-muted) !important;
        }

        .stat-card .material-symbols-outlined {
            color: var(--reference-purple) !important;
        }

        .student-card,
        .student-card .card-body,
        .student-card .card-title,
        .student-card .card-text,
        .student-card strong,
        .student-card small {
            color: var(--reference-purple-dark) !important;
        }

        .student-card .badge {
            color: var(--reference-purple) !important;
            background: #ffffff !important;
        }

        .strand-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .strand-card {
            align-self: start;
            overflow: hidden;
            border: 1px solid var(--reference-line);
            border-radius: 12px;
            background: #fffafa;
            box-shadow: 0 6px 18px rgba(127, 29, 29, 0.05);
        }

        .strand-card-summary {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 10px 12px;
            align-items: center;
            min-height: 108px;
            padding: 18px;
            cursor: pointer;
            list-style: none;
        }

        .strand-card-summary::-webkit-details-marker {
            display: none;
        }

        .strand-card-icon {
            grid-row: span 2;
            color: var(--reference-purple);
            font-size: 30px;
        }

        .strand-card-name {
            color: var(--reference-purple-dark);
            font-size: 0.9rem;
            font-weight: 700;
            line-height: 1.4;
        }

        .strand-card-count {
            grid-column: 2;
            color: var(--reference-muted);
            font-size: 0.85rem;
        }

        .strand-card-chevron {
            grid-column: 3;
            grid-row: 1 / span 2;
            color: var(--reference-muted);
            transition: transform 0.2s ease;
        }

        .strand-card[open] .strand-card-chevron {
            transform: rotate(180deg);
        }

        .strand-student-list {
            padding: 0 16px 14px;
            border-top: 1px solid var(--reference-line);
        }

        .strand-student {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 2px;
            border-bottom: 1px solid #f1e7e7;
            color: inherit;
            text-decoration: none;
        }

        .strand-student[hidden] {
            display: none;
        }

        a.strand-student:hover .strand-student-name {
            color: var(--reference-purple);
            text-decoration: underline;
        }

        a.strand-student:focus-visible {
            outline: 2px solid var(--reference-purple);
            outline-offset: 2px;
        }

        .strand-student:last-child {
            border-bottom: 0;
        }

        .strand-student-icon {
            color: var(--reference-purple);
            font-size: 20px;
        }

        .strand-student-name {
            flex: 1;
            min-width: 0;
            color: var(--reference-ink);
            font-weight: 600;
        }

        .strand-student-name a {
            color: inherit;
            text-decoration: none;
        }

        .strand-student-name a:hover {
            color: var(--reference-purple);
            text-decoration: underline;
        }

        .strand-student-records {
            color: var(--reference-muted);
            font-size: 0.76rem;
            text-align: right;
        }

        .strand-empty {
            margin: 0;
            padding-top: 14px;
            color: var(--reference-muted);
            font-size: 0.9rem;
        }

        @media (max-width: 1100px) {
            .strand-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 620px) {
            .strand-grid {
                grid-template-columns: 1fr;
            }

            .strand-student {
                flex-wrap: wrap;
            }

            .strand-student-records {
                width: 100%;
                padding-left: 30px;
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="dashboard-layout">
            <aside class="dashboard-sidebar">
                <div class="sidebar-brand">
                    <div class="sidebar-logo">i</div>
                    <div>
                        <h2>i-Tracker</h2>
                        <p>Attendance overview</p>
                    </div>
                </div>
                <div style="margin: 0 0 18px; color: rgba(255,255,255,.82); font-size: .88rem;">Coordinator access</div>
                <div class="sidebar-section-title">Navigation</div>
                <nav class="sidebar-nav">
                    <a href="dashboard.php?view=overview" class="sidebar-link <?php echo nav_item_class('overview'); ?>">
                        <span class="sidebar-link-icon material-symbols-outlined">dashboard</span>
                        <div class="sidebar-link-text">
                            <span class="sidebar-link-label">Overview</span>
                            <span class="sidebar-link-description">Summary and key metrics</span>
                        </div>
                    </a>
                    <a href="students.php" class="sidebar-link">
                        <span class="sidebar-link-icon material-symbols-outlined">school</span>
                        <div class="sidebar-link-text">
                            <span class="sidebar-link-label">Students</span>
                            <span class="sidebar-link-description">View student attendance</span>
                        </div>
                    </a>
                    <a href="reports.php" class="sidebar-link <?php echo nav_item_class('reports'); ?>">
                        <span class="sidebar-link-icon material-symbols-outlined">analytics</span>
                        <div class="sidebar-link-text">
                            <span class="sidebar-link-label">Reports</span>
                            <span class="sidebar-link-description">Attendance summaries and logs</span>
                        </div>
                    </a>
                    <div class="sidebar-menu-wrapper">
                        <button type="button" class="sidebar-link sidebar-menu-trigger" aria-expanded="false">
                            <span class="sidebar-link-icon material-symbols-outlined">business</span>
                            <div class="sidebar-link-text">
                                <span class="sidebar-link-label">Agencies & Assignments</span>
                                <span class="sidebar-link-description">Manage placements and supervisors</span>
                            </div>
                        </button>
                        <div class="sidebar-submenu">
                            <a href="coordinator_management.php?view=agency" class="sidebar-submenu-link">
                                <span class="sidebar-link-icon material-symbols-outlined">add_business</span>
                                <span class="sidebar-link-label">Add Agency</span>
                            </a>
                            <a href="coordinator_management.php?view=assign" class="sidebar-submenu-link">
                                <span class="sidebar-link-icon material-symbols-outlined">person_add</span>
                                <span class="sidebar-link-label">Assign Supervisor to Student</span>
                            </a>
                            <a href="coordinator_management.php?view=assignment" class="sidebar-submenu-link">
                                <span class="sidebar-link-icon material-symbols-outlined">assignment</span>
                                <span class="sidebar-link-label">Current Assignments</span>
                            </a>
                        </div>
                    </div>
                    <a href="coordinator_panel.php" class="sidebar-link">
                        <span class="sidebar-link-icon material-symbols-outlined">fact_check</span>
                        <div class="sidebar-link-text">
                            <span class="sidebar-link-label">Student Approval Requests</span>
                            <span class="sidebar-link-description">Approve or reject student access</span>
                        </div>
                    </a>
                    <a href="logout.php" class="sidebar-link sidebar-logout" onclick="return confirm('Are you sure you want to log out?');">
                        <span class="sidebar-link-icon material-symbols-outlined">logout</span>
                        <div class="sidebar-link-text">
                            <span class="sidebar-link-label">Sign out</span>
                            <span class="sidebar-link-description">End coordinator session</span>
                        </div>
                    </a>
                </nav>
            </aside>
            <main class="dashboard-main">
                <div class="dashboard-card">
                    <div class="dashboard-header py-4 px-4">
                <div class="dashboard-title">
                    <h1>Student Attendance Dashboard</h1>
                    <?php if (!$student_exists): ?>
                        <p>Choose a strand to browse its registered students. Students with attendance records can be opened for details.</p>
                    <?php else: ?>
                        <p>Student Full Name: <strong><?php echo htmlspecialchars($selected_student_name); ?></strong></p>
                    <?php endif; ?>
                    <div class="dashboard-stats">
                        <div class="card stat-card stat-card--students shadow-sm border-0">
                            <div class="card-body d-flex align-items-center gap-3">
                                <span class="material-symbols-outlined fs-1 text-primary">groups</span>
                                <div>
                                    <small class="text-uppercase text-muted">Total Students</small>
                                    <h3 class="mb-0 fw-bold"><?php echo $total_students; ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="card stat-card stat-card--days shadow-sm border-0">
                            <div class="card-body d-flex align-items-center gap-3">
                                <span class="material-symbols-outlined fs-1 text-success">calendar_month</span>
                                <div>
                                    <small class="text-uppercase text-muted">Tracked Days</small>
                                    <h3 class="mb-0 fw-bold"><?php echo $total_tracked_days; ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="card stat-card stat-card--locations shadow-sm border-0">
                            <div class="card-body d-flex align-items-center gap-3">
                                <span class="material-symbols-outlined fs-1 text-warning">location_on</span>
                                <div>
                                    <small class="text-uppercase text-muted">Locations Logged</small>
                                    <h3 class="mb-0 fw-bold"><?php echo $total_locations; ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <?php if (!$student_exists): ?>
                        <div class="search-panel">
                            <input type="search" id="dashboardSearch" placeholder="Search by student name">
                        </div>
                    <?php endif; ?>
                    <div class="dashboard-actions">
                        <?php if ($student_exists): ?>
                            <a href="dashboard.php" class="btn btn-secondary">Back to Student List</a>
                        <?php endif; ?>
                        <a href="coordinator_panel.php" class="btn btn-secondary">Student Approval Requests</a>
                        <a href="backup_project.php" class="btn btn-secondary" onclick="return confirm('Back up the project files now?');">Back Up Project Files</a>
                        <a href="index.php" class="btn btn-secondary">Back to Home</a>
                        <a href="logout.php" class="btn btn-secondary" onclick="return confirm('Are you sure you want to log out?');">Sign out</a>
                    </div>
                </div>
            </div>
            <?php if (!$student_exists): ?>
            <div class="table-wrap">
                <div class="strand-grid">
                    <?php foreach ($strand_roster as $strand_name => $strand_students): ?>
                        <details class="strand-card" data-strand-card>
                            <summary class="strand-card-summary">
                                <span class="material-symbols-outlined strand-card-icon" aria-hidden="true">school</span>
                                <span class="strand-card-name"><?php echo htmlspecialchars($strand_name, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="strand-card-count"><?php echo count($strand_students); ?> student<?php echo count($strand_students) === 1 ? '' : 's'; ?></span>
                                <span class="material-symbols-outlined strand-card-chevron" aria-hidden="true">expand_more</span>
                            </summary>
                            <div class="strand-student-list">
                                <?php if (empty($strand_students)): ?>
                                    <p class="strand-empty">No students registered under this strand yet.</p>
                                <?php else: ?>
                                    <?php foreach ($strand_students as $roster_student_id => $roster_student): ?>
                                        <?php $attendance_days = count($student_groups[$roster_student_id] ?? []); ?>
                                        <a class="strand-student" href="dashboard.php?student_id=<?php echo rawurlencode((string)$roster_student_id); ?>" data-student-name="<?php echo htmlspecialchars($roster_student['student_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <span class="strand-student-icon material-symbols-outlined" aria-hidden="true">person</span>
                                            <span class="strand-student-name">
                                                <?php echo htmlspecialchars($roster_student['student_name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                            <span class="strand-student-records"><?php echo $attendance_days > 0 ? $attendance_days . ' attendance day' . ($attendance_days === 1 ? '' : 's') : 'No attendance yet'; ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="table-wrap table-card">
                <div class="student-detail-header">
                    <div class="student-detail-title">
                        <div class="dashboard-tag">Student Detail</div>
                        <h2>Student Full Name: <?php echo htmlspecialchars($selected_student_name); ?></h2>
                        <p><?php echo count($selected_rows); ?> attendance record<?php echo count($selected_rows) === 1 ? '' : 's'; ?> found</p>
                    </div>
                    <div class="student-detail-meta">
                        <span><?php echo count($selected_locations); ?> location<?php echo count($selected_locations) === 1 ? '' : 's'; ?> captured</span>
                        <span><?php echo $selected_rows ? htmlspecialchars(map_strand_label($selected_rows[0]['strand'])) : '-'; ?></span>
                        <?php echo all_locations_link($selected_rows); ?>
                        <button type="button" class="btn btn-secondary print-exclude" onclick="printAttendance();">Print Attendance</button>
                    </div>
                </div>
                <div class="student-table-section attendance-print-section">
                    <div class="student-table-title">
                        <span>Attendance Details</span>
                        <strong><?php echo htmlspecialchars($selected_student_name); ?></strong>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-borderless table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Day</th>
                                <th>Strand</th>
                                <th>AM Sign In</th>
                                <th>AM Sign Out</th>
                                <th>PM Sign In</th>
                                <th>PM Sign Out</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selected_rows as $index => $s): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($s['day']); ?></td>
                                    <td><?php echo !empty($s['strand']) ? htmlspecialchars(map_strand_label($s['strand'])) : '-'; ?></td>
                                    <td><?php echo attendance_event_cell($s['am_in'], 'bg-primary', $s['student_id'], 'AM Time In', $s['date']); ?></td>
                                    <td><?php echo attendance_event_cell($s['am_out'], 'bg-info text-dark', $s['student_id'], 'AM Time Out', $s['date']); ?></td>
                                    <td><?php echo attendance_event_cell($s['pm_in'], 'bg-success', $s['student_id'], 'PM Time In', $s['date']); ?></td>
                                    <td><?php echo attendance_event_cell($s['pm_out'], 'bg-warning text-dark', $s['student_id'], 'PM Time Out', $s['date']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="student-table-section evaluation-print-section">
                    <div class="evaluation-print-heading">
                        <h2>Performance Evaluations</h2>
                        <p>Student Full Name: <?php echo htmlspecialchars($selected_student_name); ?></p>
                    </div>
                    <div class="student-table-title">
                        <span>Performance Evaluations</span>
                        <strong><?php echo count($selected_evaluations); ?> evaluation<?php echo count($selected_evaluations) === 1 ? '' : 's'; ?></strong>
                    </div>
                    <?php if (empty($selected_evaluations)): ?>
                        <p class="text-muted">No performance evaluation has been submitted for this student yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-borderless table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Evaluator</th>
                                        <th>Overall Grade</th>
                                        <th>Weighted Avg</th>
                                        <th>Equivalent Grade</th>
                                        <th>Comments</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($selected_evaluations as $evaluation): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($evaluation['evaluation_date']); ?></td>
                                            <td><?php echo htmlspecialchars($evaluation['evaluator_name']); ?></td>
                                            <td><span class="badge bg-primary"><?php echo number_format((float)$evaluation['overall_performance_rating'], 1); ?>/5.0</span></td>
                                            <td><?php echo number_format((float)$evaluation['weighted_average'], 2); ?></td>
                                            <td><?php echo htmlspecialchars((string)$evaluation['equivalent_grade']); ?></td>
                                            <td><?php echo htmlspecialchars($evaluation['comments'] ?: 'No comments'); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary view-evaluation-btn print-exclude" data-evaluation="<?php echo htmlspecialchars(json_encode($evaluation), ENT_QUOTES, 'UTF-8'); ?>">View</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
                </div>
                <button id="hideMap" style="display: none; margin: 10px 0;">Hide Map</button>
                <div class="control-row">
                    <label><input type="checkbox" id="autoRefreshToggle"> Auto-refresh every <input type="number" id="refreshInterval" value="30" min="5"> seconds</label>
                </div>
            </main>
        </div>
    </div>

    <div id="evaluationModal" class="evaluation-view-modal" aria-hidden="true" role="dialog" aria-labelledby="evaluationModalTitle">
        <div class="evaluation-view-modal-backdrop" data-close-evaluation-modal="true"></div>
        <div class="evaluation-view-modal-dialog" role="document">
            <div class="evaluation-view-modal-content">
                <div class="evaluation-view-modal-header">
                    <h5 id="evaluationModalTitle">Performance Appraisal Details</h5>
                    <button type="button" class="btn-close" aria-label="Close" data-close-evaluation-modal="true">×</button>
                </div>
                <div class="evaluation-view-modal-body" id="evaluationModalBody"></div>
                <div class="evaluation-view-modal-footer">
                    <button type="button" class="btn btn-primary" id="printEvaluationDetails">Print Evaluation</button>
                    <button type="button" class="btn btn-secondary" data-close-evaluation-modal="true">Close</button>
                </div>
            </div>
        </div>
    </div>
</body>
<script>
var rubricSections = <?php echo json_encode($rubric_sections, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function safeText(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    return value;
}

function normalizeSignature(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    var text = String(value).trim();
    if (text === '') {
        return '';
    }

    if (text.indexOf('data:image/') === 0 || text.indexOf('http://') === 0 || text.indexOf('https://') === 0) {
        return text;
    }

    if (text.indexOf('base64,') !== -1) {
        var base64Index = text.indexOf('base64,');
        var mimeType = text.slice(0, base64Index).replace(/\s+$/, '');
        if (mimeType && mimeType.indexOf('data:') === 0) {
            return text;
        }
    }

    return text;
}

function formatDecimalValue(value, digits) {
    if (value === null || value === undefined || value === '' || value === 'NA') {
        return '—';
    }
    var numberValue = Number(value);
    if (Number.isNaN(numberValue)) {
        return String(value);
    }
    return numberValue.toFixed(digits);
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
    var parts = [hours + ' hour' + (hours === 1 ? '' : 's')];

    if (minutes > 0 || hours === 0) {
        parts.push(minutes + ' minute' + (minutes === 1 ? '' : 's'));
    }

    return parts.join(' ');
}

function renderEvaluationModalContent(evaluation) {
    var modalBody = document.getElementById('evaluationModalBody');
    if (!modalBody) return;

    var rubricData = {};
    try {
        rubricData = typeof evaluation.rubric_scores === 'string' ? JSON.parse(evaluation.rubric_scores) : (evaluation.rubric_scores || {});
    } catch (error) {
        rubricData = {};
    }

    var studentInfo = <?php echo json_encode($student_profile_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>[evaluation.student_id] || {};
    var studentName = studentInfo.student_name || evaluation.student_id || 'Student';
    var assignedSupervisor = studentInfo.supervisor_name || evaluation.evaluator_name || evaluation.immersion_supervisor || 'Supervisor';
    var supervisorPosition = evaluation.supervisor_position && String(evaluation.supervisor_position).toLowerCase() !== 'student'
        ? evaluation.supervisor_position
        : 'Work Immersion Supervisor';
    var totalHoursRendered = evaluation.total_hours_rendered === null || evaluation.total_hours_rendered === undefined || evaluation.total_hours_rendered === ''
        ? '—'
        : formatRenderedHours(evaluation.total_hours_rendered);
    var studentSignature = normalizeSignature(evaluation.student_signature || evaluation.studentSignature || '');
    var supervisorSignature = normalizeSignature(evaluation.supervisor_signature || evaluation.supervisorSignature || '');

    var rubricMarkup = Object.keys(rubricSections).map(function (sectionKey) {
        var section = rubricSections[sectionKey] || { title: sectionKey, items: [] };
        var rows = (section.items || []).map(function (item, itemIndex) {
            var score = rubricData[sectionKey] && rubricData[sectionKey][itemIndex];
            if (score === undefined || score === null || score === '') {
                score = '—';
            } else if (score === 'NA') {
                score = 'NA';
            }
            return '<div class="evaluation-view-rubric-row"><span>' + (itemIndex + 1) + '. ' + item + '</span><div class="score-pill">' + score + '</div></div>';
        }).join('');
        return '<div class="evaluation-view-section"><h4>' + section.title + '</h4>' + rows + '</div>';
    }).join('');

    var signatureMarkup = '<div class="evaluation-signature-grid">' +
        '<div class="signature-preview"><label>Student Signature</label>' +
        (studentSignature ? '<img src="' + studentSignature + '" alt="Student signature">' : '') +
        '</div>' +
        '<div class="signature-preview"><label>Supervisor Signature</label>' +
        (supervisorSignature ? '<img src="' + supervisorSignature + '" alt="Supervisor signature">' : '') +
        '</div>' +
        '</div>';

    modalBody.innerHTML = '<div class="evaluation-view-grid">' +
        '<div class="field"><label>Student</label><input type="text" value="' + safeText(studentName) + '" readonly></div>' +
        '<div class="field"><label>Assigned Supervisor</label><input type="text" value="' + safeText(assignedSupervisor) + '" readonly></div>' +
        '<div class="field"><label>Evaluation Date</label><input type="text" value="' + safeText(evaluation.evaluation_date) + '" readonly></div>' +
        '<div class="field"><label>Partner Institution</label><input type="text" value="' + safeText(studentInfo.partner_institution || evaluation.partner_institution) + '" readonly></div>' +
        '<div class="field"><label>Strand</label><input type="text" value="' + safeText(studentInfo.strand || evaluation.strand) + '" readonly></div>' +
        '<div class="field"><label>Address</label><input type="text" value="' + safeText(studentInfo.address || evaluation.appraisal_address) + '" readonly></div>' +
        '<div class="field"><label>Contact No.</label><input type="text" value="' + safeText(studentInfo.phone || evaluation.contact_number) + '" readonly></div>' +
        '<div class="field"><label>Work Immersion Supervisor</label><input type="text" value="' + safeText(assignedSupervisor) + '" readonly></div>' +
        '<div class="field"><label>Supervisor Position</label><input type="text" value="' + safeText(supervisorPosition) + '" readonly></div>' +
        '<div class="field"><label>Training Period Start</label><input type="text" value="' + safeText(evaluation.training_start_date) + '" readonly></div>' +
        '<div class="field"><label>Training Period End</label><input type="text" value="' + safeText(evaluation.training_end_date) + '" readonly></div>' +
        '<div class="field"><label>Total Hours Rendered</label><input type="text" value="' + safeText(totalHoursRendered) + '" readonly></div>' +
        '<div class="field"><label>Overall Rating</label><input type="text" value="' + safeText(evaluation.overall_performance_rating) + '" readonly></div>' +
        '<div class="field"><label>Weighted Average</label><input type="text" value="' + safeText(formatDecimalValue(evaluation.weighted_average, 2)) + '" readonly></div>' +
        '<div class="field"><label>Equivalent Grade</label><input type="text" value="' + safeText(evaluation.equivalent_grade) + '" readonly></div>' +
        '</div>' +
        '<div class="transmutation-output" aria-label="Transmutation output">' +
        '<div class="cell label">Computed Weighted Average</div>' +
        '<div class="cell value">' + safeText(evaluation.weighted_average || '0.00') + '</div>' +
        '<div class="cell label">Equivalent Grade</div>' +
        '<div class="cell value">' + safeText(evaluation.equivalent_grade || '0') + '</div>' +
        '</div>' +
        rubricMarkup +
        signatureMarkup +
        '<div class="field" style="margin-top: 18px;">' +
        '<label style="display:block; margin-bottom:8px; color:#334155; font-size:0.82rem; font-weight:700; letter-spacing:0.04em; text-transform:uppercase;">Comments</label>' +
        '<div style="width:100%; min-height:110px; box-sizing:border-box; padding:12px 14px; border:1px solid #cbd5e1; border-radius:10px; background:#f8fafc; color:#0f172a; white-space:pre-wrap; line-height:1.6;">' + safeText(evaluation.comments || 'No comments') + '</div>' +
        '</div>';
}

function openEvaluationModal(evaluation) {
    var modal = document.getElementById('evaluationModal');
    if (!modal) return;

    renderEvaluationModalContent(evaluation);
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
}

function closeEvaluationModal() {
    var modal = document.getElementById('evaluationModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}

function printBrandingStyles() {
    return '.print-branding{display:flex;flex-direction:row;align-items:center;width:100%;margin-bottom:14px}.print-branding img{display:block;height:80px;object-fit:contain}.print-branding__marabut{width:200px}';
}

function printBrandingMarkup() {
    var marabutLogoUrl = new URL('img/marabut_logo.WEBP', window.location.href).href;
    return '<div class="print-branding"><img class="print-branding__marabut" src="' + marabutLogoUrl + '" alt="Marabut National High School logo"></div>';
}

function printEvaluationDetails() {
    var modal = document.getElementById('evaluationModal');
    var modalBody = document.getElementById('evaluationModalBody');
    if (!modal || !modalBody || !modal.classList.contains('show')) return;

    var printWindow = window.open('', '_blank', 'width=1000,height=900');
    if (!printWindow) {
        window.alert('Please allow pop-ups to print the evaluation details.');
        return;
    }

    var printableBody = modalBody.cloneNode(true);
    printableBody.querySelectorAll('.print-exclude').forEach(function (element) {
        element.remove();
    });

    printWindow.document.write('<!DOCTYPE html><html><head><title>Performance Evaluation</title>');
    printWindow.document.write('<style>');
    printWindow.document.write(printBrandingStyles());
    printWindow.document.write('@page{size:portrait;margin:12mm}');
    printWindow.document.write('body{font-family:Arial,sans-serif;color:#0f172a;margin:0;font-size:12px}');
    printWindow.document.write('h1{font-size:22px;margin:0 0 14px;border-bottom:1px solid #cbd5e1;padding-bottom:10px}');
    printWindow.document.write('.evaluation-view-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px;margin-bottom:16px}');
    printWindow.document.write('.field{margin:0 0 8px}.field label,.evaluation-view-section h4{display:block;font-weight:700;margin-bottom:5px;color:#334155}');
    printWindow.document.write('.field input,.field textarea{width:100%;box-sizing:border-box;padding:7px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;color:#0f172a;font:inherit}');
    printWindow.document.write('.transmutation-output{display:grid;grid-template-columns:1fr 1fr;border:1px solid #cbd5e1;margin:14px 0;padding:8px}.transmutation-output .cell{padding:5px}.transmutation-output .label{font-weight:700}.transmutation-output .value{text-align:right}');
    printWindow.document.write('.evaluation-view-section{margin-top:14px;border:1px solid #cbd5e1;padding:10px;break-inside:avoid}.evaluation-view-section h4{font-size:14px;margin:0 0 8px;color:#1d4ed8}');
    printWindow.document.write('.evaluation-view-rubric-row{display:grid;grid-template-columns:minmax(0,1fr) 54px;gap:10px;align-items:center;padding:6px 8px;border:1px solid #e2e8f0;margin-bottom:5px}.evaluation-view-rubric-row:last-child{margin-bottom:0}.score-pill{display:inline-flex;justify-content:center;padding:4px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;font-weight:700}');
    printWindow.document.write('.evaluation-signature-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:18px 0}.signature-preview{border:none;padding:8px}.signature-preview label{display:block;font-weight:700;margin-bottom:8px}.signature-preview img{display:block;width:100%;max-height:130px;object-fit:contain}.signature-empty{padding:40px 8px;text-align:center;color:#64748b;border:1px dashed #cbd5e1}.field>div{white-space:pre-wrap;line-height:1.6}');
    printWindow.document.write('</style></head><body>');
    printWindow.document.write(printBrandingMarkup());
    printWindow.document.write('<h1>Performance Evaluation</h1>');
    printWindow.document.write(printableBody.innerHTML);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();

    var images = Array.prototype.slice.call(printWindow.document.images);
    var printWhenReady = function () {
        printWindow.print();
        printWindow.close();
    };
    if (images.length === 0) {
        printWhenReady();
        return;
    }
    var pendingImages = images.length;
    images.forEach(function (image) {
        var imageReady = function () {
            pendingImages -= 1;
            if (pendingImages === 0) printWhenReady();
        };
        image.addEventListener('load', imageReady, { once: true });
        image.addEventListener('error', imageReady, { once: true });
        if (image.complete) imageReady();
    });
}

document.addEventListener('click', function(event) {
    var viewButton = event.target.closest('.view-evaluation-btn');
    if (viewButton) {
        try {
            openEvaluationModal(JSON.parse(viewButton.dataset.evaluation));
        } catch (error) {
            console.error('Unable to parse evaluation data.', error);
        }
        return;
    }

    if (event.target.closest('[data-close-evaluation-modal]')) {
        closeEvaluationModal();
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEvaluationModal();
    }
});

var printEvaluationDetailsButton = document.getElementById('printEvaluationDetails');
if (printEvaluationDetailsButton) {
    printEvaluationDetailsButton.addEventListener('click', printEvaluationDetails);
}

function printSection(section) {
    document.body.classList.toggle('print-evaluations', section === 'evaluations');
    window.requestAnimationFrame(function() {
        window.print();
    });
}

function printAttendance() {
    var section = document.querySelector('.attendance-print-section');
    if (!section) return;

    var printWindow = window.open('', '_blank', 'width=1200,height=800');
    if (!printWindow) {
        document.body.classList.remove('print-evaluations');
        window.print();
        return;
    }

    var table = section.querySelector('.table-responsive');
    var recordCount = section.querySelectorAll('tbody tr').length;
    printWindow.document.write('<!DOCTYPE html><html><head><title>Student Attendance</title>');
    printWindow.document.write('<style>');
    printWindow.document.write(printBrandingStyles());
    printWindow.document.write('@page{size:landscape;margin:12mm}body{font-family:Arial,sans-serif;color:#000;margin:0}');
    printWindow.document.write('h2{margin:0 0 8px;font-size:20px}p{margin:0 0 14px;color:#475569}');
    printWindow.document.write('.print-exclude{display:none!important}.table-responsive{display:block;overflow:visible}');
    printWindow.document.write('table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{padding:8px 7px;border:1px solid #cbd5e1;text-align:left;vertical-align:top;font-size:11px;overflow:hidden}th{font-weight:700;background:#f8fafc}.badge{background:transparent;padding:0}.attendance-absent{background:transparent;padding:0}');
    printWindow.document.write('th:nth-child(1),td:nth-child(1){width:4%}th:nth-child(2),td:nth-child(2){width:11%}th:nth-child(3),td:nth-child(3){width:11%}th:nth-child(4),td:nth-child(4){width:32%;white-space:nowrap;word-break:normal}th:nth-child(n+5),td:nth-child(n+5){width:10.5%;white-space:nowrap}');
    printWindow.document.write('</style></head><body>');
    printWindow.document.write(printBrandingMarkup());
    printWindow.document.write('<h2>Student Attendance</h2>');
    printWindow.document.write('<p>Student Full Name: ' + <?php echo json_encode($selected_student_id ?? ''); ?> + '</p>');
    printWindow.document.write('<p><strong>' + recordCount + ' attendance record' + (recordCount === 1 ? '' : 's') + ' found</strong></p>');
    if (table) {
        printWindow.document.write(table.outerHTML);
    }
    printWindow.document.write('</body></html>');
    printWindow.onload = function() {
        printWindow.print();
        printWindow.close();
    };
    printWindow.document.close();
    printWindow.focus();
}

function printEvaluations() {
    var section = document.querySelector('.evaluation-print-section');
    if (!section) return;

    var printWindow = window.open('', '_blank', 'width=1200,height=800');
    if (!printWindow) {
        document.body.classList.add('print-evaluations');
        window.print();
        return;
    }

    printWindow.document.write('<!DOCTYPE html><html><head><title>Performance Evaluations</title>');
    printWindow.document.write('<style>');
    printWindow.document.write(printBrandingStyles());
    printWindow.document.write('@page{size:landscape;margin:12mm}body{font-family:Arial,sans-serif;color:#000;margin:0}');
    printWindow.document.write('h2{margin:0 0 8px;font-size:20px}p{margin:0 0 14px;color:#475569}');
    printWindow.document.write('.student-table-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;font-weight:700}');
    printWindow.document.write('.print-exclude{display:none!important}th:last-child,td:last-child{display:none!important}th:last-child,td:last-child{display:none!important}.table-responsive{display:block;overflow:visible}');
    printWindow.document.write('table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{padding:8px 10px;border:1px solid #cbd5e1;text-align:left;vertical-align:top;font-size:12px}th{font-weight:700;background:#f8fafc}.badge{background:transparent;padding:0}');
    printWindow.document.write('</style></head><body>');
    printWindow.document.write(printBrandingMarkup());
    printWindow.document.write('<h2>Performance Evaluations</h2>');
    printWindow.document.write('<p>Student Full Name: ' + <?php echo json_encode($selected_student_id ?? ''); ?> + '</p>');
    var evaluationCount = section.querySelector('.student-table-title strong');
    var evaluationTable = section.querySelector('.table-responsive');
    if (evaluationCount) {
        printWindow.document.write('<p class="evaluation-count"><strong>' + evaluationCount.textContent + '</strong></p>');
    }
    if (evaluationTable) {
        printWindow.document.write(evaluationTable.outerHTML);
    } else {
        printWindow.document.write('<p>No performance evaluation has been submitted for this student yet.</p>');
    }
    printWindow.document.write('</body></html>');
    printWindow.onload = function() {
        printWindow.print();
        printWindow.close();
    };
    printWindow.document.close();
    printWindow.focus();
}

window.addEventListener('afterprint', function() {
    document.body.classList.remove('print-evaluations');
});

var managementMenu = document.querySelector('.sidebar-menu-trigger');
if (managementMenu) {
    managementMenu.addEventListener('click', function() {
        var wrapper = this.closest('.sidebar-menu-wrapper');
        if (!wrapper) return;
        var isOpen = wrapper.classList.toggle('expanded');
        this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
}

var currentPageMenu = document.querySelector('.sidebar-menu-wrapper');
if (currentPageMenu && window.location.pathname.toLowerCase().indexOf('coordinator_management.php') !== -1) {
    currentPageMenu.classList.add('expanded');
    var currentTrigger = currentPageMenu.querySelector('.sidebar-menu-trigger');
    if (currentTrigger) {
        currentTrigger.setAttribute('aria-expanded', 'true');
    }
}

// Auto-refresh logic (toggle + interval stored in localStorage)
var refreshIntervalInput = document.getElementById('refreshInterval');
var autoRefreshToggle = document.getElementById('autoRefreshToggle');
var refreshTimerId = null;

function startAutoRefresh(){
    var secs = parseInt(refreshIntervalInput.value, 10) || 30;
    refreshTimerId = setInterval(function(){ location.reload(); }, secs * 1000);
    localStorage.setItem('autoRefreshEnabled', '1');
    localStorage.setItem('autoRefreshSecs', secs);
}

function stopAutoRefresh(){
    if (refreshTimerId) { clearInterval(refreshTimerId); refreshTimerId = null; }
    localStorage.setItem('autoRefreshEnabled', '0');
}

autoRefreshToggle.addEventListener('change', function(){
    if (this.checked) startAutoRefresh(); else stopAutoRefresh();
});

refreshIntervalInput.addEventListener('change', function(){
    if (autoRefreshToggle.checked){
        stopAutoRefresh();
        startAutoRefresh();
    }
});

var dashboardSearch = document.getElementById('dashboardSearch');
if (dashboardSearch) {
    dashboardSearch.addEventListener('input', function() {
        var query = this.value.toLowerCase();
        var cards = document.querySelectorAll('[data-strand-card]');
        cards.forEach(function(card) {
            var students = card.querySelectorAll('[data-student-name]');
            var matchCount = 0;
            students.forEach(function(student) {
                var matches = student.dataset.studentName.toLowerCase().indexOf(query) !== -1;
                student.hidden = !matches;
                if (matches) matchCount++;
            });
            card.hidden = query !== '' && matchCount === 0;
            if (query !== '' && matchCount > 0) card.open = true;
            if (query === '') card.open = false;
        });
    });
}

(function(){
    var enabled = localStorage.getItem('autoRefreshEnabled') === '1';
    var secs = parseInt(localStorage.getItem('autoRefreshSecs'), 10) || 30;
    refreshIntervalInput.value = secs;
    autoRefreshToggle.checked = enabled;
    if (enabled) startAutoRefresh();
})();
</script>
</html>
