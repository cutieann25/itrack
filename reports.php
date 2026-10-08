<?php
require_once 'auth.php';
require_login(['coordinator']);
$conn = db_connection();

$result = $conn->query("SELECT student_id, strand, COUNT(*) AS records, MAX(log_time) AS last_seen FROM attendance_logs GROUP BY student_id ORDER BY last_seen DESC");
$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = [
        'student_id' => $row['student_id'],
        'strand' => $row['strand'],
        'records' => $row['records'],
        'last_seen' => $row['last_seen'],
    ];
}
$total_students = count($students);
$total_records = array_sum(array_column($students, 'records'));
$latest_seen = !empty($students) ? max(array_column($students, 'last_seen')) : null;

function display_strand_label($strand) {
    return preg_replace('/^\s*\d+\.\s*/', '', (string)$strand);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - i-Tracker</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="coordinator_sidebar.css">
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #0f172a;
            background: #eef2ff;
            --bg: #f8fafc;
            --surface: #ffffff;
            --surface-muted: #e2e8f0;
            --text-muted: #64748b;
            --text-strong: #0f172a;
            --accent: #2563eb;
            --accent-dark: #1d4ed8;
            --border: rgba(148, 163, 184, 0.32);
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            min-height: 100%;
            background: radial-gradient(circle at top left, rgba(37, 99, 235, 0.14), transparent 20%),
                        radial-gradient(circle at bottom right, rgba(59, 130, 246, 0.12), transparent 18%),
                        var(--bg);
            color: var(--text-strong);
        }
        .coordinator-content { min-width: 0; }
        body, button, input, select, textarea {
            font: 100% Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .page {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: 30px 0 40px;
        }
        .page-header {
            display: grid;
            gap: 20px;
            margin-bottom: 28px;
        }
        .page-header-top {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 18px;
            align-items: flex-start;
        }
        .page-brand {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .page-brand h1 {
            margin: 0;
            font-size: clamp(2rem, 2.6vw, 2.6rem);
            letter-spacing: -0.04em;
        }
        .page-brand p {
            margin: 0;
            color: var(--text-muted);
            max-width: 640px;
            line-height: 1.7;
        }
        .page-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .nav a {
            padding: 10px 18px;
            border-radius: 999px;
            text-decoration: none;
            color: #334155;
            background: #e2e8f0;
            transition: background .2s ease, color .2s ease;
            font-weight: 600;
        }
        .nav a.active,
        .nav a:hover {
            background: var(--accent-dark);
            color: #fff;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-top: 12px;
        }
        .stat-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.96), #ffffff);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 22px 24px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.06);
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(37, 99, 235, 0.35);
            box-shadow: 0 26px 70px rgba(37, 99, 235, 0.1);
        }
        .stat-card small {
            display: block;
            margin-bottom: 8px;
            font-size: 0.82rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .stat-card strong,
        .stat-card span {
            display: block;
            font-size: 2rem;
            line-height: 1.05;
            color: var(--text-strong);
        }
        .stat-card .stat-label {
            margin-top: 10px;
            color: var(--text-muted);
            font-size: 0.95rem;
        }
        .report-panel {
            background: var(--surface);
            border-radius: 28px;
            border: 1px solid var(--border);
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
            padding: 28px;
        }
        .report-panel h2 {
            margin: 0 0 12px;
            font-size: 1.35rem;
        }
        .report-panel p {
            margin: 0;
            color: var(--text-muted);
        }
        .report-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 10px;
            margin-top: 20px;
            min-width: 720px;
        }
        .report-table th,
        .report-table td {
            padding: 16px 18px;
            text-align: left;
            vertical-align: middle;
            border: none;
        }
        .report-table thead th {
            color: #334155;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.82rem;
        }
        .report-table tbody tr {
            background: #f8fbff;
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 18px;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .report-table tbody tr:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 35px rgba(15, 23, 42, 0.06);
        }
        .report-table tbody tr td {
            border-bottom: none;
        }
        .report-table tbody tr td:first-child {
            width: 18%;
            font-weight: 700;
            color: var(--accent-dark);
        }
        .report-table tbody tr td:last-child {
            text-align: right;
            width: 156px;
            white-space: nowrap;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.12);
            color: var(--accent-dark);
            font-size: 0.85rem;
            font-weight: 700;
        }
        .link-button,
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 999px;
            text-decoration: none;
            transition: background .2s ease, color .2s ease, transform .2s ease;
        }
        .link-button {
            background: var(--accent);
            color: #fff;
            font-weight: 600;
            white-space: nowrap;
        }
        .link-button:hover {
            background: var(--accent-dark);
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: #fff;
            color: #334155;
            border: 1px solid #cbd5e1;
            font-weight: 600;
        }
        .btn-secondary:hover {
            background: #eff6ff;
            color: var(--accent-dark);
        }
        .empty {
            padding: 48px;
            text-align: center;
            color: var(--text-muted);
        }
        .footer-actions {
            margin-top: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: flex-end;
        }
        @media (max-width: 900px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .report-table {
                min-width: 100%;
            }
            .report-table tbody tr td:last-child {
                text-align: left;
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
        .page { width: auto; max-width: none; padding: 34px 42px 42px; }
        .page-brand h1 { color: var(--reference-purple); letter-spacing: 0; }
        .page-brand p, .stat-card .stat-label, .report-panel p { color: #786d6d; }
        .nav a { border-radius: 10px; background: #fff; border: 1px solid var(--reference-line); color: var(--reference-purple-dark); }
        .nav a.active, .nav a:hover { background: var(--reference-purple); }
        .stat-card, .report-panel { border: 1px solid var(--reference-line); border-radius: 12px; box-shadow: 0 10px 26px rgba(127, 29, 29, .06); }
        .stat-card { border-top: 4px solid var(--reference-purple); }
        .stat-card strong, .stat-card span { color: #2d2340; }
        .report-table tbody tr { background: #fffafa; }
        .report-table tbody tr:hover { background: var(--reference-purple-soft); }
        .report-table tbody tr td:first-child { color: var(--reference-purple); }
        .badge { background: var(--reference-purple-soft); color: var(--reference-purple-dark); }
        .link-button { background: var(--reference-purple); }
        .link-button:hover { background: var(--reference-purple-dark); }
        .btn-secondary { border-color: #fecaca; color: var(--reference-purple-dark); }
        .btn-secondary:hover { background: var(--reference-purple-soft); color: var(--reference-purple); }
    </style>
</head>
<body>
    <div class="coordinator-shell">
        <?php include 'coordinator_sidebar.php'; ?>
        <main class="coordinator-content">
        <div class="page">
        <header class="page-header">
            <div class="page-header-top">
                <div class="page-brand">
                    <div class="badge">Reports</div>
                    <h1>Attendance reports & student summaries</h1>
                    <p>Review the latest student activity, download daily time records, and keep attendance reporting polished and easy to scan.</p>
                </div>
                <div class="page-actions">
                    <a href="dashboard.php" class="btn-secondary">Dashboard</a>
                    <a href="students.php" class="btn-secondary">Students</a>
                    <a href="reports.php" class="link-button">Refresh</a>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-card">
                    <small>Total Students</small>
                    <strong><?php echo $total_students; ?></strong>
                    <span class="stat-label">Unique student profiles included</span>
                </div>
                <div class="stat-card">
                    <small>Attendance Records</small>
                    <strong><?php echo $total_records; ?></strong>
                    <span class="stat-label">Total database rows grouped by student</span>
                </div>
                <div class="stat-card">
                    <small>Latest Activity</small>
                    <strong><?php echo $latest_seen ? htmlspecialchars(date('M j, Y g:i A', strtotime($latest_seen))) : 'N/A'; ?></strong>
                    <span class="stat-label">Most recent attendance scan</span>
                </div>
            </div>
        </header>

        <section class="report-panel">
            <div class="report-panel-header">
                <h2>Student Attendance Report</h2>
                <p>Download a DTR for any student and scan the latest attendance activity.</p>
            </div>
            <?php if (empty($students)): ?>
                <div class="empty">No attendance records found to generate reports.</div>
            <?php else: ?>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Strand</th>
                            <th>Records</th>
                            <th>Last Seen</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                <td><?php echo htmlspecialchars(display_strand_label($student['strand'])); ?></td>
                                <td><span class="badge"><?php echo intval($student['records']); ?></span></td>
                                <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($student['last_seen']))); ?></td>
                                <td><a class="link-button" href="generate_dtr.php?student_name=<?php echo urlencode($student['student_id']); ?>&strand=<?php echo urlencode($student['strand']); ?>">Download DTR</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <div class="footer-actions">
            <a href="dashboard.php" class="btn-secondary">Back to Dashboard</a>
            <a href="students.php" class="btn-secondary">View Students</a>
        </div>
        </div>
        </main>
    </div>
</body>
</html>
