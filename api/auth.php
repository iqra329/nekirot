<?php
require_once __DIR__ . '/../includes/functions.php';
cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

db_connect();
$db = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    if (!$body) {
        send_json(['error' => 'Invalid request'], 400);
    }

    $action = $body['action'] ?? null;
    if ($action === 'login') {
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        if (!$email || !$password) {
            send_json(['error' => 'Email and password are required'], 400);
        }
        $stmt = $db->prepare('SELECT id, name, password_hash, user_type FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        if ($user && password_verify($password, $user['password_hash'])) {
            send_json(['success' => true, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'type' => $user['user_type']]]);
        }
        send_json(['error' => 'Invalid credentials'], 401);
    }

    if ($action === 'register') {
        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $type = $body['type'] ?? '';
        $phone = trim($body['phone'] ?? '');

        if (!$name || !$email || !$password || !$type || !$phone) {
            send_json(['error' => 'All fields are required'], 400);
        }
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            send_json(['error' => 'Email already exists'], 409);
        }
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, user_type, phone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->bind_param('sssss', $name, $email, $password_hash, $type, $phone);
        if ($stmt->execute()) {
            send_json(['success' => true, 'user' => ['id' => $db->insert_id, 'name' => $name, 'type' => $type]]);
        }
        send_json(['error' => 'Registration failed'], 500);
    }
}

send_json(['error' => 'Method not allowed'], 405);
