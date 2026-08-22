<?php
// ============================================
// GET RIDER LOCATION API
// Returns rider's current location during delivery
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

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = get_db_connection();

$rescueId = intval($_GET['rescue_id'] ?? 0);

if ($rescueId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid rescue ID']);
    $db->close();
    exit;
}

// Get rescue status and rider's current location
$stmt = $db->prepare('SELECT id, status, assigned_rider_id, rider_current_lat, rider_current_lng, updated_at FROM rescues WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $rescueId);
$stmt->execute();
$rescue = $stmt->get_result()->fetch_assoc();

$db->close();

if (!$rescue) {
    echo json_encode(['success' => false, 'message' => 'Rescue not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'rescue_id' => $rescueId,
    'status' => $rescue['status'],
    'assigned_rider_id' => intval($rescue['assigned_rider_id'] ?? 0),
    'rider_latitude' => floatval($rescue['rider_current_lat'] ?? 0),
    'rider_longitude' => floatval($rescue['rider_current_lng'] ?? 0),
    'updated_at' => $rescue['updated_at']
]);
?>