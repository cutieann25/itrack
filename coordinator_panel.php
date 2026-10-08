<?php
require_once 'auth.php';
require_login(['coordinator']);

$conn = project_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $action = $_POST['action'];
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    $status = $action === 'approve' ? 'APPROVED' : ($action === 'reject' ? 'REJECTED' : null);

    if ($status !== null && $id !== false) {
        $stmt = $conn->prepare("UPDATE students SET status = ? WHERE id = ? AND status = 'PENDING'");
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: coordinator_panel.php');
    exit();
}

$result = $conn->query('SELECT id, student_name, strand, gender, dob, phone, parents, status, created_at FROM students ORDER BY created_at DESC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordinator Student Approval Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="coordinator_sidebar.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f0f2f5;
            margin: 0;
            padding: 0;
        }
        .coordinator-content { min-width: 0; padding: 24px; }
        .container {
            width: 100%;
            max-width: 1400px;
            min-height: calc(100vh - 48px);
            box-sizing: border-box;
            margin: 0 auto;
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        }
        h2 {
            color: #1A237E;
            border-bottom: 2px solid #1A237E;
            padding-bottom: 10px;
            margin-top: 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            table-layout: fixed;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            overflow-wrap: anywhere;
            vertical-align: top;
        }
        th {
            font-size: 13px;
        }
        td {
            font-size: 13px;
        }
        th {
            background: #1A237E;
            color: white;
        }
        .status-PENDING {
            color: #f39c12;
            font-weight: bold;
        }
        .status-APPROVED {
            color: #27ae60;
            font-weight: bold;
        }
        .status-REJECTED {
            color: #c0392b;
            font-weight: bold;
        }
        .btn {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            font-size: 14px;
            margin-right: 6px;
        }
        .btn-approve {
            background: #2e7d32;
        }
        .btn-reject {
            background: #c62828;
        }
        .btn-dashboard {
            background: #546e7a;
            margin-bottom: 16px;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .actions-cell {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .actions-cell .btn {
            margin-right: 0;
        }
        @media (max-width: 700px) {
            body {
                padding: 12px;
            }
            .container {
                padding: 16px;
            }
            th, td {
                padding: 8px 5px;
                font-size: 11px;
            }
            th {
                font-size: 10px;
            }
            .btn {
                padding: 6px 7px;
                font-size: 11px;
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
        .coordinator-content { background: var(--reference-page); padding: 34px 42px 42px; }
        .container { max-width: none; min-height: 0; padding: 28px; border: 1px solid var(--reference-line); border-radius: 12px; box-shadow: 0 10px 26px rgba(127, 29, 29, .06); }
        h2 { color: var(--reference-purple); border-bottom-color: var(--reference-purple); }
        table { margin-top: 24px; border-collapse: separate; border-spacing: 0 6px; }
        th { background: var(--reference-purple); color: #fff; border: 0; }
        th:first-child { border-radius: 8px 0 0 8px; }
        th:last-child { border-radius: 0 8px 8px 0; }
        td { background: #fffafa; border-bottom: 1px solid var(--reference-line); }
        .status-PENDING { color: #a16207; }
        .btn-dashboard { background: var(--reference-purple); }
        .btn-approve { background: #3d8c63; }
        .btn-reject { background: #b44a5a; }
    </style>
</head>
<body>
    <div class="coordinator-shell">
        <?php include 'coordinator_sidebar.php'; ?>
        <main class="coordinator-content">
        <div class="container">
        <a href="dashboard.php" class="btn btn-dashboard">&larr; Back to Dashboard</a>
        <h2>Coordinator Student Approval Dashboard</h2>
        <p>Review and manage student access requests for the iTracker system.</p>

        <table>
            <thead>
                <tr>
                    <th>Student Full Name</th>
                    <th>Strand</th>
                    <th>Gender</th>
                    <th>Birth Date</th>
                    <th>Contact Number</th>
                    <th>Parents/Guardian</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['student_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['strand']); ?></td>
                            <td><?php echo htmlspecialchars($row['gender'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['dob'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['phone'] ?? ''); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($row['parents'] ?? '')); ?></td>
                            <td><span class="status-<?php echo htmlspecialchars($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                            <td>
                                <?php if ($row['status'] === 'PENDING'): ?>
                                    <div class="actions-cell">
                                        <form method="post">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-approve" onclick="return confirm('Approve this student account?');">Approve</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-reject" onclick="return confirm('Reject this student account?');">Reject</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    Decision recorded
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align:center; color:#777;">No student records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        </main>
    </div>
</body>
</html>
<?php $conn->close(); ?>
