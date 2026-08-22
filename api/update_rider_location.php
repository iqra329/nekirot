<?php
// ============================================
// UPDATE RIDER LOCATION API
// Called from rider's start_transit.php during animation
// Saves rider's current position to database
// ============================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_login();
$user = current_user();

if (!$user || $user['type'] !== 'rider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only riders can update location.']);
    exit;
}

$riderId = intval($user['id']);

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if ($input === null) {
    $input = $_POST;
}

$rescueId = intval($input['rescue_id'] ?? $input['id'] ?? 0);
$latitude = floatval($input['latitude'] ?? $input['lat'] ?? 0);
$longitude = floatval($input['longitude'] ?? $input['lng'] ?? 0);

if ($rescueId <= 0 || $latitude <= 0 || $longitude <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$db = get_db_connection();

// Check if rider is assigned to this rescue
$checkStmt = $db->prepare('SELECT id, assigned_rider_id, status FROM rescues WHERE id = ? LIMIT 1');
$checkStmt->bind_param('i', $rescueId);
$checkStmt->execute();
$rescue = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$rescue) {
    echo json_encode(['success' => false, 'message' => 'Rescue not found']);
    $db->close();
    exit;
}

if (intval($rescue['assigned_rider_id']) !== $riderId) {
    echo json_encode(['success' => false, 'message' => 'You are not assigned to this rescue']);
    $db->close();
    exit;
}

// Don't update if already delivered
if (strtolower($rescue['status']) === 'delivered') {
    echo json_encode(['success' => true, 'message' => 'Already delivered', 'skipped' => true]);
    $db->close();
    exit;
}

// Check if rider_current_lat column exists
$columnCheck = $db->query("SHOW COLUMNS FROM rescues LIKE 'rider_current_lat'");
$hasLatColumn = ($columnCheck && $columnCheck->num_rows > 0);

if (!$hasLatColumn) {
    $db->query("ALTER TABLE rescues ADD COLUMN rider_current_lat DECIMAL(10,8) DEFAULT NULL");
}

// Check if rider_current_lng column exists
$columnCheck = $db->query("SHOW COLUMNS FROM rescues LIKE 'rider_current_lng'");
$hasLngColumn = ($columnCheck && $columnCheck->num_rows > 0);

if (!$hasLngColumn) {
    $db->query("ALTER TABLE rescues ADD COLUMN rider_current_lng DECIMAL(10,8) DEFAULT NULL");
}

// Update rider's current location
$updateStmt = $db->prepare('UPDATE rescues SET rider_current_lat = ?, rider_current_lng = ?, updated_at = NOW() WHERE id = ? AND assigned_rider_id = ?');
$updateStmt->bind_param('ddii', $latitude, $longitude, $rescueId, $riderId);
$updateStmt->execute();
$updateStmt->close();

$db->close();

echo json_encode([
    'success' => true,
    'message' => 'Rider location updated',
    'rescue_id' => $rescueId,
    'latitude' => $latitude,
    'longitude' => $longitude
]);
?>