<?php
// ============================================
// NEKIROT QUETTA - RIDER DASHBOARD
// Shows active delivery, available rescues, stats
// ============================================

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
$riderId = intval($user['id']);
$deliveryCompletionMessage = '';
$pickupCompletionMessage = '';

if (!empty($_GET['delivery_completed'])) {
    $deliveryCompletionMessage = '✅ Delivery completed successfully! The food has been delivered to the recipient.';
}

if (!empty($_GET['pickup_completed'])) {
    $pickupCompletionMessage = '✅ Food picked up successfully. You can now start the delivery to the recipient.';
}

// FETCH RIDER PROFILE
$stmt = $db->prepare('SELECT id, name, phone, latitude, longitude FROM users WHERE id = ? AND user_type = ? LIMIT 1');
$type = 'rider';
$stmt->bind_param('is', $riderId, $type);
$stmt->execute();
$rider = $stmt->get_result()->fetch_assoc();

if (!$rider) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$riderLat = floatval($rider['latitude'] ?? 0);
$riderLng = floatval($rider['longitude'] ?? 0);
$hasLocation = ($riderLat !== 0 && $riderLng !== 0);

// CHECK FOR PHOTO_URL COLUMN
$photoSelect = 'NULL AS photo_url';
$photoColumnCheck = $db->query("SHOW COLUMNS FROM listings LIKE 'photo_url'");
if ($photoColumnCheck && $photoColumnCheck->num_rows > 0) {
    $photoSelect = 'l.photo_url';
}

// STATS
$stmt = $db->prepare('SELECT COUNT(*) AS total FROM rescues WHERE assigned_rider_id = ? AND status = ?');
$delivered = 'delivered';
$stmt->bind_param('is', $riderId, $delivered);
$stmt->execute();
$totalDelivered = intval($stmt->get_result()->fetch_assoc()['total']);

$stmt = $db->prepare('SELECT COUNT(*) AS total FROM rescues WHERE assigned_rider_id = ? AND status NOT IN ("delivered", "cancelled")');
$stmt->bind_param('i', $riderId);
$stmt->execute();
$activeCount = intval($stmt->get_result()->fetch_assoc()['total']);

$stmt = $db->prepare('SELECT COUNT(*) AS total FROM rescues WHERE assigned_rider_id = ?');
$stmt->bind_param('i', $riderId);
$stmt->execute();
$totalAssigned = intval($stmt->get_result()->fetch_assoc()['total']);

$nekiScore = $totalDelivered * 40;

// DELIVERY HISTORY
$history = [];
$stmt = $db->prepare(
    'SELECT r.id, r.title, r.status, r.created_at, r.updated_at, u.name AS recipient_name
     FROM rescues r
     JOIN users u ON u.id = r.recipient_id
     WHERE r.assigned_rider_id = ? AND r.status = "delivered"
     ORDER BY r.updated_at DESC, r.created_at DESC
     LIMIT 10'
);
$stmt->bind_param('i', $riderId);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ACTIVE DELIVERY
$activeDelivery = null;
$activeQuery = "SELECT r.id, r.title, r.description, r.status, r.latitude AS recipient_lat, r.longitude AS recipient_lng,
        u.name AS recipient_name, u.phone AS recipient_phone,
        l.id AS listing_id, l.latitude AS pickup_lat, l.longitude AS pickup_lng,
        $photoSelect,
        l.quantity,
        d.name AS donor_name, d.phone AS donor_phone,
        d.id AS donor_id, u.id AS recipient_id,
        r.created_at
 FROM rescues r
 JOIN users u ON u.id = r.recipient_id
 LEFT JOIN listings l ON l.id = r.listing_id
 LEFT JOIN users d ON d.id = l.donor_id
 WHERE r.assigned_rider_id = ? AND r.status NOT IN ('delivered', 'cancelled')
 ORDER BY r.created_at DESC
 LIMIT 1";

$stmt = $db->prepare($activeQuery);
$stmt->bind_param('i', $riderId);
$stmt->execute();
$activeDelivery = $stmt->get_result()->fetch_assoc();

// AVAILABLE RESCUES
$availableRescues = [];

if ($hasLocation && !$activeDelivery) {
    $statusPending = 'pending';
    $availableQuery = "SELECT r.id, r.title, r.description, r.status,
               u.name AS recipient_name, u.phone AS recipient_phone, 
               u.latitude AS recipient_lat, u.longitude AS recipient_lng,
               l.id AS listing_id, l.latitude AS pickup_lat, l.longitude AS pickup_lng,
               $photoSelect,
               l.quantity, l.pickup_deadline,
               d.name AS donor_name, d.phone AS donor_phone, 
               (6371 * acos(
                   cos(radians(?)) * cos(radians(COALESCE(l.latitude, r.latitude))) *
                   cos(radians(COALESCE(l.longitude, r.longitude)) - radians(?)) +
                   sin(radians(?)) * sin(radians(COALESCE(l.latitude, r.latitude)))
               )) AS distance_km
        FROM rescues r
        JOIN users u ON u.id = r.recipient_id
        LEFT JOIN listings l ON l.id = r.listing_id
        LEFT JOIN users d ON d.id = l.donor_id
        WHERE r.status = ?
          AND r.assigned_rider_id IS NULL
        HAVING distance_km <= 30
        ORDER BY distance_km ASC
        LIMIT 30";
        
    $stmt = $db->prepare($availableQuery);
    $stmt->bind_param('ddds', $riderLat, $riderLng, $riderLat, $statusPending);
    $stmt->execute();
    $availableRescues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$db->close();

$includeMap = true;
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    #riderMap { height: 340px; border-radius: 16px; border: 1px solid rgba(26,58,107,0.08); background: #f8fafc; margin-bottom: 20px; }
    #activeDeliveryMap { height: 360px; border-radius: 16px; overflow: hidden; }
    .dashboard-card { background: white; border-radius: 18px; border: 1px solid rgba(226,232,240,0.95); box-shadow: 0 16px 35px rgba(15,23,42,0.06); }
    .dashboard-card-header { padding: 22px 24px; border-bottom: 1px solid rgba(226,232,240,0.95); font-weight: 700; color: #1a365d; }
    .dashboard-card-body { padding: 24px; }
    .stat-card { background: white; border-radius: 18px; border: 1px solid rgba(226,232,240,0.95); padding: 24px; text-align: center; box-shadow: 0 12px 28px rgba(15,23,42,0.06); }
    .stat-card .stat-icon { font-size: 2rem; display: block; margin-bottom: 12px; }
    .stat-card .stat-number { font-size: 2rem; font-weight: 800; color: #1a365d; }
    .stat-card .stat-label { color: #718096; font-size: 0.95rem; margin-top: 8px; }
    .rescue-card { background: white; border-radius: 16px; border: 1px solid rgba(226,232,240,0.95); padding: 20px; margin-bottom: 16px; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .rescue-card:hover { transform: translateY(-2px); box-shadow: 0 14px 34px rgba(15,23,42,0.08); }
    .food-image { width: 100%; height: 120px; object-fit: cover; border-radius: 12px; cursor: pointer; transition: transform 0.3s ease; }
    .food-image:hover { transform: scale(1.05); }
    .food-image-placeholder { width: 100%; height: 120px; border-radius: 12px; background: linear-gradient(135deg, #e6fffa, #ebf8ff); display: flex; align-items: center; justify-content: center; color: #2b6cb0; font-size: 2.5rem; }
    .btn-accept { padding: 10px 24px; border-radius: 14px; border: none; font-weight: 700; color: white; background: linear-gradient(135deg, #2b6cb0, #1a365d); transition: all 0.3s ease; }
    .btn-accept:hover { transform: translateY(-1px); box-shadow: 0 12px 24px rgba(43,108,176,0.25); color: white; }
    .btn-action { width: 100%; padding: 14px 20px; border-radius: 18px; border: none; font-weight: 700; color: white; background: linear-gradient(135deg, #48bb78, #276749); transition: all 0.3s ease; text-decoration: none; display: inline-block; text-align: center; }
    .btn-action:hover { transform: translateY(-1px); box-shadow: 0 12px 24px rgba(72,187,120,0.25); color: white; }
    .status-pill { display: inline-flex; align-items: center; padding: 10px 18px; border-radius: 999px; font-weight: 700; text-transform: capitalize; }
    .status-pill.accepted { background: #fef3c7; color: #b7791f; }
    .status-pill.picked_up { background: #d1fae5; color: #065f46; }
    .status-pill.in_transit { background: #c7d2fe; color: #3730a3; }
    .status-pill.delivered { background: #d1fae5; color: #065f46; }
    .status-pill.pending { background: #e0f2fe; color: #0369a1; }
    .progress-track { background: #e2e8f0; border-radius: 999px; height: 14px; overflow: hidden; margin-bottom: 18px; }
    .progress-fill { height: 100%; background: linear-gradient(90deg,#2b6cb0,#63b3ed); transition: width 0.3s ease; }
    .step-pill { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 999px; color: white; font-weight: 700; }
    .step-pill.active { background: #2b6cb0; }
    .step-pill.completed { background: #48bb78; }
    .step-pill.pending { background: #cbd5e1; color: #334155; }
    .flow-label { margin-top: 8px; font-size: 0.82rem; color: #475569; }
    .location-warning { background: #fef3c7; border: 1px solid #f6c23e; border-radius: 16px; padding: 24px; color: #b7791f; text-align: center; }
    .distance-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; background: #ebf8ff; color: #2b6cb0; font-weight: 600; }
    .alert-auto-delivery { background: #d1fae5; border: 1px solid #48bb78; border-radius: 16px; padding: 16px; color: #065f46; font-weight: 600; margin-bottom: 16px; }
    .custom-marker-pickup { background: #48bb78; width: 16px; height: 16px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 10px rgba(72,187,120,0.4); }
    .custom-marker-dropoff { background: #ed8936; width: 16px; height: 16px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 10px rgba(237,137,54,0.4); }
    .image-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); }
    .image-modal-content { margin: auto; display: block; max-width: 90%; max-height: 90%; border-radius: 12px; margin-top: 2%; }
    .image-modal-close { position: absolute; top: 20px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; transition: 0.3s; }
    .image-modal-close:hover { color: #bbb; }
    .leaflet-control-zoom { border: none !important; box-shadow: 0 2px 10px rgba(0,0,0,0.1) !important; }
    .leaflet-control-zoom a { background: white !important; color: #1a1a2e !important; font-weight: 600 !important; border: none !important; }
    .leaflet-control-zoom a:hover { background: #0d6efd !important; color: white !important; }
    @media (max-width: 768px) { 
        .stat-card .stat-number { font-size: 1.6rem; } 
        .rescue-card { padding: 14px; } 
        #riderMap { height: 240px; } 
        #activeDeliveryMap { height: 240px; }
        .food-image { height: 100px; }
        .food-image-placeholder { height: 100px; }
    }
</style>

<!-- Image Modal -->
<div id="imageModal" class="image-modal" onclick="closeImageModal()">
    <span class="image-modal-close" onclick="closeImageModal()">&times;</span>
    <img class="image-modal-content" id="modalImage">
</div>

<div class="container py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                <div>
                    <h1 class="h3 fw-bold">Rider Dashboard</h1>
                    <p class="text-muted mb-0">Manage your delivery workflow from accept through completion.</p>
                </div>
                <div>
                    <?php if ($hasLocation): ?>
                        <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Location set</span>
                        <a href="<?= BASE_URL ?>profile.php" class="btn btn-outline-primary btn-sm ms-2">
                            <i class="fas fa-edit me-1"></i> Update Profile
                        </a>
                    <?php else: ?>
                        <a href="<?= BASE_URL ?>profile.php" class="btn btn-primary">
                            <i class="fas fa-user-edit me-2"></i> Update Profile & Location
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-icon">📋</span>
                <div class="stat-number"><?= $totalAssigned ?></div>
                <div class="stat-label">Total Assigned</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-icon">🚚</span>
                <div class="stat-number"><?= $activeCount ?></div>
                <div class="stat-label">Active</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-icon">✅</span>
                <div class="stat-number"><?= $totalDelivered ?></div>
                <div class="stat-label">Delivered</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-icon">⭐</span>
                <div class="stat-number"><?= $nekiScore ?></div>
                <div class="stat-label">Neki Score</div>
            </div>
        </div>
    </div>

    <!-- Location Warning -->
    <?php if (!$hasLocation): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="location-warning">
                    <i class="fas fa-map-marker-alt fa-3x mb-3 d-block"></i>
                    <h5>📍 Location Not Set</h5>
                    <p class="mb-2">Update your profile with your current location to see available rescues.</p>
                    <a href="<?= BASE_URL ?>profile.php" class="btn btn-primary">
                        <i class="fas fa-user-edit me-2"></i> Update Profile & Location
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($deliveryCompletionMessage): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-success mb-0" role="alert" style="border-radius: 16px; font-weight: 600;">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($deliveryCompletionMessage) ?>
                </div>
            </div>
        </div>
        <script>
            // Clean URL after showing message
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, null, window.location.pathname);
            }
        </script>
    <?php elseif ($pickupCompletionMessage): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-success mb-0" role="alert" style="border-radius: 16px; font-weight: 600;">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($pickupCompletionMessage) ?>
                </div>
            </div>
        </div>
        <script>
            // Clean URL after showing message
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, null, window.location.pathname);
            }
        </script>
    <?php endif; ?>

    <!-- Map -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <?= $activeDelivery ? 'Active Delivery Map' : 'Your Location & Nearby Rescues' ?>
                </div>
                <div class="dashboard-card-body">
                    <div id="riderMap"></div>
                    <div class="d-flex flex-wrap gap-3">
                       <span class="distance-badge">🏍️ You (Live)</span>
                        <span class="distance-badge">🟢 Donor (Live)</span>
                        <span class="distance-badge">🟠 Recipient (Live)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Available Rescues -->
    <?php if (!$activeDelivery): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="dashboard-card">
                    <div class="dashboard-card-header">
                        Available Rescues Near You
                        <?php if (!empty($availableRescues)): ?>
                            <span class="badge bg-primary ms-2"><?= count($availableRescues) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="dashboard-card-body">
                        <?php if (!$hasLocation): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-map-pin fa-3x text-muted mb-3 d-block"></i>
                                <p class="text-muted mb-1">Update your profile with your location first.</p>
                            </div>
                        <?php elseif (empty($availableRescues)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                                <p class="text-muted mb-1">No pending rescues available near you.</p>
                                <small class="text-muted">Check back later.</small>
                            </div>
                        <?php else: ?>
                            <?php foreach ($availableRescues as $rescue): ?>
                                <div class="rescue-card">
                                    <div class="row gy-3 align-items-center">
                                        <div class="col-md-2">
                                            <?php if (!empty($rescue['photo_url'])): ?>
                                                <img src="<?= BASE_URL . htmlspecialchars(ltrim($rescue['photo_url'], '/')) ?>" 
                                                     alt="<?= htmlspecialchars($rescue['title']) ?>" 
                                                     class="food-image" 
                                                     onclick="openImageModal('<?= BASE_URL . htmlspecialchars(ltrim($rescue['photo_url'], '/')) ?>')"
                                                     onerror="this.outerHTML='<div class=\'food-image-placeholder\'><i class=\'fas fa-utensils\'></i></div>'">
                                            <?php else: ?>
                                                <div class="food-image-placeholder">
                                                    <i class="fas fa-utensils"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-5">
                                            <h5 class="fw-semibold mb-2"><?= htmlspecialchars($rescue['title']) ?></h5>
                                            <p class="text-muted small mb-2">
                                                <i class="fas fa-user me-1"></i> 
                                                <strong>Recipient:</strong> <?= htmlspecialchars($rescue['recipient_name'] ?? 'Unknown') ?>
                                            </p>
                                            <p class="text-muted small mb-2">
                                                <i class="fas fa-store me-1"></i> 
                                                <strong>Donor:</strong> <?= htmlspecialchars($rescue['donor_name'] ?? 'Unknown') ?>
                                            </p>
                                        </div>
                                        <div class="col-md-3">
                                            <?php if (!empty($rescue['quantity']) && $rescue['quantity'] > 0): ?>
                                                <p class="text-muted small mb-2">
                                                    <i class="fas fa-box me-1"></i> 
                                                    <strong><?= $rescue['quantity'] ?> plates</strong>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($rescue['distance_km'])): ?>
                                                <span class="distance-badge">
                                                    <i class="fas fa-road me-1"></i> 
                                                    <?= number_format(floatval($rescue['distance_km']), 1) ?> km away
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-2 text-md-end">
                                            <a href="<?= BASE_URL ?>rider/accept.php?id=<?= intval($rescue['id']) ?>" 
                                               class="btn btn-accept"
                                               onclick="return confirm('Accept this rescue?')">
                                                <i class="fas fa-check me-1"></i> Accept
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Active Delivery -->
    <?php if ($activeDelivery): ?>
        <div class="row g-4">
            <div class="col-xl-8">
                <div class="dashboard-card">
                    <div class="dashboard-card-header">Active Delivery</div>
                    <div class="dashboard-card-body">
                        <?php
                            $statusSteps = ['accepted' => 1, 'picked_up' => 2, 'in_transit' => 3, 'delivered' => 4];
                            $currentStep = $statusSteps[$activeDelivery['status']] ?? 1;
                            $progressWidth = min(100, max(10, round(($currentStep / 4) * 100)));
                            $pickupLat = floatval($activeDelivery['pickup_lat'] ?? 0);
                            $pickupLng = floatval($activeDelivery['pickup_lng'] ?? 0);
                            $recipientLat = floatval($activeDelivery['recipient_lat'] ?? 0);
                            $recipientLng = floatval($activeDelivery['recipient_lng'] ?? 0);
                            
                            $actionUrl = '';
                            $actionLabel = '';
                            if ($activeDelivery['status'] === 'accepted') {
                                $actionUrl = BASE_URL . 'rider/pickup.php?id=' . intval($activeDelivery['id']);
                                $actionLabel = 'Pickup Food';
                            } elseif ($activeDelivery['status'] === 'picked_up') {
                                $actionUrl = BASE_URL . 'rider/start_transit.php?id=' . intval($activeDelivery['id']);
                                $actionLabel = 'Start Delivery';
                            } elseif ($activeDelivery['status'] === 'in_transit') {
                                $actionUrl = BASE_URL . 'rider/start_transit.php?id=' . intval($activeDelivery['id']);
                                $actionLabel = 'Track Live Delivery';
                            }
                        ?>
                        
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <h5 class="fw-semibold mb-1"><?= htmlspecialchars($activeDelivery['title']) ?></h5>
                                <p class="text-muted small mb-1">
                                    <i class="fas fa-user me-1"></i> 
                                    <strong>Recipient:</strong> <?= htmlspecialchars($activeDelivery['recipient_name']) ?>
                                </p>
                                <p class="text-muted small mb-0">
                                    <i class="fas fa-phone me-1"></i> 
                                    <?= htmlspecialchars($activeDelivery['recipient_phone'] ?? 'N/A') ?>
                                </p>
                            </div>
                            <span class="status-pill <?= htmlspecialchars($activeDelivery['status']) ?>">
                                <?= htmlspecialchars(str_replace('_', ' ', ucfirst($activeDelivery['status']))) ?>
                            </span>
                        </div>

                        <?php if (!empty($activeDelivery['photo_url'])): ?>
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <img src="<?= BASE_URL . htmlspecialchars(ltrim($activeDelivery['photo_url'], '/')) ?>" 
                                         alt="Food Image" 
                                         class="food-image" 
                                         style="height: 150px;"
                                         onclick="openImageModal('<?= BASE_URL . htmlspecialchars(ltrim($activeDelivery['photo_url'], '/')) ?>')"
                                         onerror="this.outerHTML='<div class=\'food-image-placeholder\' style=\'height: 150px;\'><i class=\'fas fa-utensils\'></i></div>'">
                                </div>
                                <div class="col-md-8">
                                    <?php if (!empty($activeDelivery['quantity'])): ?>
                                        <p class="text-muted small mb-2">
                                            <i class="fas fa-box me-1"></i> 
                                            <strong><?= $activeDelivery['quantity'] ?> plates</strong>
                                        </p>
                                    <?php endif; ?>
                                    <p class="text-muted small">
                                        <i class="fas fa-store me-1"></i> 
                                        <strong>Donor:</strong> <?= htmlspecialchars($activeDelivery['donor_name'] ?? 'Unknown') ?>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="progress-track mb-3">
                            <div class="progress-fill" style="width: <?= $progressWidth ?>%;"></div>
                        </div>
                        
                        <div class="d-flex flex-wrap gap-3 align-items-center mb-4">
                            <?php foreach ($statusSteps as $step => $number): ?>
                                <?php
                                    $stepClass = $number < $currentStep ? 'completed' : ($number === $currentStep ? 'active' : 'pending');
                                ?>
                                <div class="text-center" style="min-width: 90px;">
                                    <div class="step-pill <?= $stepClass ?>"><?= $number ?></div>
                                    <div class="flow-label"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($step))) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm p-3">
                                    <h6 class="mb-2">📦 Pickup Location</h6>
                                    <p class="text-muted small mb-1"><?= number_format($pickupLat, 4) ?>, <?= number_format($pickupLng, 4) ?></p>
                                    <p class="text-muted small mb-0">
                                        <i class="fas fa-store me-1"></i> 
                                        <strong>Donor:</strong> <?= htmlspecialchars($activeDelivery['donor_name'] ?? 'Unknown') ?>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm p-3">
                                    <h6 class="mb-2">🏠 Destination</h6>
                                    <p class="text-muted small mb-1"><?= number_format($recipientLat, 4) ?>, <?= number_format($recipientLng, 4) ?></p>
                                    <p class="text-muted small mb-0">
                                        <i class="fas fa-user me-1"></i> 
                                        <strong>Recipient:</strong> <?= htmlspecialchars($activeDelivery['recipient_name'] ?? 'Unknown') ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <?php if ($actionUrl): ?>
                            <a href="<?= htmlspecialchars($actionUrl) ?>" class="btn btn-action mb-4">
                                <i class="fas fa-arrow-right me-2"></i> <?= htmlspecialchars($actionLabel) ?>
                            </a>
                        <?php endif; ?>

                        <div id="activeDeliveryMap"></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="dashboard-card">
                    <div class="dashboard-card-header">Delivery History</div>
                    <div class="dashboard-card-body" style="max-height: 520px; overflow-y: auto;">
                        <?php if (empty($history)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-history fa-2x text-muted mb-3 d-block"></i>
                                <p class="text-muted mb-1">No completed deliveries yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($history as $item): ?>
                                <div class="d-flex justify-content-between align-items-center py-3 border-bottom">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($item['title']) ?></div>
                                        <small class="text-muted">
                                            <i class="fas fa-user me-1"></i> <?= htmlspecialchars($item['recipient_name']) ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-success">Delivered</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function openImageModal(imageSrc) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    modal.style.display = 'block';
    modalImg.src = imageSrc;
}

function closeImageModal() {
    document.getElementById('imageModal').style.display = 'none';
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeImageModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const activeDelivery = <?= json_encode($activeDelivery) ?>;
    const riderId = <?= json_encode($riderId) ?>;
    const riderLat = <?= json_encode($riderLat) ?>;
    const riderLng = <?= json_encode($riderLng) ?>;
    const availableRescues = <?= json_encode($availableRescues) ?>;
    const hasLocation = <?= json_encode($hasLocation) ?>;
    const baseUrl = <?= json_encode(BASE_URL) ?>;

    let liveMarkers = {};

    function getLiveEmoji(userType) {
        if (userType === 'rider') return '🏍️';
        if (userType === 'donor') return '🏪';
        if (userType === 'recipient') return '🏠';
        return '📍';
    }

    function getLiveIcon(userType) {
        return L.divIcon({ 
            className: '', 
            html: getLiveEmoji(userType),
            iconSize: [36, 36], 
            iconAnchor: [18, 18],
            popupAnchor: [0, -18]
        });
    }

    async function fetchLiveLocation(userId, mapInstance) {
        if (!userId || userId === 0) return;
        
        try {
            const res = await fetch(baseUrl + 'api/get_live_location.php?user_id=' + userId, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            
            if (!data.success || !data.latitude || !data.longitude) return;
            
            const lat = parseFloat(data.latitude);
            const lng = parseFloat(data.longitude);
            const typeLabel = data.user_type.charAt(0).toUpperCase() + data.user_type.slice(1);
            
            if (liveMarkers[userId]) {
                liveMarkers[userId].setLatLng([lat, lng]);
            } else {
                const icon = getLiveIcon(data.user_type);
                liveMarkers[userId] = L.marker([lat, lng], { icon: icon })
                    .addTo(mapInstance)
                    .bindPopup('<strong>' + getLiveEmoji(data.user_type) + ' ' + typeLabel + '</strong><br>' + escapeHtml(data.name));
            }
        } catch (e) {
            console.log('Live location error:', e);
        }
    }

    function startLiveTracking(userIds, mapInstance) {
        const cleanIds = [...new Set(userIds.filter(id => id && parseInt(id) > 0))];
        if (cleanIds.length === 0) return;
        
        cleanIds.forEach(function(userId) {
            fetchLiveLocation(userId, mapInstance);
        });
        
        setInterval(function() {
            cleanIds.forEach(function(userId) {
                fetchLiveLocation(userId, mapInstance);
            });
        }, 5000);
    }

    function initMap(containerId, markers, routePoints) {
        const centerLat = riderLat || 30.1798;
        const centerLng = riderLng || 66.9750;
        const map = L.map(containerId, { scrollWheelZoom: false }).setView([centerLat, centerLng], hasLocation ? 12 : 9);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        const pickupIcon = L.divIcon({ className: 'custom-marker-pickup', iconSize: [16, 16], iconAnchor: [8, 8] });
        const dropoffIcon = L.divIcon({ className: 'custom-marker-dropoff', iconSize: [16, 16], iconAnchor: [8, 8] });

        const bounds = L.latLngBounds();

        markers.forEach(marker => {
            const icon = marker.type === 'pickup' ? pickupIcon : dropoffIcon;
            if (marker.lat && marker.lng) {
                bounds.extend([marker.lat, marker.lng]);
                L.marker([marker.lat, marker.lng], { icon }).addTo(map).bindPopup(marker.label);
            }
        });

        if (routePoints && routePoints.length === 2) {
            L.polyline(routePoints, { color: '#2b6cb0', weight: 4, opacity: 0.75, dashArray: '8, 6' }).addTo(map);
            bounds.extend(routePoints[0]);
            bounds.extend(routePoints[1]);
        }

        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [80, 80], maxZoom: 13 });
        }

        L.control.scale({ position: 'bottomright', metric: true, imperial: false }).addTo(map);
        return map;
    }

    const baseMarkers = [];
    if (!activeDelivery) {
        availableRescues.forEach(rescue => {
            const lat = parseFloat(rescue.pickup_lat || rescue.recipient_lat || 0);
            const lng = parseFloat(rescue.pickup_lng || rescue.recipient_lng || 0);
            if (lat && lng) {
                baseMarkers.push({ 
                    type: 'pickup', 
                    lat, 
                    lng, 
                    label: '<strong>📦 ' + escapeHtml(rescue.title) + '</strong><br>Pickup location' 
                });
            }
        });
    }
    const riderMapInstance = initMap('riderMap', baseMarkers, []);
    startLiveTracking([riderId], riderMapInstance);

    if (activeDelivery) {
        const pickupLat = parseFloat(activeDelivery.pickup_lat || 0);
        const pickupLng = parseFloat(activeDelivery.pickup_lng || 0);
        const recipientLat = parseFloat(activeDelivery.recipient_lat || 0);
        const recipientLng = parseFloat(activeDelivery.recipient_lng || 0);
        
        const routePoints = (pickupLat && pickupLng && recipientLat && recipientLng) 
            ? [[pickupLat, pickupLng], [recipientLat, recipientLng]] 
            : [];
        
        const activeMapInstance = initMap('activeDeliveryMap', [
            { type: 'pickup', lat: pickupLat, lng: pickupLng, label: '<strong>📦 Pickup</strong><br>Donor location' },
            { type: 'dropoff', lat: recipientLat, lng: recipientLng, label: '<strong>🏠 Dropoff</strong><br>Recipient location' }
        ], routePoints);

        const donorId = parseInt(activeDelivery.donor_id || 0);
        const recipientId = parseInt(activeDelivery.recipient_id || 0);
        startLiveTracking([riderId, donorId, recipientId], activeMapInstance);
    }
});

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>