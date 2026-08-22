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

$category = isset($_GET['category']) ? $_GET['category'] : 'donors';
$period = isset($_GET['period']) ? $_GET['period'] : 'monthly';

$db = get_db_connection();
$data = [];

if ($category === 'donors') {
    $sql = "SELECT u.id, u.name, u.user_type AS type, u.phone AS contact_phone,
            COUNT(l.id) AS total_listings,
            SUM(CASE WHEN l.status = 'completed' THEN 1 ELSE 0 END) AS total_completed,
            SUM(CASE WHEN l.status IN ('published', 'matched') THEN 1 ELSE 0 END) AS active_listings
            FROM users u
            LEFT JOIN listings l ON l.donor_id = u.id
            WHERE u.user_type = 'donor' AND u.is_active = 1
            GROUP BY u.id
            ORDER BY total_completed DESC, total_listings DESC
            LIMIT 20";
    $result = $db->query($sql);
    $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($data as $index => &$row) {
        $row['neki_score'] = intval($row['total_completed']) * 10 + intval($row['active_listings']);
        $row['rank'] = $index + 1;
    }
    unset($row);
} elseif ($category === 'riders') {
    $sql = "SELECT u.id, u.name, u.user_type AS type, u.phone AS contact_phone,
            COUNT(r.id) AS total_assignments,
            SUM(CASE WHEN r.status = 'delivered' THEN 1 ELSE 0 END) AS total_deliveries
            FROM users u
            LEFT JOIN rescues r ON r.assigned_rider_id = u.id
            WHERE u.user_type = 'rider' AND u.is_active = 1
            GROUP BY u.id
            ORDER BY total_deliveries DESC, total_assignments DESC
            LIMIT 20";
    $result = $db->query($sql);
    $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($data as $index => &$row) {
        $row['neki_score'] = intval($row['total_deliveries']) * 15 + intval($row['total_assignments']);
        $row['rank'] = $index + 1;
    }
    unset($row);
} elseif ($category === 'areas') {
    $sql = "SELECT
            CONCAT(ROUND(l.latitude, 2), ',', ROUND(l.longitude, 2)) AS area,
            COUNT(*) AS total_listings,
            SUM(CASE WHEN l.status = 'completed' THEN 1 ELSE 0 END) AS meals_saved,
            COUNT(DISTINCT l.donor_id) AS active_donors
            FROM listings l
            GROUP BY area
            ORDER BY total_listings DESC
            LIMIT 10";

    $result = $db->query($sql);
    $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($data as $index => &$row) {
        $row['rank'] = $index + 1;
    }
    unset($row);
} else {
    send_json(['error' => 'Invalid category'], 400);
}

send_json(['category' => $category, 'period' => $period, 'rows' => $data]);
$db->close();
