<?php
$sidebar_page = basename($_SERVER['PHP_SELF']);
$sidebar_view = $_GET['view'] ?? '';
$sidebar_management_active = $sidebar_page === 'coordinator_management.php';
?>
<aside class="coordinator-sidebar">
    <div class="coordinator-sidebar-brand">
        <div class="coordinator-sidebar-logo">i</div>
        <div>
            <h2>i-Tracker</h2>
            <p>Attendance overview</p>
        </div>
    </div>
    <div class="coordinator-access-label">Coordinator access</div>
    <div class="coordinator-sidebar-section-title">Navigation</div>
    <nav class="coordinator-sidebar-nav">
        <a href="dashboard.php?view=overview" class="coordinator-sidebar-link <?php echo $sidebar_page === 'dashboard.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined coordinator-sidebar-icon">dashboard</span>
            <span class="coordinator-sidebar-link-text"><strong>Overview</strong><small>Summary and key metrics</small></span>
        </a>
        <a href="students.php" class="coordinator-sidebar-link <?php echo $sidebar_page === 'students.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined coordinator-sidebar-icon">school</span>
            <span class="coordinator-sidebar-link-text"><strong>Students</strong><small>View student attendance</small></span>
        </a>
        <a href="reports.php" class="coordinator-sidebar-link <?php echo $sidebar_page === 'reports.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined coordinator-sidebar-icon">analytics</span>
            <span class="coordinator-sidebar-link-text"><strong>Reports</strong><small>Attendance summaries and logs</small></span>
        </a>
        <div class="coordinator-sidebar-menu <?php echo $sidebar_management_active ? 'expanded' : ''; ?>">
            <button type="button" class="coordinator-sidebar-link coordinator-sidebar-menu-trigger <?php echo $sidebar_management_active ? 'active' : ''; ?>" aria-expanded="<?php echo $sidebar_management_active ? 'true' : 'false'; ?>">
                <span class="material-symbols-outlined coordinator-sidebar-icon">business</span>
                <span class="coordinator-sidebar-link-text"><strong>Agencies & Assignments</strong><small>Manage placements and supervisors</small></span>
            </button>
            <div class="coordinator-sidebar-submenu">
                <a href="coordinator_management.php?view=agency" class="coordinator-sidebar-subitem <?php echo $sidebar_view === 'agency' ? 'active' : ''; ?>"><span class="material-symbols-outlined">add_business</span><span>Add Agency</span></a>
                <a href="coordinator_management.php?view=assign" class="coordinator-sidebar-subitem <?php echo $sidebar_view === 'assign' ? 'active' : ''; ?>"><span class="material-symbols-outlined">person_add</span><span>Assign Supervisor to Student</span></a>
                <a href="coordinator_management.php?view=assignment" class="coordinator-sidebar-subitem <?php echo $sidebar_view === 'assignment' ? 'active' : ''; ?>"><span class="material-symbols-outlined">assignment</span><span>Current Assignments</span></a>
                <a href="coordinator_management.php?view=students" class="coordinator-sidebar-subitem <?php echo $sidebar_view === 'students' ? 'active' : ''; ?>"><span class="material-symbols-outlined">print</span><span>All Work Immersion Students</span></a>
            </div>
        </div>
        <a href="coordinator_panel.php" class="coordinator-sidebar-link <?php echo $sidebar_page === 'coordinator_panel.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined coordinator-sidebar-icon">fact_check</span>
            <span class="coordinator-sidebar-link-text"><strong>Student Approval Requests</strong><small>Approve or reject student access</small></span>
        </a>
        <a href="logout.php" class="coordinator-sidebar-link coordinator-sidebar-logout" onclick="return confirm('Are you sure you want to log out?');">
            <span class="material-symbols-outlined coordinator-sidebar-icon">logout</span>
            <span class="coordinator-sidebar-link-text"><strong>Sign out</strong><small>End coordinator session</small></span>
        </a>
    </nav>
</aside>
<script>
(function () {
    var menu = document.querySelector('.coordinator-sidebar-menu-trigger');
    if (menu) {
        menu.addEventListener('click', function () {
            var wrapper = menu.closest('.coordinator-sidebar-menu');
            var expanded = wrapper.classList.toggle('expanded');
            menu.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    }

}());
</script>
