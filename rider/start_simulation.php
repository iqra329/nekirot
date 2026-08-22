<?php
include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gps_simulation.php';

header('Content-Type: application/json');

$user = current_user();
if (!$user || empty($user['id']) || $user['type'] !== 'rider') {
    send_json(['success' => false, 'message' => 'Unauthorized. Rider login required.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'message' => 'Invalid request method. Use POST.'], 405);
}

$postData = $_POST;
$rawBody = file_get_contents('php://input');
if (empty($postData) && $rawBody) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $postData = $decoded;
    }
}

$rescueId = intval($postData['rescue_id'] ?? 0);
if ($rescueId <= 0) {
    send_json(['success' => false, 'message' => 'Missing or invalid rescue_id.'], 400);
}

$db = get_db_connection();
$stmt = $db->prepare('SELECT assigned_rider_id, status FROM rescues WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $rescueId);
$stmt->execute();
$rescue = $stmt->get_result()->fetch_assoc();
$stmt->close();
$db->close();

if (!$rescue) {
    send_json(['success' => false, 'message' => 'Rescue not found.'], 404);
}

if (intval($rescue['assigned_rider_id']) !== intval($user['id'])) {
    send_json(['success' => false, 'message' => 'You are not assigned to this rescue.'], 403);
}

if ($rescue['status'] !== 'in_transit') {
    send_json(['success' => false, 'message' => 'Simulation can only start when rescue status is in_transit.'], 400);
}

$started = startRiderSimulation($rescueId);
if (!$started) {
    send_json(['success' => false, 'message' => 'Failed to start the rider simulation.'], 500);
}

send_json(['success' => true, 'message' => 'Rider simulation started.']);
