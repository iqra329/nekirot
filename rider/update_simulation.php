<?php
// ============================================
// NEKIROT QUETTA - UPDATE SIMULATION
// Uses DATABASE to track simulation progress
// Guaranteed to work across multiple AJAX requests
// ============================================

header('Content-Type: application/json');
include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gps_simulation.php';

$user = current_user();
if (!$user || empty($user['id']) || $user['type'] !== 'rider') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Rider login required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Use POST.']);
    exit;
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
    echo json_encode(['success' => false, 'message' => 'Missing or invalid rescue_id.']);
    exit;
}

$db = get_db_connection();

// Get rescue details
$stmt = $db->prepare('SELECT assigned_rider_id, status, listing_id, recipient_id, simulation_step FROM rescues WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $rescueId);
$stmt->execute();
$rescue = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rescue) {
    $db->close();
    echo json_encode(['success' => false, 'message' => 'Rescue not found.']);
    exit;
}

if (intval($rescue['assigned_rider_id']) !== intval($user['id'])) {
    $db->close();
    echo json_encode(['success' => false, 'message' => 'You are not assigned to this rescue.']);
    exit;
}

// If already delivered
if ($rescue['status'] === 'delivered') {
    $db->close();
    echo json_encode([
        'success' => true,
        'status' => 'delivered',
        'simulation_active' => false,
        'progress' => 100,
        'message' => 'Already delivered'
    ]);
    exit;
}

// If cancelled
if ($rescue['status'] === 'cancelled') {
    $db->close();
    echo json_encode([
        'success' => true,
        'status' => 'cancelled',
        'simulation_active' => false,
        'message' => 'Rescue cancelled'
    ]);
    exit;
}

// If not in_transit or picked_up, return current status
if ($rescue['status'] !== 'in_transit' && $rescue['status'] !== 'picked_up') {
    $db->close();
    echo json_encode([
        'success' => true,
        'status' => $rescue['status'],
        'simulation_active' => false,
        'message' => 'Status: ' . $rescue['status']
    ]);
    exit;
}

// ============================================
// GET SIMULATION STEP FROM DATABASE
// ============================================
$simulationStep = intval($rescue['simulation_step'] ?? 0);

// ============================================
// RUN SIMULATION STEP
// ============================================
$result = simulateRiderMovement($rescueId);

// Check if simulation returned delivered
$isDelivered = false;

if ($result && isset($result['status']) && $result['status'] === 'delivered') {
    $isDelivered = true;
} elseif ($result && isset($result['progress']) && floatval($result['progress']) >= 100) {
    $isDelivered = true;
}

// ============================================
// INCREMENT STEP IN DATABASE
// ============================================
$simulationStep++;
$maxSteps = 15; // Delivery completes after 15 polls (30 seconds)

// Force delivery if step exceeds max
if ($simulationStep >= $maxSteps) {
    $isDelivered = true;
}

// ============================================
// IF DELIVERED - UPDATE EVERYTHING
// ============================================
if ($isDelivered) {
    $db->begin_transaction();
    
    try {
        // Update rescue to delivered
        $updateRescue = $db->prepare('UPDATE rescues SET status = ?, delivered_at = NOW(), updated_at = NOW(), simulation_step = 0 WHERE id = ?');
        $deliveredStatus = 'delivered';
        $updateRescue->bind_param('si', $deliveredStatus, $rescueId);
        $updateRescue->execute();
        
        // Update listing status
        if (!empty($rescue['listing_id'])) {
            $updateListing = $db->prepare('UPDATE listings SET status = ?, updated_at = NOW() WHERE id = ?');
            $updateListing->bind_param('si', $deliveredStatus, $rescue['listing_id']);
            $updateListing->execute();
        }
        
        // Update rider stats
        $updateRider = $db->prepare('UPDATE users SET neki_score = neki_score + 40, total_deliveries = total_deliveries + 1 WHERE id = ?');
        $updateRider->bind_param('i', $user['id']);
        $updateRider->execute();
        
        // Update recipient stats
        if (!empty($rescue['recipient_id'])) {
            $updateRecipient = $db->prepare('UPDATE users SET neki_score = neki_score + 15, total_meals_received = total_meals_received + 1 WHERE id = ?');
            $updateRecipient->bind_param('i', $rescue['recipient_id']);
            $updateRecipient->execute();
        }
        
        $db->commit();
        $db->close();
        
        echo json_encode([
            'success' => true,
            'status' => 'delivered',
            'simulation_active' => false,
            'progress' => 100,
            'eta' => 0,
            'message' => '✅ Delivery completed!'
        ]);
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        $db->close();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// ============================================
// UPDATE STEP IN DATABASE
// ============================================
$updateStep = $db->prepare('UPDATE rescues SET simulation_step = ? WHERE id = ?');
$updateStep->bind_param('ii', $simulationStep, $rescueId);
$updateStep->execute();

$db->close();

// Return ongoing status
echo json_encode([
    'success' => true,
    'status' => $rescue['status'],
    'latitude' => $result['latitude'] ?? null,
    'longitude' => $result['longitude'] ?? null,
    'progress' => min(100, ($simulationStep / $maxSteps) * 100),
    'eta' => max(0, ($maxSteps - $simulationStep) * 2),
    'simulation_active' => true,
    'simulation_step' => $simulationStep,
    'message' => 'Moving... Step ' . $simulationStep . ' of ' . $maxSteps
]);