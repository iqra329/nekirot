<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Generate rider path between donor and recipient
 */
function generateRiderPath($rescueId)
{
    $rescueId = intval($rescueId);
    if ($rescueId <= 0) {
        return 0;
    }

    $db = get_db_connection();
    $stmt = $db->prepare(
        'SELECT r.listing_id,
                r.latitude AS recipient_lat,
                r.longitude AS recipient_lng,
                l.latitude AS donor_lat,
                l.longitude AS donor_lng
         FROM rescues r
         LEFT JOIN listings l ON l.id = r.listing_id
         WHERE r.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $rescueId);
    $stmt->execute();
    $rescue = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rescue
        || !is_numeric($rescue['donor_lat'])
        || !is_numeric($rescue['donor_lng'])
        || !is_numeric($rescue['recipient_lat'])
        || !is_numeric($rescue['recipient_lng'])
    ) {
        $db->close();
        return 0;
    }

    $donorLat = floatval($rescue['donor_lat']);
    $donorLng = floatval($rescue['donor_lng']);
    $recipientLat = floatval($rescue['recipient_lat']);
    $recipientLng = floatval($rescue['recipient_lng']);

    $totalDistance = calculate_distance_km($donorLat, $donorLng, $recipientLat, $recipientLng);
    $totalDistance = max(0.5, $totalDistance);

    $db->begin_transaction();
    try {
        $delete = $db->prepare('DELETE FROM rider_simulation_path WHERE rescue_id = ?');
        $delete->bind_param('i', $rescueId);
        $delete->execute();
        $delete->close();

        $insert = $db->prepare(
            'INSERT INTO rider_simulation_path (rescue_id, step_number, latitude, longitude, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );

        $steps = 50;
        for ($i = 0; $i < $steps; $i++) {
            $fraction = $steps > 1 ? $i / ($steps - 1) : 0;
            $latitude = $donorLat + ($recipientLat - $donorLat) * $fraction;
            $longitude = $donorLng + ($recipientLng - $donorLng) * $fraction;
            $stepNumber = $i + 1;
            $insert->bind_param('iidd', $rescueId, $stepNumber, $latitude, $longitude);
            if (!$insert->execute()) {
                throw new Exception('Unable to insert simulation step: ' . $db->error);
            }
        }
        $insert->close();

        $update = $db->prepare('UPDATE rescues SET total_distance = ?, distance_covered = 0 WHERE id = ?');
        $update->bind_param('di', $totalDistance, $rescueId);
        $update->execute();
        $update->close();

        // Reset session step
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['simulation_step_' . $rescueId] = 1;

        $db->commit();
        $db->close();

        return $steps;
    } catch (Exception $ex) {
        $db->rollback();
        $db->close();
        return 0;
    }
}

/**
 * Simulate rider movement - ONE STEP at a time
 */
function simulateRiderMovement($rescueId)
{
    $rescueId = intval($rescueId);
    if ($rescueId <= 0) {
        return [
            'status' => null,
            'progress' => 0,
            'distance_covered' => 0,
            'total_distance' => 0,
            'message' => 'Invalid rescue ID.'
        ];
    }

    $db = get_db_connection();
    
    // Get rescue data
    $stmt = $db->prepare(
        'SELECT simulation_active, status, distance_covered, total_distance, 
                rider_current_lat, rider_current_lng, assigned_rider_id
         FROM rescues
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $rescueId);
    $stmt->execute();
    $rescue = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rescue) {
        $db->close();
        return [
            'status' => null,
            'progress' => 0,
            'distance_covered' => 0,
            'total_distance' => 0,
            'message' => 'Rescue not found.'
        ];
    }

    // Check if simulation is active
    if (intval($rescue['simulation_active']) !== 1) {
        $db->close();
        return [
            'status' => $rescue['status'],
            'progress' => 0,
            'distance_covered' => floatval($rescue['distance_covered']),
            'total_distance' => floatval($rescue['total_distance']),
            'message' => 'Simulation is not active.'
        ];
    }

    // Get the simulation path
    $path = getSimulationPathForRescue($db, $rescueId);
    $totalSteps = count($path);

    if ($totalSteps === 0) {
        $db->close();
        return [
            'status' => $rescue['status'],
            'progress' => 0,
            'distance_covered' => floatval($rescue['distance_covered']),
            'total_distance' => floatval($rescue['total_distance']),
            'message' => 'No simulation path exists.'
        ];
    }

    // Get current step from session
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $currentStep = isset($_SESSION['simulation_step_' . $rescueId]) ? intval($_SESSION['simulation_step_' . $rescueId]) : 0;
    
    // If step is 0 or not set, start from step 1
    if ($currentStep <= 0) {
        $currentStep = 1;
        $_SESSION['simulation_step_' . $rescueId] = $currentStep;
        $_SESSION['simulation_active_' . $rescueId] = true;
    }

    // Check if we've reached the end
    if ($currentStep >= $totalSteps) {
        // Delivery complete - update status
        $db->query("UPDATE rescues SET status = 'delivered', simulation_active = 0, distance_covered = total_distance WHERE id = $rescueId");
        $db->close();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['simulation_step_' . $rescueId] = 0;
        $_SESSION['simulation_active_' . $rescueId] = false;
        return [
            'status' => 'delivered',
            'progress' => 100,
            'distance_covered' => floatval($rescue['total_distance']),
            'total_distance' => floatval($rescue['total_distance']),
            'latitude' => $path[$totalSteps - 1]['latitude'],
            'longitude' => $path[$totalSteps - 1]['longitude'],
            'message' => '✅ Delivery completed!'
        ];
    }

    // Get current step point
    $stepPoint = $path[$currentStep - 1];
    $currentLat = floatval($stepPoint['latitude']);
    $currentLng = floatval($stepPoint['longitude']);

    // Calculate progress
    $totalDistance = floatval($rescue['total_distance']);
    $progress = min(100, ($currentStep / $totalSteps) * 100);
    $distanceCovered = min($totalDistance, ($currentStep / $totalSteps) * $totalDistance);

    // Update rider location
    $update = $db->prepare('
        UPDATE rescues 
        SET rider_current_lat = ?, rider_current_lng = ?, 
            distance_covered = ?, updated_at = NOW() 
        WHERE id = ?
    ');
    $update->bind_param('dddi', $currentLat, $currentLng, $distanceCovered, $rescueId);
    $update->execute();
    $update->close();

    // Update tracking
    $tracking = $db->prepare('
        INSERT INTO tracking (rescue_id, rider_id, latitude, longitude, tracked_at) 
        VALUES (?, ?, ?, ?, NOW())
    ');
    $tracking->bind_param('iidd', $rescueId, $rescue['assigned_rider_id'], $currentLat, $currentLng);
    $tracking->execute();
    $tracking->close();

    // Increment step for next update
    $currentStep = $currentStep + 1;
    $_SESSION['simulation_step_' . $rescueId] = $currentStep;
    
    $db->close();

    // Calculate ETA (remaining steps * 2 seconds)
    $remainingSteps = $totalSteps - $currentStep + 1;
    $etaMinutes = round(($remainingSteps * 2) / 60, 1);

    return [
        'status' => $rescue['status'],
        'progress' => round($progress, 2),
        'distance_covered' => round($distanceCovered, 2),
        'total_distance' => round($totalDistance, 2),
        'current_step' => $currentStep - 1,
        'total_steps' => $totalSteps,
        'latitude' => $currentLat,
        'longitude' => $currentLng,
        'eta' => $etaMinutes,
        'message' => '🚴 Moving... ' . round($progress, 0) . '% complete'
    ];
}

function getRiderProgress($rescueId)
{
    $rescueId = intval($rescueId);
    if ($rescueId <= 0) {
        return [
            'current_step' => 0,
            'total_steps' => 0,
            'progress' => 0,
            'latitude' => null,
            'longitude' => null
        ];
    }

    $db = get_db_connection();
    
    // Get current progress from session first (faster)
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $currentStep = isset($_SESSION['simulation_step_' . $rescueId]) ? intval($_SESSION['simulation_step_' . $rescueId]) : 0;
    
    $path = getSimulationPathForRescue($db, $rescueId);
    $totalSteps = count($path);
    
    // Get actual location from database
    $stmt = $db->prepare(
        'SELECT rider_current_lat, rider_current_lng, distance_covered, total_distance
         FROM rescues
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $rescueId);
    $stmt->execute();
    $rescue = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $db->close();

    $progress = $totalSteps > 0 ? min(100, round(($currentStep / $totalSteps) * 100, 2)) : 0;

    return [
        'current_step' => $currentStep,
        'total_steps' => $totalSteps,
        'progress' => $progress,
        'latitude' => floatval($rescue['rider_current_lat'] ?? 0),
        'longitude' => floatval($rescue['rider_current_lng'] ?? 0),
        'distance_covered' => floatval($rescue['distance_covered'] ?? 0),
        'total_distance' => floatval($rescue['total_distance'] ?? 0)
    ];
}

function startRiderSimulation($rescueId)
{
    $rescueId = intval($rescueId);
    if ($rescueId <= 0) {
        return false;
    }

    $db = get_db_connection();
    $pathExists = getSimulationPathCount($db, $rescueId) > 0;
    if (!$pathExists) {
        $stepsCreated = generateRiderPath($rescueId);
        if ($stepsCreated === 0) {
            $db->close();
            return false;
        }
    }

    $update = $db->prepare(
        'UPDATE rescues
         SET simulation_active = 1,
             distance_covered = 0,
             rider_current_lat = (SELECT latitude FROM listings WHERE id = (SELECT listing_id FROM rescues WHERE id = ?)),
             rider_current_lng = (SELECT longitude FROM listings WHERE id = (SELECT listing_id FROM rescues WHERE id = ?))
         WHERE id = ?'
    );
    $update->bind_param('iii', $rescueId, $rescueId, $rescueId);
    $update->execute();
    $success = $update->affected_rows > 0;
    $update->close();
    $db->close();

    // Reset session
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['simulation_step_' . $rescueId] = 1;
    $_SESSION['simulation_active_' . $rescueId] = true;

    return $success;
}

function stopRiderSimulation($rescueId)
{
    $rescueId = intval($rescueId);
    if ($rescueId <= 0) {
        return false;
    }

    $db = get_db_connection();
    $update = $db->prepare('UPDATE rescues SET simulation_active = 0 WHERE id = ?');
    $update->bind_param('i', $rescueId);
    $update->execute();
    $success = $update->affected_rows > 0;
    $update->close();
    $db->close();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['simulation_step_' . $rescueId] = 0;
    $_SESSION['simulation_active_' . $rescueId] = false;

    return $success;
}

function getSimulationPathForRescue($db, $rescueId)
{
    $stmt = $db->prepare(
        'SELECT step_number, latitude, longitude
         FROM rider_simulation_path
         WHERE rescue_id = ?
         ORDER BY step_number ASC'
    );
    $stmt->bind_param('i', $rescueId);
    $stmt->execute();
    $result = $stmt->get_result();
    $path = [];
    while ($row = $result->fetch_assoc()) {
        $path[] = [
            'step_number' => intval($row['step_number']),
            'latitude' => floatval($row['latitude']),
            'longitude' => floatval($row['longitude'])
        ];
    }
    $stmt->close();
    return $path;
}

function getSimulationPathCount($db, $rescueId)
{
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM rider_simulation_path WHERE rescue_id = ?');
    $stmt->bind_param('i', $rescueId);
    $stmt->execute();
    $total = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function findSimulationStepByCoordinates(array $path, $latitude, $longitude)
{
    if ($latitude === null || $longitude === null) {
        return 0;
    }

    $tolerance = 0.000001;
    foreach ($path as $point) {
        if (abs($point['latitude'] - $latitude) < $tolerance
            && abs($point['longitude'] - $longitude) < $tolerance
        ) {
            return $point['step_number'];
        }
    }

    return 0;
}
?>