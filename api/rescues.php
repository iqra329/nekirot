<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db = get_db_connection();

// ============================================
// GET - Fetch all rescues
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('
        SELECT r.id, r.recipient_id, r.title, r.description, r.contact_phone, 
               r.latitude, r.longitude, r.status, r.assigned_rider_id, r.created_at,
               u.name AS recipient_name
        FROM rescues r
        LEFT JOIN users u ON u.id = r.recipient_id
        ORDER BY r.created_at DESC
    ');
    $stmt->execute();
    $result = $stmt->get_result();
    $rescues = $result->fetch_all(MYSQLI_ASSOC);
    send_json(['success' => true, 'rescues' => $rescues]);
}

// ============================================
// POST - Handle both Create and Delete via _method
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    
    if (!$body) {
        send_json(['error' => 'Invalid request body'], 400);
    }
    
    // ============================================
    // DELETE via POST with _method=DELETE
    // ============================================
    if (isset($body['_method']) && $body['_method'] === 'DELETE') {
        $rescue_id = intval($body['rescue_id'] ?? 0);
        
        if (!$rescue_id) {
            send_json(['error' => 'Missing rescue_id'], 400);
        }
        
        // Get current user from session (check if session already exists)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $user = current_user();
        
        if (!$user) {
            send_json(['error' => 'Not logged in'], 401);
        }
        
        if ($user['type'] !== 'recipient') {
            send_json(['error' => 'Only recipients can cancel rescues'], 403);
        }
        
        $recipient_id = $user['id'];
        
        // Check if rescue exists and belongs to this recipient
        $stmt = $db->prepare('SELECT id, recipient_id, status, listing_id FROM rescues WHERE id = ? AND recipient_id = ?');
        $stmt->bind_param('ii', $rescue_id, $recipient_id);
        $stmt->execute();
        $rescue = $stmt->get_result()->fetch_assoc();
        
        if (!$rescue) {
            send_json(['error' => 'Rescue not found or you don\'t have permission'], 404);
        }
        
        // DEBUG: Log the status
        error_log("Rescue ID: $rescue_id, Status: " . ($rescue['status'] ?? 'NULL'));
        
        // Check if rescue can be cancelled (only pending or accepted)
        $allowedStatuses = ['pending', 'accepted'];
        $currentStatus = $rescue['status'] ?? '';
        
        if (!in_array($currentStatus, $allowedStatuses)) {
            $messages = [
                'picked_up' => 'Cannot cancel. Rider has already picked up the food.',
                'in_transit' => 'Cannot cancel. Food is already on the way to you.',
                'delivered' => 'Cannot cancel. Food has already been delivered.',
                'cancelled' => 'This rescue is already cancelled.'
            ];
            
            // If status is empty or null, show a specific message
            if (empty($currentStatus)) {
                $errorMsg = 'Rescue status is empty. Please contact support.';
            } else {
                $errorMsg = $messages[$currentStatus] ?? 'Rescue cannot be cancelled at this stage. Current status: ' . $currentStatus;
            }
            send_json(['error' => $errorMsg], 400);
        }
        
        // Begin transaction
        $db->begin_transaction();
        
        try {
            // Update rescue status to cancelled
            $stmt = $db->prepare('UPDATE rescues SET status = "cancelled", assigned_rider_id = NULL, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $rescue_id);
            $stmt->execute();
            
            // Update listing status back to published
            if ($rescue['listing_id']) {
                $stmt = $db->prepare('UPDATE listings SET status = "published" WHERE id = ?');
                $stmt->bind_param('i', $rescue['listing_id']);
                $stmt->execute();
            }
            
            $db->commit();
            send_json(['success' => true, 'message' => 'Rescue cancelled successfully']);
            
        } catch (Exception $e) {
            $db->rollback();
            send_json(['error' => 'Failed to cancel rescue: ' . $e->getMessage()], 500);
        }
    }
    
    // ============================================
    // CREATE - Regular POST (Create rescue)
    // ============================================
    $recipient_id = intval($body['recipient_id'] ?? 0);
    $listing_id = intval($body['listing_id'] ?? 0);
    $title = trim($body['title'] ?? '');
    $description = trim($body['description'] ?? '');
    $contact_phone = trim($body['contact_phone'] ?? '');
    $latitude = floatval($body['latitude'] ?? 0);
    $longitude = floatval($body['longitude'] ?? 0);
    
    // Validate required fields
    if (!$recipient_id || !$listing_id || !$title || !$description || !$contact_phone || !$latitude || !$longitude) {
        send_json([
            'error' => 'Missing fields',
            'required' => ['recipient_id', 'listing_id', 'title', 'description', 'contact_phone', 'latitude', 'longitude'],
            'received' => [
                'recipient_id' => $recipient_id,
                'listing_id' => $listing_id,
                'title' => $title,
                'description' => $description,
                'contact_phone' => $contact_phone,
                'latitude' => $latitude,
                'longitude' => $longitude
            ]
        ], 400);
    }
    
    // Validate Quetta bounds
    if (!validate_quetta_bounds($latitude, $longitude)) {
        send_json(['error' => 'Location must be within Quetta city bounds (30.13-30.25 lat, 66.92-67.05 lng)'], 400);
    }
    
    // Check if recipient exists
    $stmt = $db->prepare('SELECT id, name FROM users WHERE id = ? AND user_type = "recipient" AND is_active = 1');
    $stmt->bind_param('i', $recipient_id);
    $stmt->execute();
    $recipient = $stmt->get_result()->fetch_assoc();
    
    if (!$recipient) {
        send_json(['error' => 'Recipient not found or inactive'], 404);
    }
    
    // Check if listing exists and is published
    $stmt = $db->prepare('SELECT id, donor_id, status FROM listings WHERE id = ? AND status = "published"');
    $stmt->bind_param('i', $listing_id);
    $stmt->execute();
    $listing = $stmt->get_result()->fetch_assoc();
    
    if (!$listing) {
        send_json(['error' => 'Listing not available'], 404);
    }
    
    // Create rescue with listing_id - status is 'pending'
    $status = 'pending';
    $stmt = $db->prepare('
        INSERT INTO rescues (listing_id, recipient_id, title, description, contact_phone, latitude, longitude, status, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');
    $stmt->bind_param('iisssdds', $listing_id, $recipient_id, $title, $description, $contact_phone, $latitude, $longitude, $status);
    
    if ($stmt->execute()) {
        $rescue_id = $db->insert_id;
        
        // Update listing status to 'matched'
        $stmt = $db->prepare('UPDATE listings SET status = "matched" WHERE id = ?');
        $stmt->bind_param('i', $listing_id);
        $stmt->execute();
        
        // Notify nearby riders
        try {
            $riders = getNearbyRiders($latitude, $longitude, 10);
            foreach ($riders as $rider) {
                error_log("Notifying rider {$rider['id']} about rescue #$rescue_id");
            }
        } catch (Exception $e) {
            error_log('Notification error: ' . $e->getMessage());
        }
        
        send_json([
            'success' => true,
            'id' => $rescue_id,
            'message' => 'Rescue request created successfully',
            'recipient' => $recipient['name']
        ], 201);
    }
    
    send_json(['error' => 'Failed to create rescue request: ' . $db->error], 500);
}

// ============================================
// PUT - Update a rescue
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body = get_json_body();
    
    if (!$body) {
        send_json(['error' => 'Invalid request body'], 400);
    }
    
    $rescue_id = intval($body['rescue_id'] ?? 0);
    $status = trim($body['status'] ?? '');
    $rider_id = intval($body['rider_id'] ?? 0);
    
    if (!$rescue_id) {
        send_json(['error' => 'Missing rescue_id'], 400);
    }
    
    // Build update query dynamically
    $updates = [];
    $params = [];
    $types = '';
    
    if ($status) {
        $updates[] = 'status = ?';
        $params[] = $status;
        $types .= 's';
    }
    
    if ($rider_id) {
        $updates[] = 'assigned_rider_id = ?';
        $params[] = $rider_id;
        $types .= 'i';
    }
    
    if (empty($updates)) {
        send_json(['error' => 'No fields to update'], 400);
    }
    
    $updates[] = 'updated_at = NOW()';
    $sql = 'UPDATE rescues SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $params[] = $rescue_id;
    $types .= 'i';
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        send_json(['success' => true, 'message' => 'Rescue updated successfully']);
    }
    
    send_json(['error' => 'Failed to update rescue: ' . $db->error], 500);
}

// ============================================
// DELETE - Cancel a rescue (Direct DELETE method)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body = get_json_body();
    
    if (!$body) {
        send_json(['error' => 'Invalid request body'], 400);
    }
    
    $rescue_id = intval($body['rescue_id'] ?? 0);
    
    if (!$rescue_id) {
        send_json(['error' => 'Missing rescue_id'], 400);
    }
    
    $stmt = $db->prepare('UPDATE rescues SET status = "cancelled", updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $rescue_id);
    
    if ($stmt->execute()) {
        send_json(['success' => true, 'message' => 'Rescue cancelled successfully']);
    }
    
    send_json(['error' => 'Failed to cancel rescue: ' . $db->error], 500);
}

// ============================================
// PATCH - Update rescue status (RIDER FLOW)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $body = get_json_body();
    
    if (!$body) {
        send_json(['error' => 'Invalid request body'], 400);
    }
    
    $rescue_id = intval($body['rescue_id'] ?? 0);
    $new_status = trim($body['status'] ?? '');
    $latitude = floatval($body['latitude'] ?? 0);
    $longitude = floatval($body['longitude'] ?? 0);
    
    if (!$rescue_id || !$new_status) {
        send_json(['error' => 'Missing rescue_id or status'], 400);
    }
    
    // Get current user (check if session already exists)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $user = current_user();
    
    if (!$user) {
        send_json(['error' => 'Unauthorized'], 401);
    }
    
    // Get rescue details
    $stmt = $db->prepare('SELECT * FROM rescues WHERE id = ?');
    $stmt->bind_param('i', $rescue_id);
    $stmt->execute();
    $rescue = $stmt->get_result()->fetch_assoc();
    
    if (!$rescue) {
        send_json(['error' => 'Rescue not found'], 404);
    }
    
    // Check authorization
    $isRider = ($user['type'] === 'rider' && $rescue['assigned_rider_id'] == $user['id']);
    $isRecipient = ($user['type'] === 'recipient' && $rescue['recipient_id'] == $user['id']);
    
    // Recipient can only cancel
    if ($new_status === 'cancelled' && $isRecipient) {
        // Allow recipient to cancel
    } elseif (!$isRider && !$isRecipient) {
        send_json(['error' => 'Only the assigned rider can update status'], 403);
    }
    
    // ============================================
    // VALID STATUS TRANSITIONS
    // ============================================
    $transitions = [
        'pending' => ['accepted', 'cancelled'],
        'accepted' => ['rider_assigned', 'cancelled'],
        'rider_assigned' => ['rider_en_route_pickup', 'cancelled'],
        'rider_en_route_pickup' => ['pickup_confirmed', 'cancelled'],
        'pickup_confirmed' => ['in_transit', 'cancelled'],
        'in_transit' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => []
    ];
    
    // Check if transition is allowed
    if (!isset($transitions[$rescue['status']]) || !in_array($new_status, $transitions[$rescue['status']])) {
        send_json([
            'error' => 'Invalid status transition from "' . $rescue['status'] . '" to "' . $new_status . '"',
            'allowed' => $transitions[$rescue['status']] ?? []
        ], 400);
    }
    
    // Check permissions for specific transitions
    if ($new_status === 'rider_assigned' && !$isRider) {
        send_json(['error' => 'Only a rider can accept assignment'], 403);
    }
    if (in_array($new_status, ['rider_en_route_pickup', 'pickup_confirmed', 'in_transit', 'delivered']) && !$isRider) {
        send_json(['error' => 'Only the assigned rider can update delivery status'], 403);
    }
    
    // ============================================
    // BEGIN TRANSACTION
    // ============================================
    $db->begin_transaction();
    
    try {
        // Update rescue status
        $stmt = $db->prepare('UPDATE rescues SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $new_status, $rescue_id);
        $stmt->execute();
        
        // ============================================
        // IF DELIVERED - UPDATE SCORES
        // ============================================
        if ($new_status === 'delivered') {
            // Get donor_id from rescue or listing
            $donor_id = null;
            if (isset($rescue['donor_id']) && $rescue['donor_id']) {
                $donor_id = $rescue['donor_id'];
            } elseif (isset($rescue['listing_id']) && $rescue['listing_id']) {
                $stmt2 = $db->prepare('SELECT donor_id FROM listings WHERE id = ?');
                $stmt2->bind_param('i', $rescue['listing_id']);
                $stmt2->execute();
                $listing = $stmt2->get_result()->fetch_assoc();
                if ($listing) {
                    $donor_id = $listing['donor_id'];
                }
            }
            
            // Update Donor Score (+10)
            if ($donor_id) {
                $db->query("UPDATE users SET neki_score = neki_score + 10 WHERE id = $donor_id");
            }
            // Update Recipient Score (+5)
            $db->query("UPDATE users SET neki_score = neki_score + 5 WHERE id = {$rescue['recipient_id']}");
            // Update Rider Score (+15)
            if ($rescue['assigned_rider_id']) {
                $db->query("UPDATE users SET neki_score = neki_score + 15 WHERE id = {$rescue['assigned_rider_id']}");
            }
            
            // Update listing status if listing_id exists
            if (isset($rescue['listing_id']) && $rescue['listing_id']) {
                $db->query("UPDATE listings SET status = 'completed' WHERE id = {$rescue['listing_id']}");
            }
        }
        
        // ============================================
        // IF RIDER IS MOVING - ADD TRACKING
        // ============================================
        if (in_array($new_status, ['rider_en_route_pickup', 'in_transit']) && $latitude && $longitude) {
            $rider_id = $rescue['assigned_rider_id'] ?? $user['id'];
            $stmt = $db->prepare('
                INSERT INTO tracking (rescue_id, rider_id, latitude, longitude, status, tracked_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ');
            $stmt->bind_param('iidds', $rescue_id, $rider_id, $latitude, $longitude, $new_status);
            $stmt->execute();
        }
        
        $db->commit();
        
        // ============================================
        // SUCCESS RESPONSE
        // ============================================
        send_json([
            'success' => true,
            'status' => $new_status,
            'message' => 'Status updated successfully',
            'previous_status' => $rescue['status']
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        send_json(['error' => 'Failed to update status: ' . $e->getMessage()], 500);
    }
}

// ============================================
// Method not allowed
// ============================================
send_json(['error' => 'Method not allowed'], 405);
?>