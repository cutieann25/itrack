<?php
require_once 'auth.php';
$user = require_login(['coordinator']);
$current_view = $_GET['view'] ?? 'agency';
$valid_views = ['agency', 'assign', 'assignment', 'students'];
if (!in_array($current_view, $valid_views, true)) {
    $current_view = 'agency';
}
$conn = db_connection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_agency') {
        $name = trim($_POST['agency_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact = trim($_POST['contact_info'] ?? '');
        if ($name === '') {
            $error = 'Agency name is required.';
        } else {
            $stmt = $conn->prepare('INSERT INTO agencies (agency_name, address, contact_info) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $name, $address, $contact);
            if ($stmt->execute()) {
                $message = 'Agency created.';
            } else {
                $error = 'Agency could not be created. It may already exist.';
            }
        }
    } elseif ($action === 'update_agency') {
        $id = (int)($_POST['agency_id'] ?? 0);
        $name = trim($_POST['agency_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact = trim($_POST['contact_info'] ?? '');
        if ($id <= 0 || $name === '') {
            $error = 'Agency name is required.';
        } else {
            $stmt = $conn->prepare('UPDATE agencies SET agency_name = ?, address = ?, contact_info = ? WHERE agency_id = ?');
            $stmt->bind_param('sssi', $name, $address, $contact, $id);
            $message = $stmt->execute() ? 'Agency updated.' : 'Agency could not be updated.';
        }
    } elseif ($action === 'delete_agency') {
        $id = (int)($_POST['agency_id'] ?? 0);
        $office_check = $conn->prepare('SELECT COUNT(*) AS office_count FROM offices WHERE agency_id = ?');
        $office_check->bind_param('i', $id);
        $office_check->execute();
        $office_count = (int)$office_check->get_result()->fetch_assoc()['office_count'];
        if ($office_count > 0) {
            $error = 'This agency cannot be deleted because it still has ' . $office_count . ' office or department record(s). Delete or move those offices first.';
        } else {
            $stmt = $conn->prepare('DELETE FROM agencies WHERE agency_id = ?');
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $message = 'Agency deleted.';
            } else {
                $error = 'Agency could not be deleted.';
            }
        }
    } elseif ($action === 'assign_student') {
        $student_id = trim($_POST['student_id'] ?? '');
        $supervisor_id = (int)($_POST['supervisor_id'] ?? 0);
        $agency_id = (int)($_POST['assignment_agency_id'] ?? 0);

        if ($student_id === '' || $supervisor_id <= 0) {
            $error = 'Student and supervisor are required.';
        } else {
            $stmt = $conn->prepare('INSERT INTO student_supervisor_assignments (student_id, supervisor_id, agency_id, assigned_by) VALUES (?, ?, NULLIF(?, 0), ?) ON DUPLICATE KEY UPDATE supervisor_id = VALUES(supervisor_id), agency_id = VALUES(agency_id), assigned_by = VALUES(assigned_by)');
            $stmt->bind_param('siis', $student_id, $supervisor_id, $agency_id, $user['id']);
            $message = $stmt->execute() ? 'Student assignment saved.' : 'Student assignment could not be saved.';
        }
    } elseif ($action === 'remove_assignment') {
        $id = (int)($_POST['assignment_id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM student_supervisor_assignments WHERE assignment_id = ?');
        $stmt->bind_param('i', $id);
        $message = $stmt->execute() ? 'Student assignment removed.' : 'Assignment could not be removed.';
    }
}

$agencies = [];
$result = $conn->query('SELECT * FROM agencies ORDER BY agency_name');
while ($row = $result->fetch_assoc()) {
    $agencies[] = $row;
}

$supervisors = [];
$result = $conn->query("SELECT id, display_name, username FROM users WHERE role = 'supervisor' AND is_active = 1 ORDER BY display_name");
while ($row = $result->fetch_assoc()) {
    $supervisors[] = $row;
}

$students = [];
$result = $conn->query('SELECT student_id FROM attendance_logs GROUP BY student_id ORDER BY student_id');
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

$assignments = [];
$result = $conn->query('SELECT x.*, u.display_name AS supervisor_name, a.agency_name FROM student_supervisor_assignments x JOIN users u ON u.id = x.supervisor_id LEFT JOIN agencies a ON a.agency_id = x.agency_id ORDER BY x.student_id');
while ($row = $result->fetch_assoc()) {
    $assignments[] = $row;
}

$student_assignments = [];
foreach ($assignments as $assignment) {
    $student_assignments[$assignment['student_id']] = $assignment;
}
$editing_student_id = trim($_GET['edit_student'] ?? '');
$editing_assignment = $student_assignments[$editing_student_id] ?? null;

$agency_students = [];
foreach ($assignments as $assignment) {
    $agency_id = isset($assignment['agency_id']) ? (int)$assignment['agency_id'] : 0;
    if ($agency_id <= 0) {
        continue;
    }

    $agency_students[$agency_id][] = [
        'student_id' => $assignment['student_id'],
        'supervisor_name' => $assignment['supervisor_name'] ?? '—'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordinator Management</title>
    <link rel="stylesheet" href="coordinator_sidebar.css">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet" />
    <style>
        :root {
            --bg: #f1f5f9;
            --sidebar: #0f172a;
            --sidebar-soft: #1e293b;
            --panel: #ffffff;
            --panel-alt: #f8fafc;
            --line: #e2e8f0;
            --text: #0f172a;
            --muted: #64748b;
            --primary: #2563eb;
            --primary-strong: #1d4ed8;
            --success: #166534;
            --danger: #b91c1c;
            --warning: #b45309;
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            font-family: "Book Antiqua", Georgia, serif;
            background: var(--bg);
            color: var(--text);
        }

        .management-shell {
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            min-height: 100vh;
        }

        .management-sidebar {
            background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
            color: #f8fafc;
            padding: 24px 18px;
            border-right: 1px solid rgba(148, 163, 184, 0.2);
            position: sticky;
            top: 0;
            height: 100vh;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 10px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        .sidebar-logo {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, #dc2626 100%);
            display: grid;
            place-items: center;
            font-weight: 700;
            color: #fff;
        }

        .sidebar-brand h2 {
            margin: 0;
            font-size: 1.1rem;
        }

        .sidebar-brand p {
            margin: 4px 0 0;
            font-size: 0.72rem;
            color: #cbd5e1;
        }

        .sidebar-nav {
            margin-top: 18px;
            display: grid;
            gap: 8px;
        }

        .sidebar-link,
        .sidebar-submenu-link {
            text-decoration: none;
            color: #e2e8f0;
            transition: 0.2s ease;
        }

        .sidebar-link {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 12px;
            border-radius: 12px;
            background: transparent;
            border: 1px solid transparent;
            color: #e2e8f0;
            cursor: pointer;
            text-align: left;
            font: inherit;
        }

        .sidebar-link:hover,
        .sidebar-link.active,
        .sidebar-submenu-link:hover {
            background: rgba(37, 99, 235, 0.18);
            border-color: rgba(96, 165, 250, 0.38);
            color: #fff;
        }

        .sidebar-icon {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            color: #dbeafe;
            flex-shrink: 0;
        }

        .sidebar-link-text {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .sidebar-link-label {
            font-size: 0.9rem;
            font-weight: 600;
        }

        .sidebar-link-description {
            font-size: 0.72rem;
            color: #cbd5e1;
        }

        .sidebar-menu-wrapper {
            display: grid;
            gap: 8px;
        }

        .sidebar-submenu {
            display: none;
            padding: 8px;
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.18);
            gap: 6px;
        }

        .sidebar-menu-wrapper.expanded .sidebar-submenu {
            display: grid;
        }

        .sidebar-submenu-link {
            display: block;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 0.8rem;
            color: #dbeafe;
            border: 1px solid transparent;
        }

        .management-main {
            padding: 28px;
        }

        .page-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.04);
            padding: 24px;
            margin-bottom: 24px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .topbar h1 {
            margin: 0;
            font-size: clamp(1.8rem, 2vw, 2.4rem);
        }

        .topbar p {
            margin: 6px 0 0;
            color: var(--muted);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--text);
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }

        .btn.primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .btn.danger {
            background: #fff;
            color: var(--danger);
            border-color: #fecaca;
        }

        .btn.secondary {
            background: #f8fafc;
            color: var(--text);
        }

        .btn.small {
            padding: 7px 10px;
            font-size: 0.8rem;
        }

        .notice,
        .error {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 0.94rem;
        }

        .notice {
            background: #dcfce7;
            color: var(--success);
            border: 1px solid #bbf7d0;
        }

        .error {
            background: #fee2e2;
            color: var(--danger);
            border: 1px solid #fecaca;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
        }

        .section-head h2 {
            margin: 0;
            font-size: 1.3rem;
        }

        .grid-two {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 24px;
        }

        .panel-stack {
            display: grid;
            gap: 18px;
        }

        .field {
            margin-bottom: 14px;
        }

        .field label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
            color: var(--text);
        }

        .field input,
        .field select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            font: inherit;
            color: var(--text);
        }

        .inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        th, td {
            text-align: left;
            padding: 10px 8px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
            font-size: 0.9rem;
        }

        th {
            background: #f8fafc;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.74rem;
        }

        .muted {
            color: var(--muted);
        }

        .row-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .edit-panel {
            display: none;
            margin-top: 10px;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--panel-alt);
        }

        .edit-panel.visible {
            display: block;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .section-spacer {
            margin-top: 18px;
        }

        @media (max-width: 980px) {
            .management-shell {
                grid-template-columns: 1fr;
            }

            .management-sidebar {
                position: relative;
                height: auto;
                border-right: 0;
                border-bottom: 1px solid rgba(148, 163, 184, 0.2);
            }

            .grid-two,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .management-main {
                padding: 18px 14px;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        :root {
            --reference-purple: #dc2626;
            --reference-purple-dark: #991b1b;
            --reference-purple-soft: #fef2f2;
            --reference-page: #fff7f7;
            --reference-line: #fee2e2;
        }
        body { background: var(--reference-page); color: #3f1d1d; }
        .coordinator-content { background: var(--reference-page); }
        .management-main { padding: 34px 42px 42px; }
        .topbar h1 { color: var(--reference-purple); }
        .topbar p, .muted { color: #786d6d; }
        .page-card { border: 1px solid var(--reference-line); border-radius: 12px; box-shadow: 0 10px 26px rgba(127, 29, 29, .06); }
        .field input, .field select { border-color: #fecaca; border-radius: 10px; }
        .btn.primary { background: var(--reference-purple); border-color: var(--reference-purple); }
        .btn.primary:hover { background: var(--reference-purple-dark); }
        .btn.secondary { border-color: #fecaca; color: var(--reference-purple-dark); }
        .btn.secondary:hover { background: var(--reference-purple-soft); }
        th { background: var(--reference-purple-soft); color: var(--reference-purple-dark); }
        th, td { border-bottom-color: var(--reference-line); }
        .notice { background: var(--reference-purple-soft); color: var(--reference-purple-dark); border-color: #fecaca; }

        @media print {
            .coordinator-sidebar,
            .topbar .actions,
            .print-button {
                display: none !important;
            }

            .coordinator-shell {
                display: block;
            }

            .management-main {
                padding: 0;
            }

            .page-card {
                border: 0;
                box-shadow: none;
                margin: 0;
            }

            .print-student-view .topbar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="coordinator-shell">
        <?php include 'coordinator_sidebar.php'; ?>

        <main class="coordinator-content management-main<?php echo $current_view === 'students' ? ' print-student-view' : ''; ?>">
            <div class="topbar">
                <div>
                    <h1>Coordinator Management</h1>
                    <p>Create placement locations and assign each student to a supervisor.</p>
                </div>
                <div class="actions">
                    <a class="btn secondary" href="dashboard.php">Attendance Dashboard</a>
                    <a class="btn secondary" href="logout.php" onclick="return confirm('Are you sure you want to log out?');">Sign out</a>
                </div>
            </div>

            <?php if ($message && !str_contains($message, 'could not')): ?>
                <div class="notice"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error || ($message && str_contains($message, 'could not'))): ?>
                <div class="error"><?php echo htmlspecialchars($error ?: $message); ?></div>
            <?php endif; ?>

            <?php if ($current_view === 'agency'): ?>
            <section id="agency-section" class="page-card">
                <div class="section-head">
                    <h2>Add Agency</h2>
                </div>

                <div class="grid-two">
                    <div class="panel-stack">
                        <form method="post">
                            <input type="hidden" name="action" value="create_agency">
                            <div class="field">
                                <label for="agency_name">Agency Name</label>
                                <input id="agency_name" name="agency_name" required>
                            </div>
                            <div class="field">
                                <label for="agency_address">Address</label>
                                <input id="agency_address" name="address">
                            </div>
                            <div class="field">
                                <label for="agency_contact">Contact Information</label>
                                <input id="agency_contact" name="contact_info">
                            </div>
                            <button type="submit" class="btn primary">Create Agency</button>
                        </form>
                    </div>

                    <div class="panel-stack">
                        <h3>Agencies</h3>
                        <?php if (empty($agencies)): ?>
                            <p class="muted">No agencies added yet.</p>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Agency</th>
                                        <th>Address</th>
                                        <th>Contact</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($agencies as $agency): ?>
                                        <tr class="agency-row" data-agency-id="<?php echo (int)$agency['agency_id']; ?>" data-agency-name="<?php echo htmlspecialchars($agency['agency_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <td><?php echo htmlspecialchars($agency['agency_name']); ?></td>
                                            <td><?php echo htmlspecialchars($agency['address'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($agency['contact_info'] ?? ''); ?></td>
                                            <td>
                                                <div class="row-actions">
                                                    <button type="button" class="btn small secondary action-edit" data-target="agency-edit-<?php echo (int)$agency['agency_id']; ?>">Edit</button>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="action" value="delete_agency">
                                                        <input type="hidden" name="agency_id" value="<?php echo (int)$agency['agency_id']; ?>">
                                                        <button type="submit" class="btn small danger" onclick="return confirm('Delete this agency?');">Delete</button>
                                                    </form>
                                                </div>
                                                <div id="agency-edit-<?php echo (int)$agency['agency_id']; ?>" class="edit-panel">
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="update_agency">
                                                        <input type="hidden" name="agency_id" value="<?php echo (int)$agency['agency_id']; ?>">
                                                        <div class="field">
                                                            <label>Agency Name</label>
                                                            <input name="agency_name" value="<?php echo htmlspecialchars($agency['agency_name']); ?>" required>
                                                        </div>
                                                        <div class="field">
                                                            <label>Address</label>
                                                            <input name="address" value="<?php echo htmlspecialchars($agency['address'] ?? ''); ?>">
                                                        </div>
                                                        <div class="field">
                                                            <label>Contact Information</label>
                                                            <input name="contact_info" value="<?php echo htmlspecialchars($agency['contact_info'] ?? ''); ?>">
                                                        </div>
                                                        <button type="submit" class="btn primary small">Save Changes</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="agency-students-panel" class="page-card" style="display:none; margin-top: 20px;">
                    <div class="section-head">
                        <h3 id="agency-students-title">Assigned Students</h3>
                    </div>
                    <div id="agency-students-content" class="muted">Double-click an agency to view the students assigned to it.</div>
                </div>
            </section>
            <?php elseif ($current_view === 'assign'): ?>

            <section id="assign-section" class="page-card">
                <div class="section-head">
                    <h2>Assign Supervisor to Student</h2>
                </div>
                <p class="muted">A student can have one active supervisor assignment. Saving an existing student updates that assignment.</p>
                <form method="post">
                    <input type="hidden" name="action" value="assign_student">
                    <div class="form-grid">
                        <div class="field">
                            <label>Student</label>
                            <select name="student_id" required>
                                <option value="">Select student</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo htmlspecialchars($student['student_id']); ?>" <?php echo $editing_student_id === $student['student_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($student['student_id']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Supervisor</label>
                            <select name="supervisor_id" required>
                                <option value="">Select supervisor</option>
                                <?php foreach ($supervisors as $supervisor): ?>
                                    <option value="<?php echo (int)$supervisor['id']; ?>" <?php echo $editing_assignment && (int)$editing_assignment['supervisor_id'] === (int)$supervisor['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($supervisor['display_name']); ?> (<?php echo htmlspecialchars($supervisor['username']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Agency</label>
                            <select name="assignment_agency_id">
                                <option value="0">Select agency</option>
                                <?php foreach ($agencies as $agency): ?>
                                    <option value="<?php echo (int)$agency['agency_id']; ?>" <?php echo $editing_assignment && (int)$editing_assignment['agency_id'] === (int)$agency['agency_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($agency['agency_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn primary"><?php echo $editing_assignment ? 'Update Assignment' : 'Save Assignment'; ?></button>
                </form>

                <div class="section-spacer">
                    <div class="section-head">
                        <h3>All Students</h3>
                    </div>
                    <?php if (empty($students)): ?>
                        <p class="muted">No students are available yet.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Assigned Agency</th>
                                    <th>Assigned Supervisor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <?php $student_id = $student['student_id']; ?>
                                    <?php $assignment = $student_assignments[$student_id] ?? null; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student_id); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['agency_name'] ?? 'Not assigned'); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['supervisor_name'] ?? 'Not assigned'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>
            <?php elseif ($current_view === 'students'): ?>

            <section id="students-section" class="page-card">
                <div class="section-head">
                    <div>
                        <h2>All Work Immersion Students</h2>
                        <p class="muted">Complete list of students and their current agency and supervisor assignments.</p>
                    </div>
                    <button type="button" class="btn primary print-button" onclick="window.print()">
                        <span class="material-symbols-outlined" aria-hidden="true">print</span>
                        Print Students
                    </button>
                </div>
                <?php if (empty($students)): ?>
                    <p class="muted">No work immersion students are available yet.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Assigned Agency</th>
                                <th>Assigned Supervisor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <?php $student_id = $student['student_id']; ?>
                                <?php $assignment = $student_assignments[$student_id] ?? null; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student_id); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['agency_name'] ?? 'Not assigned'); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['supervisor_name'] ?? 'Not assigned'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
            <?php else: ?>

            <section id="assignment-section" class="page-card">
                <div class="section-head">
                    <h2>Current Assignments</h2>
                </div>
                <?php if (empty($assignments)): ?>
                    <p class="muted">No supervisor assignments created yet.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Supervisor</th>
                                <th>Agency</th>
                                        <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($assignment['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['supervisor_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['agency_name'] ?? '—'); ?></td>
                                    <td>
                                        <a class="btn small secondary" href="coordinator_management.php?view=assign&amp;edit_student=<?php echo rawurlencode($assignment['student_id']); ?>">Edit</a>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="remove_assignment">
                                            <input type="hidden" name="assignment_id" value="<?php echo (int)$assignment['assignment_id']; ?>">
                                            <button type="submit" class="btn small danger" onclick="return confirm('Remove this assignment?');">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </main>
    </div>

    <script>
        var agencyStudentMap = <?php echo json_encode($agency_students, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function(character) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[character];
            });
        }

        document.querySelectorAll('.action-edit').forEach(function(button) {
            button.addEventListener('click', function() {
                var target = document.getElementById(this.dataset.target);
                if (!target) return;
                target.classList.toggle('visible');
            });
        });

        document.querySelectorAll('.agency-row').forEach(function(row) {
            row.addEventListener('dblclick', function() {
                var agencyId = this.dataset.agencyId;
                var agencyName = this.dataset.agencyName || 'Agency';
                var panel = document.getElementById('agency-students-panel');
                var title = document.getElementById('agency-students-title');
                var content = document.getElementById('agency-students-content');
                var students = agencyStudentMap[agencyId] || [];

                title.textContent = 'Assigned Students for ' + agencyName;

                if (!students.length) {
                    content.innerHTML = '<p class="muted">No students assigned to this agency yet.</p>';
                } else {
                    var rows = students.map(function(student) {
                        return '<tr><td>' + escapeHtml(student.student_id) + '</td><td>' + escapeHtml(student.supervisor_name) + '</td></tr>';
                    }).join('');

                    content.innerHTML = '<table><thead><tr><th>Student</th><th>Supervisor</th></tr></thead><tbody>' + rows + '</tbody></table>';
                }

                panel.style.display = 'block';
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        });
    </script>
</body>
</html>
