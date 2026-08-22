<?php
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

$user = current_user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($user['type'] !== 'rider') {
    http_response_code(403);
    echo json_encode(['error' => 'Only riders can access tracking']);
    exit;
}

$db = get_db_connection();

// ============================================
// GET - Fetch tracking history
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rescue_id = intval($_GET['rescue_id'] ?? 0);
    
    if (!$rescue_id) {
        http_response_code(400);
        echo json_encode(['error' => 'rescue_id is required']);
        exit;
    }
    
    // Verify rider is assigned to this rescue
    $stmt = $db->prepare('SELECT assigned_rider_id FROM rescues WHERE id = ?');
    $stmt->bind_param('i', $rescue_id);
    $stmt->execute();
    $rescue = $stmt->get_result()->fetch_assoc();
    
    if (!$rescue || $rescue['assigned_rider_id'] != $user['id']) {
        $db->close();
        http_response_code(403);
        echo json_encode(['error' => 'You are not assigned to this rescue']);
        exit;
    }
    
    // Get tracking history
    $stmt = $db->prepare('
        SELECT id, rider_id, latitude, longitude, status, tracked_at 
        FROM tracking 
        WHERE rescue_id = ? 
        ORDER BY tracked_at ASC
    ');
    $stmt->bind_param('i', $rescue_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = $result->fetch_all(MYSQLI_ASSOC);
    
    // Get latest tracking point
    $stmt = $db->prepare('
        SELECT latitude, longitude, status, tracked_at 
        FROM tracking 
        WHERE rescue_id = ? 
        ORDER BY tracked_at DESC 
        LIMIT 1
    ');
    $stmt->bind_param('i', $rescue_id);
    $stmt->execute();
    $latest = $stmt->get_result()->fetch_assoc();
    
    $db->close();
    
    echo json_encode([
        'success' => true,
        'tracking' => $history,
        'latest' => $latest,
        'count' => count($history)
    ]);
    exit;
}

// ============================================
// POST - Update rider location
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body']);
        exit;
    }
    
    $rescue_id = intval($data['rescue_id'] ?? 0);
    $latitude = floatval($data['latitude'] ?? 0);
    $longitude = floatval($data['longitude'] ?? 0);
    $status = $data['status'] ?? 'in_transit';
    
    if (!$rescue_id || !$latitude || !$longitude) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: rescue_id, latitude, longitude']);
        exit;
    }
    
    // Verify rider is assigned to this rescue
    $stmt = $db->prepare('SELECT assigned_rider_id, status FROM rescues WHERE id = ?');
    $stmt->bind_param('i', $rescue_id);
    $stmt->execute();
    $rescue = $stmt->get_result()->fetch_assoc();
    
    if (!$rescue) {
        $db->close();
        http_response_code(404);
        echo json_encode(['error' => 'Rescue not found']);
        exit;
    }
    
    if ($rescue['assigned_rider_id'] != $user['id']) {
        $db->close();
        http_response_code(403);
        echo json_encode(['error' => 'You are not assigned to this rescue']);
        exit;
    }
    
    // Insert tracking data
    $stmt = $db->prepare('
        INSERT INTO tracking (rescue_id, rider_id, latitude, longitude, status, tracked_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ');
    $stmt->bind_param('iidds', $rescue_id, $user['id'], $latitude, $longitude, $status);
    
    if ($stmt->execute()) {
        // Update rider current location in users table
        $db->query("UPDATE users SET latitude = $latitude, longitude = $longitude WHERE id = {$user['id']}");
        
        echo json_encode([
            'success' => true,
            'message' => 'Location updated successfully',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'status' => $status,
            'rescue_status' => $rescue['status'],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update location: ' . $db->error]);
    }
    
    $db->close();
    exit;
}

// ============================================
// Method not allowed
// ============================================
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>