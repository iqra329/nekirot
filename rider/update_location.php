<?php
include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

require_login();
$user = current_user();

if (!$user || $user['type'] !== 'rider') {
    send_json(['success' => false, 'message' => 'Unauthorized. Rider login required.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'message' => 'Invalid request method. Use POST.'], 405);
}

$rawBody = file_get_contents('php://input');
$postData = $_POST;
if (!$postData && $rawBody) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $postData = $decoded;
    }
}

$rescueId = intval($postData['rescue_id'] ?? 0);
$latitude = isset($postData['latitude']) ? floatval($postData['latitude']) : null;
$longitude = isset($postData['longitude']) ? floatval($postData['longitude']) : null;

if ($rescueId <= 0 || $latitude === null || $longitude === null) {
    send_json(['success' => false, 'message' => 'Missing required fields: rescue_id, latitude, longitude.'], 400);
}

if (!is_numeric($latitude) || !is_numeric($longitude)) {
    send_json(['success' => false, 'message' => 'Latitude and longitude must be numeric values.'], 400);
}

$db = get_db_connection();

$stmt = $db->prepare('SELECT assigned_rider_id FROM rescues WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $rescueId);
$stmt->execute();
$rescue = $stmt->get_result()->fetch_assoc();

if (!$rescue) {
    $db->close();
    send_json(['success' => false, 'message' => 'Rescue not found.'], 404);
}

if (intval($rescue['assigned_rider_id']) !== intval($user['id'])) {
    $db->close();
    send_json(['success' => false, 'message' => 'You are not assigned to this rescue.'], 403);
}

// Ensure rider_locations table exists before inserting.
$db->query(
    'CREATE TABLE IF NOT EXISTS rider_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rescue_id INT NOT NULL,
        rider_id INT NOT NULL,
        latitude DECIMAL(10,6) NOT NULL,
        longitude DECIMAL(10,6) NOT NULL,
        recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(rescue_id),
        INDEX(rider_id),
        FOREIGN KEY (rescue_id) REFERENCES rescues(id) ON DELETE CASCADE,
        FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
);

$insert = $db->prepare(
    'INSERT INTO rider_locations (rescue_id, rider_id, latitude, longitude) VALUES (?, ?, ?, ?)'
);
$insert->bind_param('iidd', $rescueId, $user['id'], $latitude, $longitude);

if (!$insert->execute()) {
    $error = $db->error;
    $db->close();
    send_json(['success' => false, 'message' => 'Unable to save rider location. ' . $error], 500);
}

// Optionally keep current rider position up to date in the users table.
$updateUser = $db->prepare('UPDATE users SET latitude = ?, longitude = ?, updated_at = NOW() WHERE id = ?');
$updateUser->bind_param('ddi', $latitude, $longitude, $user['id']);
$updateUser->execute();

$db->close();
send_json(['success' => true, 'message' => 'Rider location updated successfully.']);
