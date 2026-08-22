<?php
// includes/notifications.php
require_once __DIR__ . '/functions.php';

function add_alert($user_id, $message, $type = 'notification') {
    $db = get_db_connection();
    $stmt = $db->prepare('INSERT INTO alerts (user_id, message, type, is_read, created_at) VALUES (?, ?, ?, 0, NOW())');
    $stmt->bind_param('iss', $user_id, $message, $type);
    return $stmt->execute();
}

function get_alerts_for_user($user_id) {
    $db = get_db_connection();
    $stmt = $db->prepare('SELECT id, message, type, is_read, created_at FROM alerts WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function mark_alert_read($alert_id, $user_id) {
    $db = get_db_connection();
    $stmt = $db->prepare('UPDATE alerts SET is_read = 1 WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $alert_id, $user_id);
    $stmt->execute();
    return $stmt->affected_rows > 0;
}

function notify_user($user_id, $message, $type = 'notification') {
    add_alert($user_id, $message, $type);
    $db = get_db_connection();
    $stmt = $db->prepare('SELECT phone FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    if ($user && !empty($user['phone'])) {
        send_twilio_sms($user['phone'], $message);
    }
}
