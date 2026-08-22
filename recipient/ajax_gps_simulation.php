<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gps_simulation.php';

require_login();
$user = current_user();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$rescueId = intval($_GET['rescue_id'] ?? 0);

if (!$rescueId) {
    echo json_encode(['success' => false, 'error' => 'Rescue ID required']);
    exit;
}

// Verify this rescue belongs to the user
$db = get_db_connection();
$stmt = $db->prepare('SELECT id, status, assigned_rider_id FROM rescues WHERE id = ? AND recipient_id = ?');
$stmt->bind_param('ii', $rescueId, $user['id']);
$stmt->execute();
$rescue = $stmt->get_result()->fetch_assoc();

if (!$rescue) {
    echo json_encode(['success' => false, 'error' => 'Rescue not found']);
    $db->close();
    exit;
}

switch ($action) {
    case 'start_simulation':
        if (function_exists('startRiderSimulation')) {
            $result = startRiderSimulation($rescueId);
            echo json_encode(['success' => $result]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Function not found']);
        }
        break;
        
    case 'stop_simulation':
        if (function_exists('stopRiderSimulation')) {
            $result = stopRiderSimulation($rescueId);
            echo json_encode(['success' => $result]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Function not found']);
        }
        break;
        
    case 'get_progress':
        if (function_exists('getRiderProgress')) {
            $progress = getRiderProgress($rescueId);
            echo json_encode([
                'success' => true,
                'data' => $progress
            ]);
        } else {
            // Fallback to old tracking
            $stmt = $db->prepare('SELECT latitude, longitude, status, tracked_at FROM tracking WHERE rescue_id = ? ORDER BY tracked_at DESC LIMIT 1');
            $stmt->bind_param('i', $rescueId);
            $stmt->execute();
            $tracking = $stmt->get_result()->fetch_assoc();
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'current_lat' => floatval($tracking['latitude'] ?? 0),
                    'current_lng' => floatval($tracking['longitude'] ?? 0),
                    'status' => $tracking['status'] ?? $rescue['status'],
                    'tracked_at' => $tracking['tracked_at'] ?? null,
                    'distance_covered' => 0,
                    'total_distance' => 0,
                    'simulation_active' => false
                ]
            ]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

$db->close();
?>