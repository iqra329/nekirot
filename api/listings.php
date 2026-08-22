<?php
require_once __DIR__ . '/../includes/functions.php';
cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

db_connect();
$db = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT id, donor_id, title, description, contact_phone, latitude, longitude, status, created_at FROM listings WHERE status = ? ORDER BY created_at DESC');
    $status = 'published';
    $stmt->bind_param('s', $status);
    $stmt->execute();
    $result = $stmt->get_result();
    $listings = $result->fetch_all(MYSQLI_ASSOC);
    send_json(['listings' => $listings]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    if (!$body) {
        send_json(['error' => 'Invalid request body'], 400);
    }
    $donor_id = intval($body['donor_id'] ?? 0);
    $title = trim($body['title'] ?? '');
    $description = trim($body['description'] ?? '');
    $contact_phone = trim($body['contact_phone'] ?? '');
    $latitude = floatval($body['latitude'] ?? 0);
    $longitude = floatval($body['longitude'] ?? 0);

    if (!$donor_id || !$title || !$description || !$contact_phone || !$latitude || !$longitude) {
        send_json(['error' => 'Missing fields'], 400);
    }
    if (!validate_quetta_bounds($latitude, $longitude)) {
        send_json(['error' => 'Location must be within Quetta'], 400);
    }
    $status = 'published';
    $stmt = $db->prepare('INSERT INTO listings (donor_id, title, description, contact_phone, latitude, longitude, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->bind_param('isssdds', $donor_id, $title, $description, $contact_phone, $latitude, $longitude, $status);
    if ($stmt->execute()) {
        send_json(['success' => true, 'id' => $db->insert_id], 201);
    }
    send_json(['error' => 'Failed to create listing'], 500);
}

send_json(['error' => 'Method not allowed'], 405);
