<?php
// ============================================
// NEKIROT QUETTA - UPDATE DELIVERY STATUS
// Called when rider reaches recipient
// ============================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Ensure logs directory exists
$logDir = __DIR__ . '/../logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}
ini_set('error_log', $logDir . '/status_update_errors.log');

// ============================================
// CHECK REQUEST METHOD
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST request.'
    ]);
    exit;
}

// ============================================
// CHECK AUTHENTICATION
// ============================================
require_login();
$user = current_user();

if (!$user || $user['type'] !== 'rider') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Only riders can update delivery status.'
    ]);
    exit;
}

$riderId = intval($user['id']);

// ============================================
// GET AND VALIDATE INPUT
// ============================================
$input = json_decode(file_get_contents('php://input'), true);

// If JSON parsing fails, try POST data
if ($input === null) {
    $input = $_POST;
}

$rescueId = intval($input['rescue_id'] ?? $input['id'] ?? 0);
$newStatus = strtolower(trim($input['status'] ?? ''));

error_log("Status update request - Rescue ID: {$rescueId}, New Status: {$newStatus}, Rider ID: {$riderId}");

// Validate input
$allowedStatuses = ['delivered', 'completed', 'cancelled'];

if ($rescueId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid rescue ID provided.'
    ]);
    exit;
}

if (!in_array($newStatus, $allowedStatuses)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status value. Allowed: ' . implode(', ', $allowedStatuses)
    ]);
    exit;
}

// ============================================
// DATABASE CONNECTION
// ============================================
try {
    $db = get_db_connection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.'
    ]);
    exit;
}

// ============================================
// UPDATE RESCUE STATUS
// ============================================
try {
    // Start transaction
    $db->begin_transaction();
    
    // ============================================
    // CHECK CURRENT STATUS AND RIDER ASSIGNMENT
    // ============================================
    $checkStmt = $db->prepare(
        'SELECT id, status, assigned_rider_id, recipient_id, listing_id 
         FROM rescues 
         WHERE id = ? 
         LIMIT 1'
    );
    $checkStmt->bind_param('i', $rescueId);
    $checkStmt->execute();
    $rescue = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if (!$rescue) {
        throw new Exception('Rescue not found with ID: ' . $rescueId);
    }
    
    $currentStatus = strtolower(trim($rescue['status']));
    $assignedRiderId = intval($rescue['assigned_rider_id'] ?? 0);
    
    error_log("Current status: {$currentStatus}, Assigned Rider: {$assignedRiderId}");
    
    // Verify rider is assigned to this rescue
    if ($assignedRiderId > 0 && $assignedRiderId !== $riderId) {
        throw new Exception('You are not assigned to this rescue.');
    }
    
    // ============================================
    // VALIDATE STATUS TRANSITION
    // ============================================
    $validTransitions = [
        'in_transit' => ['delivered', 'cancelled'],
        'picked_up' => ['delivered', 'cancelled'], // Allow direct delivery if needed
        'delivered' => ['completed'],
        'completed' => [],
        'cancelled' => []
    ];
    
    if ($currentStatus !== $newStatus) {
        if (!isset($validTransitions[$currentStatus]) || 
            !in_array($newStatus, $validTransitions[$currentStatus])) {
            throw new Exception(
                "Invalid status transition from '{$currentStatus}' to '{$newStatus}'"
            );
        }
    }
    
    // ============================================
    // UPDATE RESCUE STATUS
    // ============================================
    // Check if delivered_at column exists
    $columnCheck = $db->query("SHOW COLUMNS FROM rescues LIKE 'delivered_at'");
    $hasDeliveredAt = ($columnCheck && $columnCheck->num_rows > 0);
    
    if ($hasDeliveredAt) {
        $updateStmt = $db->prepare(
            'UPDATE rescues 
             SET status = ?, 
                 delivered_at = NOW(), 
                 updated_at = NOW() 
             WHERE id = ?'
        );
        $updateStmt->bind_param('si', $newStatus, $rescueId);
    } else {
        // If delivered_at doesn't exist, just update status and updated_at
        $updateStmt = $db->prepare(
            'UPDATE rescues 
             SET status = ?, 
                 updated_at = NOW() 
             WHERE id = ?'
        );
        $updateStmt->bind_param('si', $newStatus, $rescueId);
    }
    
    $updateStmt->execute();
    
    if ($updateStmt->affected_rows === 0 && $currentStatus !== $newStatus) {
        throw new Exception('Failed to update rescue status. No rows affected.');
    }
    $updateStmt->close();
    
    error_log("Rescue {$rescueId} status updated to {$newStatus}");
    
    // ============================================
    // UPDATE LISTING STATUS
    // ============================================
    if ($newStatus === 'delivered' || $newStatus === 'completed') {
        $listingStatus = 'completed';
        $updateListing = $db->prepare(
            'UPDATE listings l 
             JOIN rescues r ON l.id = r.listing_id 
             SET l.status = ?, l.updated_at = NOW() 
             WHERE r.id = ?'
        );
        $updateListing->bind_param('si', $listingStatus, $rescueId);
        $updateListing->execute();
        $updateListing->close();
        
        error_log("Listing status updated for rescue {$rescueId}");
    }
    
    // ============================================
    // UPDATE SCORES
    // ============================================
    if ($newStatus === 'delivered' || $newStatus === 'completed') {
        $scoreColumns = [];
        $scoreColumnResult = $db->query("SHOW COLUMNS FROM users WHERE Field IN ('neki_score', 'total_deliveries', 'total_meals_received', 'total_meals_donated')");
        if ($scoreColumnResult) {
            while ($scoreColumn = $scoreColumnResult->fetch_assoc()) {
                $scoreColumns[$scoreColumn['Field']] = true;
            }
        }

        // Update rider score
        if ($assignedRiderId > 0 && isset($scoreColumns['neki_score'], $scoreColumns['total_deliveries'])) {
            $updateRider = $db->prepare(
                'UPDATE users 
                 SET neki_score = neki_score + 40, 
                     total_deliveries = COALESCE(total_deliveries, 0) + 1 
                 WHERE id = ?'
            );
            $updateRider->bind_param('i', $assignedRiderId);
            $updateRider->execute();
            $updateRider->close();
            
            error_log("Rider {$assignedRiderId} score updated");
        }
        
        // Update recipient score
        if (!empty($rescue['recipient_id']) && isset($scoreColumns['neki_score'], $scoreColumns['total_meals_received'])) {
            $recipientId = intval($rescue['recipient_id']);
            
            $updateRecipient = $db->prepare(
                'UPDATE users 
                 SET neki_score = neki_score + 15, 
                     total_meals_received = COALESCE(total_meals_received, 0) + 1 
                 WHERE id = ?'
            );
            $updateRecipient->bind_param('i', $recipientId);
            $updateRecipient->execute();
            $updateRecipient->close();
            
            error_log("Recipient {$recipientId} score updated");
        }
        
        // Update donor score
        if (!empty($rescue['listing_id']) && isset($scoreColumns['neki_score'], $scoreColumns['total_meals_donated'])) {
            $listingId = intval($rescue['listing_id']);
            
            $updateDonor = $db->prepare(
                'UPDATE users u 
                 JOIN listings l ON l.donor_id = u.id 
                 SET u.neki_score = u.neki_score + 50, 
                     u.total_meals_donated = COALESCE(u.total_meals_donated, 0) + 1 
                 WHERE l.id = ?'
            );
            $updateDonor->bind_param('i', $listingId);
            $updateDonor->execute();
            $updateDonor->close();
            
            error_log("Donor score updated for listing {$listingId}");
        }
    }
    
    // ============================================
    // INSERT TRACKING RECORD
    // ============================================
    if ($newStatus === 'delivered') {
        // Get recipient location for tracking
        $trackStmt = $db->prepare(
            'SELECT latitude, longitude 
             FROM users 
             WHERE id = ? 
             LIMIT 1'
        );
        $recipientId = intval($rescue['recipient_id'] ?? 0);
        $trackStmt->bind_param('i', $recipientId);
        $trackStmt->execute();
        $recipient = $trackStmt->get_result()->fetch_assoc();
        $trackStmt->close();
        
        $trackingLat = floatval($recipient['latitude'] ?? 0);
        $trackingLng = floatval($recipient['longitude'] ?? 0);
        
        // Insert tracking record if we have coordinates
        if ($trackingLat !== 0 && $trackingLng !== 0) {
            $insertTrackStmt = $db->prepare(
                'INSERT INTO tracking (rescue_id, rider_id, latitude, longitude, tracked_at) 
                 VALUES (?, ?, ?, ?, NOW())'
            );
            $insertTrackStmt->bind_param('iidd', $rescueId, $riderId, $trackingLat, $trackingLng);
            $insertTrackStmt->execute();
            $insertTrackStmt->close();
            
            error_log("Tracking record inserted for rescue {$rescueId}");
        }
    }
    
    // ============================================
    // CLEAR SIMULATION SESSION
    // ============================================
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    unset(
        $_SESSION['simulation_step_' . $rescueId],
        $_SESSION['simulation_active_' . $rescueId],
        $_SESSION['simulation_phase_' . $rescueId]
    );
    
    // ============================================
    // COMMIT TRANSACTION
    // ============================================
    $db->commit();
    
    error_log("✅ Rescue {$rescueId} successfully updated from '{$currentStatus}' to '{$newStatus}'");
    
    // ============================================
    // RETURN SUCCESS RESPONSE
    // ============================================
    echo json_encode([
        'success' => true,
        'message' => 'Delivery status updated to ' . $newStatus,
        'rescue_id' => $rescueId,
        'data' => [
            'status' => $newStatus,
            'previous_status' => $currentStatus,
            'updated_at' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $ex) {
    // Rollback transaction on error
    if ($db && $db->ping()) {
        $db->rollback();
    }
    
    error_log("❌ Status update error: " . $ex->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $ex->getMessage(),
        'rescue_id' => $rescueId,
        'status' => $newStatus
    ]);
    
} finally {
    if ($db && $db->ping()) {
        $db->close();
    }
}
?>