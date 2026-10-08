<?php
// supervisor_auth_check.php
header('Content-Type: application/json');
require_once 'auth.php';

$user = $_POST['username'] ?? '';
$pass = $_POST['password'] ?? '';

if ($user === 'supervisor' && $pass === 'supervisor@123') {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user'] = [
        'id' => 1,
        'username' => 'supervisor',
        'display_name' => 'Work Immersion Supervisor',
        'role' => 'supervisor'
    ];
    echo json_encode(["status" => "SUCCESS", "message" => "Login successful"]);
} else {
    echo json_encode(["status" => "ERROR", "message" => "Invalid supervisor credentials"]);
}
?>