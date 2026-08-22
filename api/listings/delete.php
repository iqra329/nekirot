<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'Method not allowed'], 405);
}

$user = current_user();
if (!$user['id']) {
    send_json(['error' => 'Not logged in'], 401);
}

if ($user['type'] !== 'donor') {
    send_json(['error' => 'Only donors can cancel listings'], 403);
}

$body = get_json_body();
$listingId = intval($body['listing_id'] ?? 0);
if (!$listingId) {
    send_json(['error' => 'Missing listing_id'], 400);
}

$db = get_db_connection();
$stmt = $db->prepare('UPDATE listings
                      SET status = "cancelled", updated_at = NOW()
                      WHERE id = ? AND donor_id = ? AND status = "published"');
$stmt->bind_param('ii', $listingId, $user['id']);
$stmt->execute();

if ($stmt->affected_rows === 1) {
    send_json(['success' => true, 'message' => 'Listing cancelled successfully']);
}

$stmt = $db->prepare('SELECT status FROM listings WHERE id = ? AND donor_id = ?');
$stmt->bind_param('ii', $listingId, $user['id']);
$stmt->execute();
$listing = $stmt->get_result()->fetch_assoc();

if (!$listing) {
    send_json(['error' => 'Listing not found or you do not have permission'], 404);
}

$messages = [
    'matched' => 'Cannot cancel. The listing has been accepted by a recipient.',
    'in_transit' => 'Cannot cancel. The food is already in transit.',
    'completed' => 'Cannot cancel. The listing is already completed.',
    'cancelled' => 'This listing is already cancelled.'
];
send_json(['error' => $messages[$listing['status']] ?? 'Listing cannot be cancelled at this stage.'], 400);