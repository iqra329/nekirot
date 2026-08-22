<?php
require_once __DIR__ . '/../includes/functions.php';

cors_headers();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['error' => 'Method not allowed'], 405);
}

header('Content-Type: application/json');
header('Cache-Control: max-age=300');

$db = get_db_connection();

$hasQuantityColumn = false;
$columnCheck = $db->query("SHOW COLUMNS FROM listings LIKE 'quantity'");
if ($columnCheck && $columnCheck->num_rows > 0) {
    $hasQuantityColumn = true;
}

if ($hasQuantityColumn) {
    $result = $db->query("SELECT SUM(quantity) AS total FROM listings WHERE status = 'completed'");
} else {
    $result = $db->query("SELECT COUNT(*) AS total FROM listings WHERE status = 'completed'");
}
$meals = 0;
if ($result) {
    $row = $result->fetch_assoc();
    $meals = intval($row['total'] ?? 0);
}

$foodKg = $meals * 0.25;
$co2Saved = $foodKg * 2.0;
$waterSaved = $foodKg * 1000;

$result = $db->query("SELECT COUNT(DISTINCT donor_id) AS count FROM listings WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$donors = 0;
if ($result) {
    $donors = intval($result->fetch_assoc()['count'] ?? 0);
}

$result = $db->query("SELECT COUNT(DISTINCT assigned_rider_id) AS count FROM rescues WHERE assigned_rider_id IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$riders = 0;
if ($result) {
    $riders = intval($result->fetch_assoc()['count'] ?? 0);
}

$result = $db->query("SELECT COUNT(*) AS count FROM users WHERE user_type = 'recipient' AND is_active = 1");
$recipients = 0;
if ($result) {
    $recipients = intval($result->fetch_assoc()['count'] ?? 0);
}

$result = $db->query("SELECT COUNT(*) AS count FROM rescues WHERE DATE(created_at) = CURDATE()");
$todayRescues = 0;
if ($result) {
    $todayRescues = intval($result->fetch_assoc()['count'] ?? 0);
}

$result = $db->query("SELECT COUNT(*) AS count FROM rescues WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$monthRescues = 0;
if ($result) {
    $monthRescues = intval($result->fetch_assoc()['count'] ?? 0);
}

$result = $db->query("SELECT COUNT(DISTINCT CONCAT(latitude, ',', longitude)) AS count FROM listings WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
$areas = 0;
if ($result) {
    $areas = intval($result->fetch_assoc()['count'] ?? 0);
}

$response = [
    'total_meals_rescued' => $meals,
    'total_food_kg_saved' => round($foodKg, 2),
    'total_co2_prevented_kg' => round($co2Saved, 2),
    'total_water_saved_liters' => round($waterSaved, 2),
    'active_donors' => $donors,
    'active_riders' => $riders,
    'registered_recipients' => $recipients,
    'rescues_today' => $todayRescues,
    'rescues_this_month' => $monthRescues,
    'quetta_areas_covered' => $areas,
    'has_quantity_column' => $hasQuantityColumn,
];

send_json($response);

$db->close();
