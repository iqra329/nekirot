<?php
// rider/set_delivery_message.php
session_start();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!empty($data['message'])) {
    $_SESSION['delivery_completed_message'] = $data['message'];
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'No message provided']);
}