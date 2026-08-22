<?php
// includes/functions.php
require_once __DIR__ . '/../config/database.php';

// ============================================
// ADDED: isWithinQuetta function (alias for validate_quetta_bounds)
// ============================================
function isWithinQuetta($lat, $lng) {
    return is_numeric($lat)
        && is_numeric($lng)
        && $lat >= 30.13
        && $lat <= 30.25
        && $lng >= 66.92
        && $lng <= 67.05;
}

// ============================================
// ORIGINAL FUNCTIONS
// ============================================

function send_json($payload, $status = 200) {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function get_json_body() {
    $input = file_get_contents('php://input');
    if (!$input) {
        return null;
    }
    return json_decode($input, true);
}

function cors_headers() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

function validate_quetta_bounds($lat, $lng) {
    return is_numeric($lat)
        && is_numeric($lng)
        && $lat >= 30.13
        && $lat <= 30.25
        && $lng >= 66.92
        && $lng <= 67.05;
}

function timeAgo($timestamp) {
    $time = strtotime($timestamp);
    if ($time === false) {
        return 'Just now';
    }

    $diff = time() - $time;
    if ($diff < 0) {
        return 'Just now';
    }

    $units = [
        'year' => 31536000,
        'month' => 2592000,
        'week' => 604800,
        'day' => 86400,
        'hour' => 3600,
        'minute' => 60,
        'second' => 1
    ];

    foreach ($units as $unit => $seconds) {
        $value = floor($diff / $seconds);
        if ($value >= 1) {
            if ($value == 1) {
                return $value . ' ' . $unit . ' ago';
            }
            return $value . ' ' . $unit . 's ago';
        }
    }

    return 'Just now';
}

function calculate_distance_km($lat1, $lng1, $lat2, $lng2) {
    $earthRadiusKm = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusKm * $c;
}

function send_whatsapp($phone, $message) {
    $phone = preg_replace('/[^0-9]/', '', $phone ?? '');
    if (strlen($phone) === 10) {
        $phone = '92' . $phone;
    }

    $encodedMessage = urlencode($message);
    return 'https://wa.me/' . $phone . '?text=' . $encodedMessage;
}

function get_whatsapp_link($phone, $message) {
    return send_whatsapp($phone, $message);
}

function send_push_notification($userId, $userType, $title, $message) {
    error_log("Push Notification to $userType #$userId: $title - $message");
    return true;
}

function send_sms($phone, $message) {
    error_log("SMS to $phone: $message");
    return true;
}

function send_twilio_sms($to, $message) {
    $accountSid = getenv('TWILIO_ACCOUNT_SID');
    $authToken = getenv('TWILIO_AUTH_TOKEN');
    $from = getenv('TWILIO_PHONE_NUMBER');
    if (!$accountSid || !$authToken || !$from) {
        return false;
    }
    try {
        require_once __DIR__ . '/../vendor/twilio-php/src/Twilio/autoload.php';
        $client = new Twilio\Rest\Client($accountSid, $authToken);
        $client->messages->create($to, ['from' => $from, 'body' => $message]);
        return true;
    } catch (Exception $e) {
        error_log('SMS error: ' . $e->getMessage());
        return false;
    }
}