<?php
// ============================================
// NEKIROT QUETTA - DONOR DASHBOARD
// With live location for donor and rider
// ============================================

include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user = current_user();
if ($user['type'] !== 'donor') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$db = get_db_connection();
$donorId = intval($user['id']);

// Get donor info with location
$stmt = $db->prepare('SELECT id, name, phone, latitude, longitude FROM users WHERE id = ? AND user_type = ? LIMIT 1');
$type = 'donor';
$stmt->bind_param('is', $donorId, $type);
$stmt->execute();
$donor = $stmt->get_result()->fetch_assoc();
if (!$donor) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$donorLat = floatval($donor['latitude'] ?? 0);
$donorLng = floatval($donor['longitude'] ?? 0);

if ($donorLat == 0 || $donorLng == 0) {
    $donorLat = 30.1798;
    $donorLng = 66.9750;
}

// Stats
$stmt = $db->prepare("SELECT COUNT(*) AS total_active FROM listings WHERE donor_id = ? AND status IN ('published', 'matched')");
$stmt->bind_param('i', $donorId);
$stmt->execute();
$result = $stmt->get_result();
$stats = $result->fetch_assoc();
$activeListings = intval($stats['total_active']);

$stmt = $db->prepare("SELECT COUNT(*) AS total_listings, SUM(status = 'completed') AS total_completed FROM listings WHERE donor_id = ?");
$stmt->bind_param('i', $donorId);
$stmt->execute();
$result = $stmt->get_result();
$summary = $result->fetch_assoc();
$totalListings = intval($summary['total_listings']);
$totalCompleted = intval($summary['total_completed']);

// Get active rescues
$stmt = $db->prepare('
    SELECT r.id, r.title, r.description, r.status, r.created_at,
           u.name AS rider_name, u.phone AS rider_phone, u.latitude AS rider_lat, u.longitude AS rider_lng, u.id AS rider_id,
           rec.name AS recipient_name, rec.phone AS recipient_phone,
           r.latitude, r.longitude
    FROM rescues r
    LEFT JOIN users u ON u.id = r.assigned_rider_id
    LEFT JOIN users rec ON rec.id = r.recipient_id
    LEFT JOIN listings l ON l.id = r.listing_id
    WHERE l.donor_id = ? AND r.status NOT IN ("delivered", "cancelled")
    ORDER BY r.created_at DESC
');
$stmt->bind_param('i', $donorId);
$stmt->execute();
$activeRescues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get listings
$stmt = $db->prepare('
    SELECT id, title, description, quantity, pickup_deadline, status, created_at, latitude, longitude 
    FROM listings 
    WHERE donor_id = ? 
    ORDER BY created_at DESC 
    LIMIT 8
');
$stmt->bind_param('i', $donorId);
$stmt->execute();
$result = $stmt->get_result();
$listings = $result->fetch_all(MYSQLI_ASSOC);

$hasDeadline = false;
$columnCheck = $db->query("SHOW COLUMNS FROM listings LIKE 'pickup_deadline'");
if ($columnCheck && $columnCheck->num_rows > 0) {
    $hasDeadline = true;
}

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
    #donorMap {
        height: 350px;
        border-radius: 12px;
        overflow: hidden;
        background: #e8ecf1;
        border: 1px solid rgba(26,58,107,0.06);
    }
    .stat-card-donor {
        background: white;
        border-radius: 16px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 15px rgba(0,0,0,0.06);
        transition: all 0.3s ease;
        height: 100%;
        border: 1px solid rgba(26,58,107,0.06);
    }
    .stat-card-donor:hover { transform: translateY(-5px); box-shadow: 0 8px 30px rgba(0,0,0,0.1); }
    .stat-card-donor .stat-icon { font-size: 2.2rem; display: block; margin-bottom: 8px; }
    .stat-card-donor .stat-number { font-size: 2rem; font-weight: 800; color: #1a365d; }
    .stat-card-donor .stat-label { color: #718096; font-size: 0.9rem; font-weight: 500; }
    .listing-item {
        background: white;
        border-radius: 12px;
        padding: 16px;
        border: 1px solid rgba(26,58,107,0.06);
        transition: all 0.3s ease;
        margin-bottom: 12px;
    }
    .listing-item:hover { border-color: #2b6cb0; box-shadow: 0 4px 20px rgba(43,108,176,0.08); transform: translateY(-2px); }
    .deadline-badge {
        background: #fef3c7;
        color: #b7791f;
        padding: 2px 10px;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-block;
    }
    .deadline-badge.urgent { background: #fee2e2; color: #991b1b; }
    .deadline-badge.soon { background: #fef3c7; color: #b7791f; }
    .btn-broadcast-donor {
        background: linear-gradient(135deg, #2b6cb0, #1a365d);
        color: white;
        border: none;
        padding: 14px 30px;
        border-radius: 12px;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    .btn-broadcast-donor:hover { transform: translateY(-2px); box-shadow: 0 4px 25px rgba(43,108,176,0.3); color: white; }
    .btn-edit-profile {
        background: transparent;
        color: #2b6cb0;
        border: 2px solid #2b6cb0;
        padding: 10px 24px;
        border-radius: 12px;
        font-weight: 600;
    }
    .btn-edit-profile:hover { background: #2b6cb0; color: white; }
    .status-badge {
        padding: 4px 14px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-badge.published { background: #d1fae5; color: #065f46; }
    .status-badge.matched { background: #fef3c7; color: #b7791f; }
    .status-badge.completed { background: #e0f2fe; color: #0369a1; }
    .status-badge.cancelled { background: #fee2e2; color: #991b1b; }
    .status-badge.in_transit { background: #e0f2fe; color: #0369a1; }
    .status-badge.rider_assigned { background: #d1fae5; color: #065f46; }
    .status-badge.accepted { background: #fef3c7; color: #b7791f; }
    .location-warning {
        background: #fef3c7;
        border: 1px solid #f6c23e;
        border-radius: 12px;
        padding: 12px 20px;
        color: #b7791f;
        font-weight: 500;
    }
    .eta-badge { font-size: 0.8rem; padding: 4px 12px; border-radius: 12px; display: inline-block; }
    .eta-badge.soon { background: #d1fae5; color: #065f46; }
    .eta-badge.minutes { background: #e0f2fe; color: #0369a1; }
    .eta-badge.hours { background: #fef3c7; color: #b7791f; }
    .delivery-card {
        border-left: 4px solid #2b6cb0;
        transition: all 0.3s ease;
        background: white;
        border-radius: 12px;
        padding: 16px;
        border: 1px solid rgba(26,58,107,0.06);
        margin-bottom: 12px;
    }
    .live-badge {
        background: #d1fae5;
        color: #065f46;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 700;
        animation: pulse-live 2s infinite;
    }
    @keyframes pulse-live { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    @media (max-width: 768px) {
        .stat-card-donor .stat-number { font-size: 1.5rem; }
        #donorMap { height: 220px; }
    }
</style>

<div class="container py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div>
                    <h1 class="fw-bold text-primary-dark">
                        <i class="fas fa-store"></i> Welcome, <?= htmlspecialchars($donor['name']) ?>!
                    </h1>
                    <?php if ($donorLat && $donorLng && $donorLat != 30.1798): ?>
                        <p class="text-muted">
                            <i class="fas fa-map-marker-alt text-primary"></i> 
                            📍 Your location: <?= number_format($donorLat, 4) ?>, <?= number_format($donorLng, 4) ?>
                            <span class="live-badge ms-2">LIVE</span>
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
                    <a href="<?= BASE_URL ?>profile.php" class="btn-edit-profile">
                        <i class="fas fa-user-edit me-1"></i> Edit Profile
                    </a>
                    <a href="<?= BASE_URL ?>donor/broadcast.php" class="btn-broadcast-donor">
                        <i class="fas fa-broadcast me-2"></i> Broadcast Food
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
                    <i class="fas fa-map text-primary me-2"></i> Your Location & Active Listings
                </div>
                <div class="card-body">
                    <div id="donorMap"></div>
                    <div class="d-flex flex-wrap gap-3 mt-3">
                        <span><span class="badge bg-primary">🔵</span> You (Donor - Live)</span>
                        <span><span class="badge bg-success">🟢</span> Your Active Listings</span>
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
            <div class="stat-card-donor">
                <span class="stat-icon">📋</span>
                <div class="stat-number"><?= $activeListings ?></div>
                <div class="stat-label">Active Listings</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card-donor">
                <span class="stat-icon">📦</span>
                <div class="stat-number"><?= $totalListings ?></div>
                <div class="stat-label">Total Listings</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card-donor">
                <span class="stat-icon">✅</span>
                <div class="stat-number"><?= $totalCompleted ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card-donor">
                <span class="stat-icon">⭐</span>
                <div class="stat-number"><?= $totalCompleted * 10 + $activeListings ?></div>
                <div class="stat-label">Neki Score</div>
            </div>
        </div>
    </div>

    <!-- Active Deliveries -->
    <?php if (!empty($activeRescues)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold py-3">
                    <i class="fas fa-truck text-warning me-2"></i> Active Deliveries
                    <span class="badge bg-warning ms-2"><?= count($activeRescues) ?></span>
                </div>
                <div class="card-body">
                    <?php foreach ($activeRescues as $rescue): 
                        $eta = '';
                        $etaClass = 'soon';
                        if ($rescue['rider_lat'] && $rescue['rider_lng'] && $rescue['latitude'] && $rescue['longitude']) {
                            $distance = calculate_distance_km($rescue['rider_lat'], $rescue['rider_lng'], $rescue['latitude'], $rescue['longitude']);
                            $etaMinutes = round(($distance / 20) * 60);
                            if ($etaMinutes > 0 && $etaMinutes < 60) {
                                $eta = $etaMinutes . ' min';
                                $etaClass = 'minutes';
                            } elseif ($etaMinutes >= 60) {
                                $eta = round($etaMinutes / 60) . ' hr';
                                $etaClass = 'hours';
                            } else {
                                $eta = 'Soon';
                                $etaClass = 'soon';
                            }
                        }
                    ?>
                        <div class="delivery-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <h5 class="fw-bold mb-1"><?= htmlspecialchars($rescue['title']) ?></h5>
                                    <p class="text-muted small mb-2"><?= htmlspecialchars(substr($rescue['description'] ?? '', 0, 80)) ?></p>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="status-badge <?= $rescue['status'] ?>">
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $rescue['status']))) ?>
                                        </span>
                                        <?php if ($rescue['rider_name']): ?>
                                            <span class="text-muted small">
                                                <i class="fas fa-motorcycle me-1"></i> <?= htmlspecialchars($rescue['rider_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($eta): ?>
                                            <span class="eta-badge <?= $etaClass ?>">⏱️ <?= $eta ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-2">
                                        <small class="text-muted">
                                            <i class="fas fa-user me-1"></i> Recipient: <?= htmlspecialchars($rescue['recipient_name'] ?? 'N/A') ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted d-block"><?= timeAgo($rescue['created_at']) ?></small>
                                    <a href="<?= BASE_URL ?>track/<?= intval($rescue['id']) ?>" class="btn btn-outline-primary btn-sm mt-1">
                                        <i class="fas fa-map"></i> Track
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Listings -->
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold py-3">
                    <i class="fas fa-list text-primary me-2"></i> Your Active Listings
                    <?php if (!empty($listings)): ?>
                        <span class="badge bg-primary ms-2"><?= count($listings) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($listings)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                            <p class="text-muted mb-0">No active listings yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($listings as $listing): ?>
                            <div class="listing-item">
                                <div class="d-flex align-items-start justify-content-between gap-3">
                                    <div>
                                        <h5 class="fw-bold mb-1"><?= htmlspecialchars($listing['title']) ?></h5>
                                        <p class="text-muted small mb-2"><?= htmlspecialchars(substr($listing['description'] ?? '', 0, 100)) ?></p>
                                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                            <?php if (isset($listing['quantity']) && $listing['quantity'] > 0): ?>
                                                <span class="badge bg-info text-white"><i class="fas fa-box me-1"></i> <?= $listing['quantity'] ?> plates</span>
                                            <?php endif; ?>
                                            <?php if ($hasDeadline && !empty($listing['pickup_deadline'])): 
                                                $deadline = strtotime($listing['pickup_deadline']);
                                                $now = time();
                                                $hoursLeft = round(($deadline - $now) / 3600);
                                                $daysLeft = round(($deadline - $now) / 86400);
                                                if ($hoursLeft < 0) {
                                                    $deadlineClass = 'urgent';
                                                    $deadlineText = '⏰ Deadline passed';
                                                } elseif ($hoursLeft < 24) {
                                                    $deadlineClass = 'urgent';
                                                    $deadlineText = '⏰ ' . $hoursLeft . ' hours left';
                                                } elseif ($daysLeft < 3) {
                                                    $deadlineClass = 'soon';
                                                    $deadlineText = '⏰ ' . $daysLeft . ' days left';
                                                } else {
                                                    $deadlineClass = '';
                                                    $deadlineText = '📅 ' . date('M d, Y h:i A', $deadline);
                                                }
                                            ?>
                                                <span class="deadline-badge <?= $deadlineClass ?>"><i class="fas fa-clock me-1"></i> <?= $deadlineText ?></span>
                                            <?php endif; ?>
                                            <span class="status-badge <?= $listing['status'] ?>"><?= htmlspecialchars(ucfirst($listing['status'])) ?></span>
                                        </div>
                                        <small class="text-muted"><i class="fas fa-clock me-1"></i> <?= timeAgo($listing['created_at']) ?></small>
                                    </div>
                                    <div class="text-end">
                                        <a href="<?= BASE_URL ?>donor/listing.php?id=<?= $listing['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-bold py-3">
                    <i class="fas fa-bolt text-warning me-2"></i> Quick Actions
                </div>
                <div class="card-body">
                    <div class="d-grid gap-3">
                        <a href="<?= BASE_URL ?>donor/broadcast.php" class="btn btn-broadcast-donor">
                            <i class="fas fa-broadcast me-2"></i> Broadcast New Food
                        </a>
                        <a href="<?= BASE_URL ?>donor/listings.php" class="btn btn-outline-primary">
                            <i class="fas fa-list me-2"></i> View All Listings
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const donorId = <?= json_encode($donorId) ?>;
    const donorName = <?= json_encode($donor['name']) ?>;
    const listings = <?= json_encode($listings) ?>;
    const rescues = <?= json_encode($activeRescues) ?>;
    const baseUrl = <?= json_encode(BASE_URL) ?>;

    const map = L.map('donorMap').setView([30.1798, 66.9750], 13);
    
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
                .bindPopup('<strong>🍽️ ' + escapeHtml(listing.title) + '</strong><br><small>Status: ' + escapeHtml(listing.status) + '</small>');
            bounds.extend([parseFloat(listing.latitude), parseFloat(listing.longitude)]);
        }
    });

    // ============================================
    // LIVE LOCATION FOR DONOR (Blue Circle)
    // ============================================
    async function fetchDonorLiveLocation() {
        try {
            const res = await fetch(baseUrl + 'api/get_live_location.php?user_id=' + donorId, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            
            if (!data.success || !data.latitude || !data.longitude) return;
            
            const lat = parseFloat(data.latitude);
            const lng = parseFloat(data.longitude);
            
            console.log('📍 Donor LIVE:', lat, lng);
            
            const donorIcon = L.divIcon({
                className: '',
                html: '<div style="background:#2b6cb0;width:20px;height:20px;border-radius:50%;border:3px solid white;box-shadow:0 2px 15px rgba(43,108,176,0.5);animation:pulse-live 2s infinite;"></div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10]
            });
            
            if (liveMarkers['donor']) {
                liveMarkers['donor'].setLatLng([lat, lng]);
            } else {
                liveMarkers['donor'] = L.marker([lat, lng], { icon: donorIcon })
                    .addTo(map)
                    .bindPopup('<strong>🏢 You (Donor)</strong><br>' + escapeHtml(donorName));
            }
            
            bounds.extend([lat, lng]);
            
            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
            }
            
        } catch (e) {
            console.log('Donor live error:', e);
        }
    }

    // ============================================
    // LIVE LOCATION FOR RIDERS (Bike Icon)
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
                html: '<div style="font-size:28px; line-height:1; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.3)); animation: bike-bounce 2s infinite;">🏍️</div>',
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

    // Start live tracking
    fetchDonorLiveLocation();
    setInterval(fetchDonorLiveLocation, 5000);

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