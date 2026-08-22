<?php
include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user = current_user();

if ($user['type'] !== 'rider') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$db = get_db_connection();

// ============================================
// POST - Accept a rescue
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rescue_id = intval($_POST['rescue_id'] ?? 0);
    
    if ($rescue_id) {
        // Get rider location
        $rider_lat = floatval($user['latitude'] ?? 0);
        $rider_lng = floatval($user['longitude'] ?? 0);
        
        // Get rescue pickup location
        $stmt = $db->prepare('
            SELECT r.*, l.latitude as pickup_lat, l.longitude as pickup_lng 
            FROM rescues r 
            JOIN listings l ON l.id = r.listing_id 
            WHERE r.id = ?
        ');
        $stmt->bind_param('i', $rescue_id);
        $stmt->execute();
        $rescue = $stmt->get_result()->fetch_assoc();
        
        if ($rescue) {
            // Check if rider is within 10km
            if ($rider_lat && $rider_lng) {
                $distance = calculate_distance_km($rider_lat, $rider_lng, $rescue['pickup_lat'], $rescue['pickup_lng']);
                if ($distance > 10) {
                    $error = "You are too far from the pickup location (" . round($distance, 1) . " km away)";
                }
            }
            
            if (!isset($error)) {
                // Update rescue - set rider and status
                $stmt = $db->prepare('
                    UPDATE rescues 
                    SET assigned_rider_id = ?, status = ?, updated_at = NOW() 
                    WHERE id = ? AND status = ? AND assigned_rider_id IS NULL
                ');
                $new_status = 'rider_assigned';
                $current_status = 'accepted_by_recipient';
                $stmt->bind_param('isis', $user['id'], $new_status, $rescue_id, $current_status);
                
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    // Update listing status
                    $db->query("UPDATE listings SET status = 'matched' WHERE id = {$rescue['listing_id']}");
                    
                    // Create tracking entry
                    if ($rider_lat && $rider_lng) {
                        $stmt = $db->prepare('
                            INSERT INTO tracking (rescue_id, rider_id, latitude, longitude, status, tracked_at) 
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ');
                        $tracking_status = 'rider_assigned';
                        $stmt->bind_param('iidds', $rescue_id, $user['id'], $rider_lat, $rider_lng, $tracking_status);
                        $stmt->execute();
                    }
                    
                    $success = "✅ Rescue accepted successfully!";
                } else {
                    $error = "Rescue already assigned or unavailable.";
                }
            }
        } else {
            $error = "Rescue not found.";
        }
    }
}

// ============================================
// GET - Show available rescues
// ============================================
// FIX: Looking for 'accepted_by_recipient' instead of 'pending'
$stmt = $db->prepare('
    SELECT r.id, r.title, r.description, r.contact_phone, r.latitude, r.longitude, r.status, 
           u.name AS recipient_name, r.created_at 
    FROM rescues r 
    JOIN users u ON u.id = r.recipient_id 
    WHERE r.status = ? AND r.assigned_rider_id IS NULL
    ORDER BY r.created_at DESC
');
$status = 'accepted_by_recipient';
$stmt->bind_param('s', $status);
$stmt->execute();
$result = $stmt->get_result();
$rescues = $result->fetch_all(MYSQLI_ASSOC);

$db->close();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .rescues-container {
        padding: 20px 0;
    }
    .rescue-card {
        background: white;
        border-radius: 12px;
        padding: 16px 20px;
        border: 1px solid rgba(26,58,107,0.06);
        transition: all 0.3s ease;
        margin-bottom: 12px;
    }
    .rescue-card:hover {
        border-color: #2b6cb0;
        box-shadow: 0 4px 20px rgba(43,108,176,0.08);
        transform: translateY(-2px);
    }
    .btn-accept-rescue {
        background: linear-gradient(135deg, #2b6cb0, #1a365d);
        color: white;
        border: none;
        padding: 8px 20px;
        border-radius: 10px;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    .btn-accept-rescue:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(43,108,176,0.3);
        color: white;
    }
    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-badge.available {
        background: #d1fae5;
        color: #065f46;
    }
    .distance-badge {
        background: #ebf8ff;
        color: #2b6cb0;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
</style>

<div class="rescues-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="fw-bold text-primary-dark">
                <i class="fas fa-bell"></i> Available Rescues
            </h1>
            <p class="text-muted">Accept a rescue request and start tracking delivery.</p>
        </div>
        <a href="<?= BASE_URL ?>rider/dashboard.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <?php if (empty($rescues)): ?>
        <div class="text-center py-5">
            <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
            <h4>No available rescues</h4>
            <p class="text-muted">There are no rescues ready for pickup right now.</p>
            <small class="text-muted">Check back later or expand your search radius.</small>
        </div>
    <?php else: ?>
        <?php foreach ($rescues as $rescue): ?>
            <div class="rescue-card">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <h5 class="fw-bold mb-1"><?= htmlspecialchars($rescue['title']) ?></h5>
                        <p class="text-muted small mb-1">
                            <i class="fas fa-user me-1"></i> 
                            Recipient: <?= htmlspecialchars($rescue['recipient_name']) ?>
                        </p>
                        <span class="status-badge available">Available</span>
                    </div>
                    <div class="col-md-3">
                        <p class="text-muted small mb-1">
                            <i class="fas fa-map-marker-alt me-1"></i>
                            <?= number_format($rescue['latitude'], 4) ?>, <?= number_format($rescue['longitude'], 4) ?>
                        </p>
                        <p class="text-muted small mb-0">
                            <i class="fas fa-phone me-1"></i>
                            <?= htmlspecialchars($rescue['contact_phone']) ?>
                        </p>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted">
                            <i class="fas fa-clock me-1"></i>
                            <?= timeAgo($rescue['created_at']) ?>
                        </small>
                    </div>
                    <div class="col-md-3 text-end">
                        <form method="post" action="<?= BASE_URL ?>rider/available_rescues.php">
                            <input type="hidden" name="rescue_id" value="<?= $rescue['id'] ?>">
                            <button type="submit" class="btn-accept-rescue">
                                <i class="fas fa-check me-1"></i> Accept
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>