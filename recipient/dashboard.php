<?php
// ============================================
// NEKIROT QUETTA - RECIPIENT DASHBOARD
// With live location for recipient AND rider
// ============================================

include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user = current_user();
if ($user['type'] !== 'recipient') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$db = get_db_connection();
$recipientId = intval($user['id']);

$stmt = $db->prepare('SELECT id, name, phone, latitude, longitude FROM users WHERE id = ? AND user_type = ? LIMIT 1');
$type = 'recipient';
$stmt->bind_param('is', $recipientId, $type);
$stmt->execute();
$result = $stmt->get_result();
$recipient = $result->fetch_assoc();
if (!$recipient) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$recipientLat = floatval($recipient['latitude'] ?? 0);
$recipientLng = floatval($recipient['longitude'] ?? 0);

if ($recipientLat == 0 || $recipientLng == 0) {
    $recipientLat = 30.1798;
    $recipientLng = 66.9750;
}

// Available Listings
$availableListings = [];
$photoSelect = 'NULL AS photo_url';
$photoColumnCheck = $db->query("SHOW COLUMNS FROM listings LIKE 'photo_url'");
if ($photoColumnCheck && $photoColumnCheck->num_rows > 0) {
    $photoSelect = 'l.photo_url';
}

if ($recipientLat && $recipientLng) {
    $sql = "SELECT l.id, l.title, l.description, l.contact_phone, l.latitude, l.longitude, $photoSelect,
            u.name AS donor_name, u.id AS donor_id,
            (6371 * acos(
                cos(radians(?)) * cos(radians(l.latitude)) *
                cos(radians(l.longitude) - radians(?)) +
                sin(radians(?)) * sin(radians(l.latitude))
            )) AS distance_km
            FROM listings l
            JOIN users u ON u.id = l.donor_id
            WHERE l.status = 'published' AND u.user_type = 'donor' AND u.is_active = 1
            HAVING distance_km <= 10
            ORDER BY distance_km ASC
            LIMIT 12";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ddd', $recipientLat, $recipientLng, $recipientLat);
    $stmt->execute();
    $availableListings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Active Rescues
$stmt = $db->prepare('
    SELECT r.id, r.title, r.description, r.status, r.assigned_rider_id, r.created_at,
           r.latitude AS rescue_lat, r.longitude AS rescue_lng,
           u.name AS rider_name, u.phone AS rider_phone, u.latitude AS rider_lat, u.longitude AS rider_lng,
           u.id AS rider_id,
           d.name AS donor_name, d.phone AS donor_phone, d.id AS donor_id,
           l.latitude AS pickup_lat, l.longitude AS pickup_lng
    FROM rescues r 
    LEFT JOIN users u ON u.id = r.assigned_rider_id 
    LEFT JOIN listings l ON l.id = r.listing_id
    LEFT JOIN users d ON d.id = l.donor_id
    WHERE r.recipient_id = ? AND r.status NOT IN ("delivered", "cancelled") 
    ORDER BY r.created_at DESC
');
$stmt->bind_param('i', $recipientId);
$stmt->execute();
$activeRescues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Delivery History
$stmt = $db->prepare('SELECT r.id, r.title, r.description, r.status, r.created_at FROM rescues r WHERE r.recipient_id = ? AND r.status = "delivered" ORDER BY r.created_at DESC LIMIT 10');
$stmt->bind_param('i', $recipientId);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$activeCount = count($activeRescues);
$capacityMax = 10;
$capacityPercent = $capacityMax ? min(100, ($activeCount / $capacityMax) * 100) : 0;

$db->close();

$includeMap = true;
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .leaflet-div-icon {
        background: transparent !important;
        border: none !important;
    }
    #recipientMap {
        height: 350px;
        border-radius: 12px;
        overflow: hidden;
        background: #e8ecf1;
        border: 1px solid rgba(26,58,107,0.06);
    }
    .stat-card-recipient {
        background: white;
        border-radius: 16px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 15px rgba(0,0,0,0.06);
        transition: all 0.3s ease;
        height: 100%;
        border: 1px solid rgba(26,58,107,0.06);
    }
    .stat-card-recipient:hover { transform: translateY(-5px); box-shadow: 0 8px 30px rgba(0,0,0,0.1); }
    .stat-card-recipient .stat-icon { font-size: 2.2rem; display: block; margin-bottom: 8px; }
    .stat-card-recipient .stat-number { font-size: 2rem; font-weight: 800; color: #1a365d; }
    .stat-card-recipient .stat-label { color: #718096; font-size: 0.9rem; font-weight: 500; }
    .listing-card {
        background: white;
        border-radius: 12px;
        padding: 16px;
        border: 1px solid rgba(26,58,107,0.06);
        transition: all 0.3s ease;
        height: 100%;
        min-height: 180px;
        display: flex;
        flex-direction: column;
    }
    .listing-card:hover { border-color: #2b6cb0; box-shadow: 0 4px 20px rgba(43,108,176,0.08); transform: translateY(-3px); }
    .listing-food-image {
        width: calc(100% + 32px);
        height: 155px;
        margin: -16px -16px 14px;
        object-fit: cover;
        display: block;
        background: linear-gradient(135deg, #e6fffa, #ebf8ff);
    }
    .listing-image-placeholder {
        width: calc(100% + 32px);
        height: 155px;
        margin: -16px -16px 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #e6fffa, #ebf8ff);
        color: #2b6cb0;
        font-size: 2.5rem;
    }
    .btn-accept-food {
        background: linear-gradient(135deg, #2b6cb0, #1a365d);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.9rem;
        width: 100%;
    }
    .btn-accept-food:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(43,108,176,0.3); color: white; }
    .btn-cancel-request {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        color: white;
        border: none;
        padding: 4px 12px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.75rem;
        cursor: pointer;
    }
    .btn-request-rescue {
        background: linear-gradient(135deg, #48bb78, #276749);
        color: white;
        border: none;
        padding: 14px 30px;
        border-radius: 12px;
        font-weight: 600;
    }
    .distance-badge {
        background: #ebf8ff;
        color: #2b6cb0;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
    .status-badge.pending { background: #fef3c7; color: #b7791f; }
    .status-badge.accepted { background: #e0f2fe; color: #0369a1; }
    .status-badge.rider_assigned { background: #d1fae5; color: #065f46; }
    .status-badge.picked_up { background: #c7d2fe; color: #3730a3; }
    .status-badge.in_transit { background: #fef3c7; color: #b7791f; }
    .status-badge.delivered { background: #d1fae5; color: #065f46; }
    .status-badge.cancelled { background: #fee2e2; color: #991b1b; }
    .location-warning {
        background: #fef3c7;
        border: 1px solid #f6c23e;
        border-radius: 12px;
        padding: 12px 20px;
        color: #b7791f;
        font-weight: 500;
    }
    .eta-badge { font-size: 0.8rem; padding: 4px 12px; border-radius: 12px; display: inline-block; }
    .eta-badge.soon { 
        background: #d1fae5; 
        color: #065f46; 
        animation: pulse-eta 2s infinite; 
    }
    .eta-badge.minutes { background: #e0f2fe; color: #0369a1; }
    .eta-badge.hours { background: #fef3c7; color: #b7791f; }
    @keyframes pulse-eta {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }
    .delivery-status-card { border-left: 4px solid #2b6cb0; transition: all 0.3s ease; }
    @media (max-width: 768px) {
        .stat-card-recipient .stat-number { font-size: 1.5rem; }
        #recipientMap { height: 220px; }
    }
</style>

<div class="container py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div>
                    <h1 class="fw-bold text-primary-dark">
                        <i class="fas fa-home"></i> Welcome, <?= htmlspecialchars($recipient['name']) ?>!
                    </h1>
                    <?php if ($recipientLat && $recipientLng && $recipientLat != 30.1798): ?>
                        <p class="text-muted">
                            <i class="fas fa-map-marker-alt text-primary"></i> 
                            📍 Your location: <?= number_format($recipientLat, 4) ?>, <?= number_format($recipientLng, 4) ?>
                            <span class="badge bg-success ms-2">Live</span>
                        </p>
                    <?php else: ?>
                        <div class="location-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Location not set!</strong> 
                            Please <a href="<?= BASE_URL ?>profile.php" class="fw-bold">update your location</a>.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= BASE_URL ?>profile.php" class="btn btn-outline-primary">
                        <i class="fas fa-user-edit me-1"></i> Edit Profile
                    </a>
                    <a href="<?= BASE_URL ?>recipient/create_rescue.php" class="btn btn-request-rescue">
                        <i class="fas fa-utensils me-2"></i> Request Rescue
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Map -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold py-3">
                    <i class="fas fa-map text-primary me-2"></i> Nearby Food & Active Deliveries
                </div>
                <div class="card-body">
                    <div id="recipientMap"></div>
                    <div class="d-flex flex-wrap gap-3 mt-3">
                        <span><span class="badge bg-primary">🔵</span> You (Live)</span>
                        <span><span class="badge bg-success">🟢</span> Available Food</span>
                        <?php if (!empty($activeRescues)): ?>
                            <span><span class="badge bg-warning">🏍️</span> Rider (Live)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-4 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card-recipient">
                <span class="stat-icon">📋</span>
                <div class="stat-number"><?= $activeCount ?></div>
                <div class="stat-label">Active Requests</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card-recipient">
                <span class="stat-icon">🍽️</span>
                <div class="stat-number"><?= count($availableListings) ?></div>
                <div class="stat-label">Nearby Offers</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card-recipient">
                <span class="stat-icon">✅</span>
                <div class="stat-number"><?= count($history) ?></div>
                <div class="stat-label">Delivered</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card-recipient">
                <span class="stat-icon">📊</span>
                <div class="stat-number"><?= round($capacityPercent) ?>%</div>
                <div class="stat-label">Capacity Used</div>
            </div>
        </div>
    </div>

    <!-- Available Listings + Incoming Deliveries -->
    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold py-3">
                    <i class="fas fa-utensils text-success me-2"></i> Available Nearby
                    <?php if (!empty($availableListings)): ?>
                        <span class="badge bg-success ms-2"><?= count($availableListings) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($availableListings)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                            <p class="text-muted mb-0">No food available nearby right now.</p>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($availableListings as $listing): ?>
                                <div class="col-md-6">
                                    <div class="listing-card d-flex flex-column h-100">
                                        <?php if (!empty($listing['photo_url'])): ?>
                                            <img
                                                src="<?= BASE_URL . htmlspecialchars(ltrim($listing['photo_url'], '/')) ?>"
                                                alt="<?= htmlspecialchars($listing['title']) ?>"
                                                class="listing-food-image"
                                                onerror="this.outerHTML='<div class=\'listing-image-placeholder\'><i class=\'fas fa-utensils\'></i></div>'">
                                        <?php else: ?>
                                            <div class="listing-image-placeholder" aria-hidden="true">
                                                <i class="fas fa-utensils"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex-grow-1">
                                            <h5 class="fw-semibold"><?= htmlspecialchars($listing['title']) ?></h5>
                                            <p class="text-muted small mb-1">
                                                <i class="fas fa-store me-1"></i> <?= htmlspecialchars($listing['donor_name']) ?>
                                            </p>
                                            <p class="text-muted small mb-2">
                                                <span class="distance-badge"><?= number_format($listing['distance_km'], 1) ?> km away</span>
                                            </p>
                                            <p class="text-muted small mb-2">
                                                <?= htmlspecialchars(substr($listing['description'] ?? 'No description', 0, 80)) ?>
                                            </p>
                                        </div>
                                        <div class="mt-2">
                                            <button class="btn-accept-food" onclick="acceptRescue(<?= intval($listing['id']) ?>)">
                                                <i class="fas fa-check me-1"></i> Accept Food
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <!-- Incoming Deliveries -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-bold py-3">
                    <i class="fas fa-truck text-warning me-2"></i> Incoming Deliveries
                    <?php if (!empty($activeRescues)): ?>
                        <span class="badge bg-warning ms-2"><?= count($activeRescues) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body" style="max-height: 350px; overflow-y: auto;">
                    <?php if (empty($activeRescues)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-2x text-muted mb-2 d-block"></i>
                            <p class="text-muted mb-0">No active deliveries</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activeRescues as $rescue): 
                            $eta = '';
                            $etaClass = 'soon';
                            
                            // Get coordinates
                            $pickupLat = floatval($rescue['pickup_lat'] ?? 0);
                            $pickupLng = floatval($rescue['pickup_lng'] ?? 0);
                            $riderLat = floatval($rescue['rider_lat'] ?? 0);
                            $riderLng = floatval($rescue['rider_lng'] ?? 0);
                            
                            // Calculate ETA based on rescue status
                            if ($rescue['status'] === 'in_transit' && $riderLat && $riderLng) {
                                // Rider is in transit - calculate distance from rider to recipient
                                $distance = calculate_distance_km($riderLat, $riderLng, $recipientLat, $recipientLng);
                                // Use average speed of 30 km/h for in-transit deliveries
                                $etaMinutes = round(($distance / 30) * 60);
                                
                                if ($etaMinutes <= 0) {
                                    $eta = 'Arriving now';
                                    $etaClass = 'soon';
                                } elseif ($etaMinutes < 5) {
                                    $eta = $etaMinutes . ' min';
                                    $etaClass = 'soon';
                                } elseif ($etaMinutes < 60) {
                                    $eta = $etaMinutes . ' min';
                                    $etaClass = 'minutes';
                                } elseif ($etaMinutes >= 60) {
                                    $eta = round($etaMinutes / 60, 1) . ' hr';
                                    $etaClass = 'hours';
                                }
                            } elseif ($rescue['status'] === 'picked_up' && $pickupLat && $pickupLng) {
                                // Rider has picked up but not started transit yet
                                $distance = calculate_distance_km($pickupLat, $pickupLng, $recipientLat, $recipientLng);
                                $etaMinutes = round(($distance / 25) * 60) + 5; // Add 5 min for pickup completion
                                
                                if ($etaMinutes < 60) {
                                    $eta = $etaMinutes . ' min';
                                    $etaClass = 'minutes';
                                } elseif ($etaMinutes >= 60) {
                                    $eta = round($etaMinutes / 60, 1) . ' hr';
                                    $etaClass = 'hours';
                                }
                            } elseif ($rescue['status'] === 'accepted' || $rescue['status'] === 'rider_assigned') {
                                // Rider assigned but hasn't picked up yet
                                if ($pickupLat && $pickupLng && $riderLat && $riderLng) {
                                    // Calculate: rider to pickup + pickup to recipient
                                    $distanceToPickup = calculate_distance_km($riderLat, $riderLng, $pickupLat, $pickupLng);
                                    $distanceToRecipient = calculate_distance_km($pickupLat, $pickupLng, $recipientLat, $recipientLng);
                                    $totalDistance = $distanceToPickup + $distanceToRecipient;
                                    $etaMinutes = round(($totalDistance / 25) * 60) + 10; // Add 10 min buffer
                                    
                                    if ($etaMinutes < 60) {
                                        $eta = $etaMinutes . ' min';
                                        $etaClass = 'minutes';
                                    } elseif ($etaMinutes >= 60) {
                                        $eta = round($etaMinutes / 60, 1) . ' hr';
                                        $etaClass = 'hours';
                                    }
                                } else {
                                    $eta = 'Calculating...';
                                    $etaClass = 'soon';
                                }
                            } else {
                                $eta = 'Pending';
                                $etaClass = 'soon';
                            }
                        ?>
                            <div class="delivery-status-card border-bottom py-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="fw-bold mb-1"><?= htmlspecialchars($rescue['title']) ?></h6>
                                        <p class="text-muted small mb-1"><?= htmlspecialchars(substr($rescue['description'] ?? '', 0, 60)) ?></p>
                                        <span class="status-badge <?= $rescue['status'] ?>">
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $rescue['status']))) ?>
                                        </span>
                                        <?php if ($rescue['rider_name']): ?>
                                            <span class="text-muted small ms-2">
                                                <i class="fas fa-motorcycle me-1"></i> <?= htmlspecialchars($rescue['rider_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($eta): ?>
                                            <span class="eta-badge <?= $etaClass ?> ms-2" title="Estimated time of arrival">
                                                ⏱️ ETA: <?= $eta ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted d-block"><?= timeAgo($rescue['created_at']) ?></small>
                                        <a href="<?= BASE_URL ?>track/<?= intval($rescue['id']) ?>" class="btn btn-sm btn-outline-primary mt-1">
                                            <i class="fas fa-map"></i> Track
                                        </a>
                                        <?php if ($rescue['status'] !== 'delivered' && $rescue['status'] !== 'cancelled'): ?>
                                            <button class="btn-cancel-request mt-1" onclick="cancelRescue(<?= intval($rescue['id']) ?>)">
                                                <i class="fas fa-times me-1"></i> Cancel
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Delivery History -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold py-3">
                    <i class="fas fa-history text-muted me-2"></i> Recent Deliveries
                </div>
                <div class="card-body" style="max-height: 250px; overflow-y: auto;">
                    <?php if (empty($history)): ?>
                        <div class="text-center py-3">
                            <i class="fas fa-clock fa-2x text-muted mb-2 d-block"></i>
                            <p class="text-muted mb-0">No delivery history yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($history as $item): ?>
                            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($item['title']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars(substr($item['description'] ?? '', 0, 40)) ?></small>
                                </div>
                                <small class="text-muted"><?= timeAgo($item['created_at']) ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const recipientId = <?= json_encode($recipientId) ?>;
    const recipientName = <?= json_encode($recipient['name']) ?>;
    const listings = <?= json_encode($availableListings) ?>;
    const rescues = <?= json_encode($activeRescues) ?>;
    const baseUrl = <?= json_encode(BASE_URL) ?>;

    const map = L.map('recipientMap').setView([30.1798, 66.9750], 13);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
    
    const bounds = L.latLngBounds();
    let liveMarkers = {};

    // Listing markers (green)
    const listingIcon = L.divIcon({
        className: '',
        html: '<div style="background:#48bb78;width:14px;height:14px;border-radius:50%;border:2px solid white;box-shadow:0 2px 10px rgba(72,187,120,0.4);"></div>',
        iconSize: [14, 14],
        iconAnchor: [7, 7]
    });
    
    listings.forEach(function(listing) {
        if (listing.latitude && listing.longitude) {
            L.marker([parseFloat(listing.latitude), parseFloat(listing.longitude)], { icon: listingIcon })
                .addTo(map)
                .bindPopup('<strong>🍽️ ' + escapeHtml(listing.title) + '</strong><br><small>' + escapeHtml(listing.donor_name) + '</small>');
            bounds.extend([parseFloat(listing.latitude), parseFloat(listing.longitude)]);
        }
    });

    // ============================================
    // LIVE LOCATION FOR RECIPIENT
    // ============================================
    async function fetchRecipientLiveLocation() {
        try {
            const res = await fetch(baseUrl + 'api/get_live_location.php?user_id=' + recipientId, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            
            if (!data.success || !data.latitude || !data.longitude) return;
            
            const lat = parseFloat(data.latitude);
            const lng = parseFloat(data.longitude);
            
            console.log('📍 Recipient LIVE:', lat, lng);
            
            const recipientIcon = L.divIcon({
                className: '',
                html: '<div style="background:#2b6cb0;width:20px;height:20px;border-radius:50%;border:3px solid white;box-shadow:0 2px 15px rgba(43,108,176,0.5);animation:pulse-live 2s infinite;"></div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10]
            });
            
            if (liveMarkers['recipient']) {
                liveMarkers['recipient'].setLatLng([lat, lng]);
            } else {
                liveMarkers['recipient'] = L.marker([lat, lng], { icon: recipientIcon })
                    .addTo(map)
                    .bindPopup('<strong>🏠 You</strong><br>' + escapeHtml(recipientName));
            }
            
            bounds.extend([lat, lng]);
            
            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
            }
            
        } catch (e) {
            console.log('Recipient live error:', e);
        }
    }

    // ============================================
    // LIVE LOCATION FOR RIDERS
    // ============================================
    async function fetchRiderLiveLocation(riderId, riderName) {
        if (!riderId) return;
        
        try {
            const res = await fetch(baseUrl + 'api/get_live_location.php?user_id=' + riderId, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            
            if (!data.success || !data.latitude || !data.longitude) return;
            
            const lat = parseFloat(data.latitude);
            const lng = parseFloat(data.longitude);
            
            console.log('📍 Rider LIVE:', data.name, lat, lng);
            
            const riderIcon = L.divIcon({
                className: '',
                html: '<div style="font-size:28px; line-height:1; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.3));">🏍️</div>',
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            });
            
            if (liveMarkers['rider_' + riderId]) {
                liveMarkers['rider_' + riderId].setLatLng([lat, lng]);
            } else {
                liveMarkers['rider_' + riderId] = L.marker([lat, lng], { icon: riderIcon })
                    .addTo(map)
                    .bindPopup('<strong>🏍️ ' + escapeHtml(data.name || riderName) + '</strong>');
            }
            
            bounds.extend([lat, lng]);
            
            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
            }
            
        } catch (e) {
            console.log('Rider live error:', e);
        }
    }

    // Start live tracking for recipient
    fetchRecipientLiveLocation();
    setInterval(fetchRecipientLiveLocation, 5000);

    // Start live tracking for all active riders
    rescues.forEach(function(rescue) {
        if (rescue.rider_id) {
            fetchRiderLiveLocation(rescue.rider_id, rescue.rider_name);
            setInterval(function() {
                fetchRiderLiveLocation(rescue.rider_id, rescue.rider_name);
            }, 5000);
        }
    });

    // Initial fit
    setTimeout(function() {
        map.invalidateSize();
        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
        }
    }, 500);

    L.control.scale({ position: 'bottomright', metric: true, imperial: false }).addTo(map);
});

function acceptRescue(listingId) {
    if (!confirm('Accept this food rescue?')) return;
    
    const listings = <?= json_encode($availableListings) ?>;
    const listing = listings.find(l => l.id === listingId);
    if (!listing) { alert('Listing not found'); return; }
    
    fetch('<?= BASE_URL ?>api/rescues.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            recipient_id: <?= $recipientId ?>,
            listing_id: listingId,
            title: listing.title,
            description: listing.description || 'Food rescue request',
            contact_phone: listing.contact_phone || '',
            latitude: listing.latitude,
            longitude: listing.longitude
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success === true) {
            alert('✅ Food accepted!');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.error || 'Unable to accept.'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('✅ Food request submitted!');
        location.reload();
    });
}

function cancelRescue(rescueId) {
    if (!confirm('Cancel this rescue request?')) return;
    
    fetch('<?= BASE_URL ?>api/rescues.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _method: 'DELETE', rescue_id: rescueId }),
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success === true) {
            alert('✅ Rescue cancelled.');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.error || 'Unable to cancel.'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('❌ Network error.');
    });
}

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