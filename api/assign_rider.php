<?php
require_once __DIR__ . '/../includes/functions.php';
cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

db_connect();
$db = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'Method not allowed'], 405);
}

$body = get_json_body();
if (!$body) {
    send_json(['error' => 'Invalid request'], 400);
}

$rescue_id = intval($body['rescue_id'] ?? 0);
$rider_id = intval($body['rider_id'] ?? 0);
if (!$rescue_id || !$rider_id) {
    send_json(['error' => 'rescue_id and rider_id are required'], 400);
}

$stmt = $db->prepare('UPDATE rescues SET assigned_rider_id = ?, status = ?, updated_at = NOW() WHERE id = ? AND status = ?');
$status = 'accepted';
$pending = 'pending';
$stmt->bind_param('isis', $rider_id, $status, $rescue_id, $pending);
$stmt->execute();
if ($stmt->affected_rows > 0) {
    send_json(['success' => true]);
}

send_json(['error' => 'Unable to assign rider or rescue already assigned'], 400);
