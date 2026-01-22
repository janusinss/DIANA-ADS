<?php
// api/auth.php
include_once 'header.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput();

// Route Actions
if ($method === 'POST') {
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'register':
                registerUser($conn, $input);
                break;
            case 'login':
                loginUser($conn, $input);
                break;
            default:
                sendResponse(400, "Invalid action");
        }
    } else {
        // Default to login if no action or try to infer? Better explicit.
        sendResponse(400, "Action parameter required (?action=register|login)");
    }
} else {
    sendResponse(405, "Method Not Allowed");
}

function registerUser($conn, $data)
{
    if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
        sendResponse(400, "Incomplete data. Username, email, and password required.");
    }

    $username = $conn->real_escape_string($data['username']);
    $email = $conn->real_escape_string($data['email']);
    $password = password_hash($data['password'], PASSWORD_DEFAULT);

    // Check existing
    $check = $conn->query("SELECT id FROM users WHERE email = '$email' OR username = '$username'");
    if ($check->num_rows > 0) {
        sendResponse(409, "User already exists (email or username taken).");
    }

    $sql = "INSERT INTO users (username, email, password) VALUES ('$username', '$email', '$password')";
    if ($conn->query($sql)) {
        sendResponse(201, "User registered successfully.", ["id" => $conn->insert_id]);
    } else {
        sendResponse(500, "Registration failed: " . $conn->error);
    }
}

function loginUser($conn, $data)
{
    if (empty($data['email']) || empty($data['password'])) {
        sendResponse(400, "Email and password required.");
    }

    $email = $conn->real_escape_string($data['email']);

    $res = $conn->query("SELECT * FROM users WHERE email = '$email'");
    if ($res->num_rows === 0) {
        sendResponse(401, "Invalid credentials.");
    }

    $user = $res->fetch_assoc();
    if (password_verify($data['password'], $user['password'])) {
        // Ideally generate a JWT token here.
        // For this simple implementation, we return the user_id which the client 'trusts'.
        // IN PRODUCTION: USE JWT OR SESSION TOKENS.
        unset($user['password']);
        unset($user['reset_token']);
        unset($user['reset_expiry']);

        sendResponse(200, "Login successful.", $user);
    } else {
        sendResponse(401, "Invalid credentials.");
    }
}
?>