<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);
    if (!$user_id) {
        send_json(['error' => 'user_id is required'], 400);
    }
    $alerts = get_alerts_for_user($user_id);
    send_json(['alerts' => $alerts]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    if (!$body) {
        send_json(['error' => 'Invalid request'], 400);
    }
    $user_id = intval($body['user_id'] ?? 0);
    $message = trim($body['message'] ?? '');
    $type = trim($body['type'] ?? 'notification');
    if (!$user_id || !$message) {
        send_json(['error' => 'user_id and message are required'], 400);
    }
    if (add_alert($user_id, $message, $type)) {
        send_json(['success' => true]);
    }
    send_json(['error' => 'Unable to add alert'], 500);
}

send_json(['error' => 'Method not allowed'], 405);
