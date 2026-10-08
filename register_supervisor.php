<?php
// register_supervisor.php
header('Content-Type: application/json');
require_once 'db_config.php';
$conn = project_db_connection();

if (!$conn) {
    echo json_encode(["status" => "ERROR", "message" => "Connection failed"]);
    exit;
}

$fullname = $_POST['fullname'] ?? '';
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$gender = $_POST['gender'] ?? '';
$phone = $_POST['phone'] ?? '';
$email = $_POST['email'] ?? '';
$address = $_POST['address'] ?? '';

if (empty($fullname) || empty($username) || empty($password)) {
    echo json_encode(["status" => "ERROR", "message" => "Required fields missing"]);
    exit;
}

// Check if username already exists
$check = $conn->prepare("SELECT id FROM users WHERE username = ?");
$check->bind_param("s", $username);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    echo json_encode(["status" => "ERROR", "message" => "Username already exists"]);
    exit;
}

// Securely hash the password
$hash = password_hash($password, PASSWORD_DEFAULT);
$role = 'supervisor';

$sql = "INSERT INTO users (username, display_name, role, password_hash, gender, phone, email, address) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssssss", $username, $fullname, $role, $hash, $gender, $phone, $email, $address);

if ($stmt->execute()) {
    echo json_encode(["status" => "SUCCESS", "message" => "Account created successfully"]);
} else {
    echo json_encode(["status" => "ERROR", "message" => "Database error: " . $conn->error]);
}

$conn->close();
?>