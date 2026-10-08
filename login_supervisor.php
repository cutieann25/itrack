<?php
// login_supervisor.php
header('Content-Type: application/json');
require_once 'db_config.php';
$conn = project_db_connection();

if (!$conn) {
    echo json_encode(["status" => "ERROR", "message" => "Connection failed"]);
    exit;
}

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// Find the user by username
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND role = 'supervisor' LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows > 0) {
    $user = $res->fetch_assoc();
    // Verify the hashed password
    if (password_verify($password, $user['password_hash'])) {
        echo json_encode([
            "status" => "SUCCESS",
            "message" => "Login successful",
            "username" => $user['username'],
            "fullname" => $user['display_name']
        ]);
    } else {
        echo json_encode(["status" => "ERROR", "message" => "Invalid password"]);
    }
} else {
    echo json_encode(["status" => "ERROR", "message" => "User not found"]);
}

$conn->close();
?>