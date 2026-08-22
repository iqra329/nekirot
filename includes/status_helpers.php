<?php
// includes/status_helpers.php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Get the current status of a rescue.
 * @param int $rescueId
 * @return string|null
 */
function getRescueStatus($rescueId) {
    $db = get_db_connection();
    $stmt = $db->prepare('SELECT status FROM rescues WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $rescueId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $db->close();
    return $row['status'] ?? null;
}

/**
 * Validate allowed status transitions.
 * @param string $currentStatus
 * @param string $newStatus
 * @return bool
 */
function canTransitionTo($currentStatus, $newStatus) {
    $flow = ['pending', 'accepted', 'picked_up', 'in_transit', 'delivered'];
    // cancelled allowed from any
    if ($newStatus === 'cancelled') return true;
    if ($currentStatus === $newStatus) return false;
    $currentIndex = array_search($currentStatus, $flow, true);
    $newIndex = array_search($newStatus, $flow, true);
    if ($currentIndex === false || $newIndex === false) return false;
    // only allow forward progression by one or more steps
    return $newIndex > $currentIndex;
}

/**
 * Update rescue status and notify stakeholders.
 * Returns array with success and details.
 */
function updateRescueStatus($rescueId, $newStatus) {
    $oldStatus = getRescueStatus($rescueId);
    if ($oldStatus === null) {
        return ['success' => false, 'error' => 'Rescue not found'];
    }
    if (!canTransitionTo($oldStatus, $newStatus)) {
        return ['success' => false, 'error' => "Invalid transition from $oldStatus to $newStatus"];
    }

    $db = get_db_connection();
    $stmt = $db->prepare('UPDATE rescues SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('si', $newStatus, $rescueId);
    $ok = $stmt->execute();
    $db->close();

    if ($ok) {
        // send notifications async-friendly (best-effort)
        try {
            sendStatusNotification($rescueId, $oldStatus, $newStatus);
        } catch (Exception $e) {
            error_log('Notification failed: ' . $e->getMessage());
        }
        return ['success' => true, 'old' => $oldStatus, 'new' => $newStatus];
    }
    return ['success' => false, 'error' => 'Database update failed'];
}

/**
 * Notify donor and recipient about a status change.
 */
function sendStatusNotification($rescueId, $oldStatus, $newStatus) {
    $db = get_db_connection();
    $stmt = $db->prepare(
        'SELECT r.id, r.listing_id, r.recipient_id, l.donor_id, u_rec.name AS recipient_name, u_rec.phone AS recipient_phone, u_don.name AS donor_name, u_don.phone AS donor_phone
         FROM rescues r
         LEFT JOIN listings l ON l.id = r.listing_id
         LEFT JOIN users u_rec ON u_rec.id = r.recipient_id
         LEFT JOIN users u_don ON u_don.id = l.donor_id
         WHERE r.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $rescueId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $db->close();

    if (!$row) return false;

    $title = 'Delivery status update';
    $message = "Your delivery (#{$rescueId}) status changed: {$oldStatus} → {$newStatus}.";

    // Push notifications (if available)
    if (!empty($row['donor_id'])) {
        send_push_notification($row['donor_id'], 'donor', $title, $message);
    }
    if (!empty($row['recipient_id'])) {
        send_push_notification($row['recipient_id'], 'recipient', $title, $message);
    }

    // SMS fallback
    if (!empty($row['donor_phone'])) {
        send_sms($row['donor_phone'], $message);
    }
    if (!empty($row['recipient_phone'])) {
        send_sms($row['recipient_phone'], $message);
    }

    return true;
}
