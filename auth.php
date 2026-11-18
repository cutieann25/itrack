
<?php
// Start the session before loading other files or producing output.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load the database configuration after starting the session.
require_once __DIR__ . '/db_config.php';

function db_connection() {
    return project_db_connection();
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login($roles = []) {
    $user = current_user();

    if (!$user) {
        header('Location: login.php');
        exit;
    }

    if (!empty($roles) && !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }

    return $user;
}

function redirect_for_role($role) {
    header(
        'Location: ' .
        ($role === 'supervisor' ? 'supervisor.php' : 'dashboard.php')
    );
    exit;
}
