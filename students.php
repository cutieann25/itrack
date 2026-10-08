<?php
require_once 'auth.php';
require_login(['coordinator']);
$conn = db_connection();

$result = $conn->query("SELECT grouped.student_id,
    (SELECT latest.strand
     FROM attendance_logs AS latest
     WHERE latest.student_id = grouped.student_id
     ORDER BY latest.log_time DESC, latest.id DESC
     LIMIT 1) AS strand,
    grouped.records
    FROM (
        SELECT student_id, COUNT(*) AS records, MAX(log_time) AS last_seen
        FROM attendance_logs
        GROUP BY student_id
    ) AS grouped
    ORDER BY grouped.last_seen DESC");
if (!$result) {
    error_log('Unable to load student attendance: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load student attendance. Please contact the administrator.');
}
$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = [
        'student_id' => $row['student_id'],
        'strand' => $row['strand'],
        'records' => $row['records'],
    ];
}

$total_students = count($students);
$total_records = array_sum(array_column($students, 'records'));

$default_map = null;
$mapResult = $conn->query("SELECT latitude, longitude, student_id FROM attendance_logs WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY log_time DESC LIMIT 1");
if ($mapResult && $mapResult->num_rows > 0) {
    $mapRow = $mapResult->fetch_assoc();
    $default_map = $mapRow;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students - i-Tracker</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="coordinator_sidebar.css">
    <style>
        body { margin: 0; font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #eef2ff; color: #0f172a; }
        .coordinator-content { background: #eef2ff; }
        .page { max-width: 1200px; margin: 0 auto; padding: 24px; }
        .topbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; }
        .topbar h1 { margin: 0; font-size: 2rem; color: #1e3a8a; }
        .topbar p { margin: 8px 0 0; color: #475569; }
        .nav { display: flex; flex-wrap: wrap; gap: 10px; }
        .nav a { padding: 10px 18px; border-radius: 999px; text-decoration: none; color: #334155; background: #e2e8f0; transition: background .2s ease, color .2s ease, transform .2s ease; }
        .nav a:hover { background: #c7d2fe; color: #1e3a8a; transform: translateY(-1px); }
        .nav a.active { background: #1d4ed8; color: #fff; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 20px; }
        .summary-card { background: #fff; border-radius: 24px; box-shadow: 0 15px 40px rgba(15, 23, 42, 0.08); padding: 22px; border: 1px solid rgba(147, 197, 253, 0.5); }
        .summary-card strong { display: block; font-size: 2rem; color: #1d4ed8; }
        .summary-card small { color: #475569; text-transform: uppercase; letter-spacing: 0.08em; }
        .card { background: #fff; border-radius: 28px; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08); padding: 24px; border: 1px solid rgba(203, 213, 225, 0.65); }
        .student-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-top: 20px; }
        .student-card { display: block; padding: 22px 24px; border-radius: 24px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); border: 1px solid rgba(148, 163, 184, 0.18); text-decoration: none; color: inherit; transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease; }
        .student-card:hover { transform: translateY(-4px); border-color: #2563eb; box-shadow: 0 18px 36px rgba(37, 99, 235, 0.14); }
        .student-card strong { display: block; font-size: 1.1rem; margin-bottom: 8px; color: #1e293b; }
        .student-card small { color: #475569; }
        .student-card .meta { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 14px; }
        .student-card .badge { display: inline-flex; padding: 8px 12px; border-radius: 999px; background: #e0f2fe; color: #0369a1; font-size: 0.85rem; font-weight: 700; }
        .filters { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px; align-items: center; }
        .filters label { display: flex; align-items: center; gap: 8px; color: #475569; }
        .filters select, .filters input { border: 1px solid #cbd5e1; border-radius: 999px; padding: 10px 14px; background: #fff; color: #0f172a; }
        .empty { text-align: center; padding: 48px; color: #475569; }
        .btn-secondary { display: inline-flex; padding: 10px 18px; border-radius: 999px; background: #f8fafc; color: #334155; text-decoration: none; border: 1px solid #cbd5e1; transition: background .2s ease, transform .2s ease; }
        .btn-secondary:hover { background: #e2e8f0; transform: translateY(-1px); }
        @media (max-width: 720px) {
            .topbar { flex-direction: column; align-items: stretch; }
            .summary-grid { grid-template-columns: 1fr; }
        }

        :root {
            --reference-purple: #dc2626;
            --reference-purple-dark: #991b1b;
            --reference-purple-soft: #fef2f2;
            --reference-page: #fff7f7;
            --reference-line: #fee2e2;
        }
        body { background: var(--reference-page); color: #2d2340; }
        .coordinator-content { background: var(--reference-page); }
        .page { max-width: none; padding: 34px 42px 42px; }
        .topbar h1 { color: var(--reference-purple); }
        .topbar p, .filters label, .student-card small, .empty { color: #786d6d; }
        .nav a { border-radius: 10px; background: #fff; border: 1px solid var(--reference-line); color: var(--reference-purple-dark); }
        .nav a:hover, .nav a.active { background: var(--reference-purple); color: #fff; }
        .summary-card, .card { border: 1px solid var(--reference-line); border-radius: 12px; box-shadow: 0 10px 26px rgba(127, 29, 29, .06); }
        .summary-card strong { color: var(--reference-purple); }
        .summary-card small { color: #786d6d; }
        .student-card { border-radius: 10px; background: var(--reference-purple-soft); border-color: #fecaca; }
        .student-card:hover { border-color: var(--reference-purple); background: #fee2e2; box-shadow: 0 10px 22px rgba(127, 29, 29, .1); }
        .student-card strong { color: var(--reference-purple-dark); }
        .student-card .badge { background: #fff; color: var(--reference-purple); }
        .filters select, .filters input { border-color: #fecaca; border-radius: 10px; }
        .btn-secondary { border-radius: 10px; border-color: #fecaca; background: #fff; color: var(--reference-purple-dark); }
        .btn-secondary:hover { background: var(--reference-purple-soft); }
    </style>
</head>
<body>
    <div class="coordinator-shell">
        <?php include 'coordinator_sidebar.php'; ?>
        <main class="coordinator-content">
        <div class="page">
        <div class="topbar">
            <div>
                <h1>Student Directory</h1>
                <p>Browse student attendance history and click a name to view details.</p>
            </div>
            <div class="nav">
                <a href="dashboard.php">Overview</a>
                <a href="students.php" class="active">Students</a>
                <a href="reports.php">Reports</a>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <small>Total Students</small>
                <strong><?php echo $total_students; ?></strong>
            </div>
            <div class="summary-card">
                <small>Total Attendance Records</small>
                <strong><?php echo $total_records; ?></strong>
            </div>
            <div class="summary-card">
                <small>Most Recent Location</small>
                <strong><?php echo $default_map ? htmlspecialchars($default_map['student_id']) : 'None'; ?></strong>
            </div>
        </div>

        <div class="card">
            <div class="filters">
                <label>
                    Filter by strand
                    <select id="strandFilter">
                        <option value="">All strands</option>
                        <option value="ABM">ABM</option>
                        <option value="ICT">ICT</option>
                        <option value="HUMSS">HUMSS</option>
                        <option value="HE">HE</option>
                    </select>
                </label>
                <label>
                    Search by name
                    <input id="searchStudent" type="search" placeholder="Start typing...">
                </label>
            </div>

            <?php if (empty($students)): ?>
                <div class="empty">No students found yet. Please add attendance logs first.</div>
            <?php else: ?>
                <div class="student-grid" id="studentGrid">
                    <?php foreach ($students as $student): ?>
                        <a class="student-card" data-strand="<?php echo htmlspecialchars($student['strand']); ?>" href="dashboard.php?student_id=<?php echo urlencode($student['student_id']); ?>">
                            <strong><?php echo htmlspecialchars($student['student_id']); ?></strong>
                            <div class="meta">
                                <small><?php echo htmlspecialchars($student['strand'] ?: 'No strand'); ?></small>
                                <span class="badge"><?php echo intval($student['records']); ?> record<?php echo $student['records'] === '1' ? '' : 's'; ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div style="margin-top: 24px;">
            <a href="dashboard.php" class="btn-secondary">Back to Dashboard</a>
        </div>
        </div>
        </main>
    </div>
<script>
    var searchInput = document.getElementById('searchStudent');
    var strandFilter = document.getElementById('strandFilter');
    var cards = document.querySelectorAll('.student-card');

    function updateStudentGrid() {
        var query = searchInput.value.toLowerCase();
        var strand = strandFilter.value;

        cards.forEach(function(card) {
            var matchesSearch = card.textContent.toLowerCase().includes(query);
            var matchesStrand = !strand || card.dataset.strand === strand;
            card.style.display = matchesSearch && matchesStrand ? 'block' : 'none';
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', updateStudentGrid);
    }
    if (strandFilter) {
        strandFilter.addEventListener('change', updateStudentGrid);
    }
</script>
</body>
</html>
