<?php
// ============================================
// NEKIROT QUETTA - LIVE LOCATION API
// Returns real-time location for ANY user
// ============================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

require_login();
$user = current_user();

$db = get_db_connection();

$userId = intval($_GET['user_id'] ?? $user['id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    $db->close();
    exit;
}

$stmt = $db->prepare('SELECT id, name, user_type, latitude, longitude FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

$db->close();

if ($userData) {
    echo json_encode([
        'success' => true,
        'user_id' => intval($userData['id']),
        'name' => $userData['name'] ?? 'User',
        'user_type' => $userData['user_type'] ?? 'unknown',
        'latitude' => floatval($userData['latitude'] ?? 0),
        'longitude' => floatval($userData['longitude'] ?? 0)
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'User not found']);
}