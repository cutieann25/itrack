<?php
// Start the session before loading other files.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load database configuration.
require_once __DIR__ . '/db_config.php';

// Get database connection.
function db_connection() {
    return project_db_connection();
}

// Get currently logged-in user.
function current_user() {
    return $_SESSION['user'] ?? null;
}

// Check whether the user is logged in and has permission.
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

// Redirect users according to their role.
function redirect_for_role($role) {
    if ($role === 'supervisor') {
        header('Location: supervisor.php');
    } else {
        header('Location: dashboard.php');
    }

    exit;
}
