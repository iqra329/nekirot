<?php

header('Content-Type: application/json');

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// =====================================================
// AUTHENTICATION
// =====================================================

$user = current_user();

if (!$user) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized'
    ]);
    exit;
}

if ($user['type'] !== 'rider') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Only riders can accept rescues'
    ]);
    exit;
}

// =====================================================
// GET REQUEST DATA
// =====================================================

$data = json_decode(file_get_contents('php://input'), true);

$rescue_id = intval($data['rescue_id'] ?? 0);

if ($rescue_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing or invalid rescue_id'
    ]);
    exit;
}

// =====================================================
// DATABASE
// =====================================================

$db = get_db_connection();

// =====================================================
// GET RIDER INFORMATION (including phone for notifications)
// =====================================================

$stmt = $db->prepare("
    SELECT id, name, phone, latitude, longitude
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $user['id']);
$stmt->execute();

$rider = $stmt->get_result()->fetch_assoc();

if (!$rider) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Rider account not found'
    ]);
    exit;
}

$rider_lat = floatval($rider['latitude'] ?? 0);
$rider_lng = floatval($rider['longitude'] ?? 0);

// =====================================================
// CHECK RIDER LOCATION
// =====================================================

if ($rider_lat == 0 || $rider_lng == 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Your location is not set. Please update your GPS location first.',
        'user_id' => $user['id']
    ]);
    exit;
}

// =====================================================
// GET RESCUE WITH FULL DETAILS
// =====================================================

$stmt = $db->prepare("
    SELECT
        r.*,
        u.name AS recipient_name,
        u.phone AS recipient_phone,
        u.latitude AS recipient_lat,
        u.longitude AS recipient_lng
    FROM rescues r
    LEFT JOIN users u ON u.id = r.recipient_id
    WHERE r.id = ?
    LIMIT 1
");

$stmt->bind_param('i', $rescue_id);
$stmt->execute();

$rescue = $stmt->get_result()->fetch_assoc();

if (!$rescue) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Rescue not found'
    ]);
    exit;
}

// =====================================================
// CHECK CURRENT STATUS
// =====================================================

$current_status = strtolower(trim($rescue['status'] ?? ''));
$assigned_rider_id = intval($rescue['assigned_rider_id'] ?? 0);

// Already assigned to another rider
if ($assigned_rider_id > 0 && $assigned_rider_id != intval($user['id'])) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => 'This rescue has already been assigned to another rider.',
        'status' => $current_status,
        'assigned_rider_id' => $assigned_rider_id
    ]);
    exit;
}

// Already assigned to this rider
if ($assigned_rider_id == intval($user['id'])) {
    echo json_encode([
        'success' => true,
        'already_assigned' => true,
        'rescue_id' => $rescue_id,
        'status' => $current_status,
        'message' => 'This rescue is already assigned to you.'
    ]);
    exit;
}

// Rescue must be accepted before rider can take it
if ($current_status !== 'accepted') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'This rescue cannot be accepted in its current state.',
        'current_status' => $current_status,
        'required_status' => 'accepted'
    ]);
    exit;
}

// =====================================================
// CHECK PICKUP LOCATION
// =====================================================

$rescue_lat = floatval($rescue['latitude'] ?? 0);
$rescue_lng = floatval($rescue['longitude'] ?? 0);

if ($rescue_lat == 0 || $rescue_lng == 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Rescue pickup location is not available.'
    ]);
    exit;
}

// =====================================================
// CALCULATE DISTANCE
// =====================================================

$distance = calculate_distance_km(
    $rider_lat,
    $rider_lng,
    $rescue_lat,
    $rescue_lng
);

$distance = floatval($distance);

// =====================================================
// DISTANCE LIMIT (10km)
// =====================================================

if ($distance > 10) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'You are too far from the pickup location.',
        'distance' => round($distance, 1),
        'maximum_distance' => 10,
        'your_location' => [
            'lat' => $rider_lat,
            'lng' => $rider_lng
        ],
        'pickup_location' => [
            'lat' => $rescue_lat,
            'lng' => $rescue_lng
        ]
    ]);
    exit;
}

// =====================================================
// START TRANSACTION
// =====================================================

$db->begin_transaction();

try {
    // =================================================
    // ASSIGN RIDER TO RESCUE
    // =================================================
    $new_status = 'rider_assigned';

    $stmt = $db->prepare("
        UPDATE rescues
        SET
            assigned_rider_id = ?,
            status = ?,
            updated_at = NOW()
        WHERE
            id = ?
            AND status = 'accepted'
            AND (assigned_rider_id IS NULL OR assigned_rider_id = 0)
    ");

    $stmt->bind_param('isi', $user['id'], $new_status, $rescue_id);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new Exception('The rescue was already accepted by another rider or its status changed.');
    }

    // =================================================
    // CREATE INITIAL TRACKING RECORD
    // =================================================
    $tracking_status = 'rider_assigned';

    $stmt = $db->prepare("
        INSERT INTO tracking
        (rescue_id, rider_id, latitude, longitude, status, tracked_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $stmt->bind_param('iidds', $rescue_id, $user['id'], $rider_lat, $rider_lng, $tracking_status);
    $stmt->execute();

    // =================================================
    // UPDATE LISTING STATUS (if listing_id exists)
    // =================================================
    if (isset($rescue['listing_id']) && $rescue['listing_id']) {
        $db->query("UPDATE listings SET status = 'matched' WHERE id = {$rescue['listing_id']}");
    }

    // =================================================
    // COMMIT TRANSACTION
    // =================================================
    $db->commit();

    // =================================================
    // SUCCESS RESPONSE
    // =================================================
    echo json_encode([
        'success' => true,
        'rescue_id' => $rescue_id,
        'status' => $new_status,
        'assigned_rider_id' => intval($user['id']),
        'rider_name' => $rider['name'],
        'message' => '✅ Rescue accepted successfully! Head to the pickup location.',
        'pickup_location' => [
            'lat' => $rescue_lat,
            'lng' => $rescue_lng
        ],
        'dropoff_location' => [
            'lat' => floatval($rescue['recipient_lat'] ?? 0),
            'lng' => floatval($rescue['recipient_lng'] ?? 0)
        ],
        'recipient' => $rescue['recipient_name'] ?? 'Unknown',
        'recipient_phone' => $rescue['recipient_phone'] ?? '',
        'distance' => round($distance, 1),
        'estimated_time' => round(($distance / 20) * 60) . ' minutes'
    ]);

} catch (Exception $e) {
    // =================================================
    // ROLLBACK ON ERROR
    // =================================================
    $db->rollback();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to accept rescue: ' . $e->getMessage()
    ]);
}

// =====================================================
// CLOSE DATABASE
// =====================================================

$db->close();
?>