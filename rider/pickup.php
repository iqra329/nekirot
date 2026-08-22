<?php
// ============================================
// NEKIROT QUETTA - PICKUP CONFIRMATION
// With live rider location tracking
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

$rescueId = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($rescueId <= 0) {
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

$db = get_db_connection();

// Get rider location and info
$stmt = $db->prepare('SELECT id, name, latitude, longitude FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$rider = $stmt->get_result()->fetch_assoc();

$riderId = intval($rider['id'] ?? $user['id']);
$riderLat = floatval($rider['latitude'] ?? 0);
$riderLng = floatval($rider['longitude'] ?? 0);
$riderName = $rider['name'] ?? 'Rider';

// Fetch rescue, recipient, donor, and pickup details
$stmt = $db->prepare(
    'SELECT r.*, 
            u.name AS recipient_name, u.phone AS recipient_phone, 
            u.latitude AS recipient_lat, u.longitude AS recipient_lng,
            l.id AS listing_id, 
            l.latitude AS donor_lat, l.longitude AS donor_lng,
            l.title AS food_title,
            d.name AS donor_name, d.phone AS donor_phone,
            d.id AS donor_id, u.id AS recipient_id,
            d.latitude AS donor_user_lat, d.longitude AS donor_user_lng
     FROM rescues r
     LEFT JOIN users u ON u.id = r.recipient_id
     LEFT JOIN listings l ON l.id = r.listing_id
     LEFT JOIN users d ON d.id = l.donor_id
     WHERE r.id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $rescueId);
$stmt->execute();
$rescue = $stmt->get_result()->fetch_assoc();

if (!$rescue) {
    $db->close();
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

// Protect page
$assignedRiderId = intval($rescue['assigned_rider_id'] ?? 0);
if ($assignedRiderId > 0 && $assignedRiderId !== intval($user['id'])) {
    $db->close();
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

$status = strtolower(trim($rescue['status'] ?? 'pending'));
$canPickup = ($status === 'accepted');
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canPickup) {
        $error = 'This delivery cannot be confirmed for pickup because it is not in accepted status. Current status: ' . $status;
    } elseif ($riderLat === 0 || $riderLng === 0) {
        $error = 'Your saved location is missing. Please update your profile with your current location before confirming pickup.';
    } else {
        $db->begin_transaction();
        try {
            $newStatus = 'picked_up';
            $update = $db->prepare('UPDATE rescues SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?');
            $update->bind_param('sis', $newStatus, $rescueId, $status);
            $update->execute();

            if ($update->affected_rows !== 1) {
                throw new Exception('Unable to update rescue status.');
            }

            $tracking = $db->prepare(
                'INSERT INTO tracking (rescue_id, rider_id, latitude, longitude, tracked_at) VALUES (?, ?, ?, ?, NOW())'
            );
            $tracking->bind_param('iidd', $rescueId, $user['id'], $riderLat, $riderLng);
            $tracking->execute();

            $db->commit();
            header('Location: ' . BASE_URL . 'rider/dashboard.php?pickup_completed=1');
            exit;
        } catch (Exception $e) {
            $db->rollback();
            $error = 'Unable to confirm pickup. ' . $e->getMessage();
        }
    }
}

$db->close();

// ============================================
// GET LOCATIONS
// ============================================
$donorLat = floatval($rescue['donor_lat'] ?? 0);
$donorLng = floatval($rescue['donor_lng'] ?? 0);

if ($donorLat == 0 || $donorLng == 0) {
    $donorLat = floatval($rescue['donor_user_lat'] ?? 0);
    $donorLng = floatval($rescue['donor_user_lng'] ?? 0);
}

if ($donorLat == 0 || $donorLng == 0) {
    $donorLat = floatval($rescue['latitude'] ?? 0);
    $donorLng = floatval($rescue['longitude'] ?? 0);
}

$recipientLat = floatval($rescue['recipient_lat'] ?? 0);
$recipientLng = floatval($rescue['recipient_lng'] ?? 0);

if ($recipientLat == 0 || $recipientLng == 0) {
    $recipientLat = floatval($rescue['latitude'] ?? 0);
    $recipientLng = floatval($rescue['longitude'] ?? 0);
}

$pickupLat = $donorLat;
$pickupLng = $donorLng;

$donorId = intval($rescue['donor_id'] ?? 0);
$recipientId = intval($rescue['recipient_id'] ?? 0);

// Calculate distance
$distance = 0;
$eta = 'Unknown';
$hasDistance = false;

if ($riderLat != 0 && $riderLng != 0 && $recipientLat != 0 && $recipientLng != 0) {
    $distance = calculate_distance_km($riderLat, $riderLng, $recipientLat, $recipientLng);
    $hasDistance = true;
    $etaMinutes = round(($distance / 20) * 60);
    if ($etaMinutes < 60) {
        $eta = $etaMinutes . ' min';
    } elseif ($etaMinutes < 120) {
        $eta = round($etaMinutes / 60) . ' hr';
    } else {
        $eta = round($etaMinutes / 60) . ' hrs';
    }
}

$donorName = $rescue['donor_name'] ?? 'Donor';
$donorPhone = $rescue['donor_phone'] ?? 'N/A';
$recipientName = $rescue['recipient_name'] ?? 'Recipient';
$recipientPhone = $rescue['recipient_phone'] ?? 'N/A';

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
    #pickupMap {
        height: 400px !important;
        width: 100% !important;
        border-radius: 12px;
        border: 2px solid #e8e8f0;
        background: #f0f0f0;
        display: block !important;
        position: relative !important;
        z-index: 1 !important;
    }
    .leaflet-control-zoom {
        border: none !important;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1) !important;
    }
    .leaflet-control-zoom a {
        background: white !important;
        color: #1a1a2e !important;
        font-weight: 600 !important;
        border: none !important;
    }
    .leaflet-control-zoom a:hover {
        background: #0d6efd !important;
        color: white !important;
    }
    .pickup-card {
        border-radius: 16px;
        border: 1px solid rgba(26,58,107,0.08);
        box-shadow: 0 12px 35px rgba(15, 23, 42, 0.04);
        background: white;
    }
    .pickup-card .card-header {
        background: #ffffff;
        border-bottom: 1px solid rgba(226,232,240,0.8);
        font-weight: 700;
        padding: 16px 20px;
    }
    .pickup-detail {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid rgba(226,232,240,0.8);
    }
    .pickup-detail:last-child {
        border-bottom: none;
    }
    .pickup-label {
        color: #4a5568;
        font-weight: 600;
    }
    .pickup-value {
        color: #1a365d;
        font-weight: 500;
        text-align: right;
    }
    .pickup-value.unknown {
        color: #b7791f;
        font-style: italic;
    }
    .btn-pickup {
        background: linear-gradient(135deg, #48bb78, #2f855a);
        color: white;
        border: none;
        padding: 14px 24px;
        border-radius: 16px;
        font-weight: 700;
        font-size: 1.1rem;
        transition: all 0.3s ease;
        width: 100%;
    }
    .btn-pickup:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 24px rgba(72,187,120,0.3);
        color: white;
    }
    .btn-pickup:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 999px;
        font-weight: 700;
    }
    .status-badge.accepted { background: #e0f2fe; color: #0369a1; }
    .status-badge.picked_up { background: #d1fae5; color: #065f46; }
    .alert-success,
    .alert-danger {
        border-radius: 14px;
    }
    .map-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-top: 16px;
    }
    .legend-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 16px;
        background: #f8fafc;
        border-radius: 14px;
        border: 1px solid rgba(66,153,225,0.12);
        color: #2d3748;
        font-size: 0.9rem;
    }
    .marker-chip {
        width: 14px;
        height: 14px;
        border-radius: 50%;
        display: inline-block;
        box-shadow: 0 1px 4px rgba(0,0,0,0.14);
    }
    .marker-donor { background: #48bb78; }
    .marker-recipient { background: #ed8936; }
    .custom-marker-donor {
        background: #48bb78;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 2px 10px rgba(72,187,120,0.4);
    }
    .custom-marker-recipient {
        background: #ed8936;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 2px 10px rgba(237,137,54,0.4);
    }
    @media (max-width: 768px) {
        #pickupMap {
            height: 250px !important;
        }
    }
</style>

<div class="container py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                <div>
                    <h1 class="h3 fw-bold">Pickup Confirmation</h1>
                    <p class="text-muted mb-0">Confirm that you have collected the food from the donor.</p>
                </div>
                <div>
                    <span class="status-badge <?= htmlspecialchars($status) ?>">
                        <?= htmlspecialchars(str_replace('_', ' ', ucfirst($status))) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="row gx-4 gy-4">
        <div class="col-xl-7">
            <div class="pickup-card p-4">
                <div class="card-header mb-3">Delivery Details</div>
                
                <div class="pickup-detail">
                    <div class="pickup-label">Food</div>
                    <div class="pickup-value"><?= htmlspecialchars($rescue['food_title'] ?? $rescue['title'] ?? 'N/A') ?></div>
                </div>
                <div class="pickup-detail">
                    <div class="pickup-label">Donor</div>
                    <div class="pickup-value"><?= htmlspecialchars($donorName) ?></div>
                </div>
                <div class="pickup-detail">
                    <div class="pickup-label">Donor Phone</div>
                    <div class="pickup-value"><?= htmlspecialchars($donorPhone) ?></div>
                </div>
                <div class="pickup-detail">
                    <div class="pickup-label">Recipient</div>
                    <div class="pickup-value"><?= htmlspecialchars($recipientName) ?></div>
                </div>
                <div class="pickup-detail">
                    <div class="pickup-label">Recipient Phone</div>
                    <div class="pickup-value"><?= htmlspecialchars($recipientPhone) ?></div>
                </div>
                <div class="pickup-detail">
                    <div class="pickup-label">Rider</div>
                    <div class="pickup-value"><?= htmlspecialchars($riderName) ?></div>
                </div>
                
                <div class="card-header mb-3 mt-4">Location Details</div>
                
                <div class="pickup-detail">
                    <div class="pickup-label">📍 Pickup Location (Donor)</div>
                    <div class="pickup-value">
                        <?php if ($pickupLat != 0 && $pickupLng != 0): ?>
                            <?= number_format($pickupLat, 5) ?>, <?= number_format($pickupLng, 5) ?>
                        <?php else: ?>
                            <span class="pickup-value unknown">⚠️ Location not set</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="pickup-detail">
                    <div class="pickup-label">🏠 Recipient Location</div>
                    <div class="pickup-value">
                        <?php if ($recipientLat != 0 && $recipientLng != 0): ?>
                            <?= number_format($recipientLat, 5) ?>, <?= number_format($recipientLng, 5) ?>
                        <?php else: ?>
                            <span class="pickup-value unknown">⚠️ Location not set</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Live Rider Location Fields -->
                <div class="pickup-detail">
                    <div class="pickup-label">🏍️ Your Current Location</div>
                    <div class="pickup-value" id="riderLocationDisplay">Loading...</div>
                </div>
                <div class="pickup-detail">
                    <div class="pickup-label">📍 Your Coordinates (Live)</div>
                    <div class="pickup-value" id="riderCoordsDisplay">Fetching live location...</div>
                </div>
                
                <div class="pickup-detail">
                    <div class="pickup-label">📏 Distance to Recipient</div>
                    <div class="pickup-value">
                        <?php if ($hasDistance): ?>
                            <?= number_format($distance, 1) ?> km
                        <?php else: ?>
                            <span class="pickup-value unknown">⚠️ Unable to calculate</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="pickup-detail">
                    <div class="pickup-label">⏱️ Estimated ETA</div>
                    <div class="pickup-value">
                        <?php if ($hasDistance && $distance > 0): ?>
                            <?= $eta ?>
                        <?php else: ?>
                            <span class="pickup-value unknown">Unknown</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($canPickup): ?>
                    <form method="post" class="mt-4">
                        <input type="hidden" name="id" value="<?= intval($rescueId) ?>">
                        <button type="submit" class="btn-pickup">
                            <i class="fas fa-hand-holding-heart me-2"></i> Confirm Pickup
                        </button>
                    </form>
                <?php else: ?>
                    <div class="mt-4">
                        <?php if ($status === 'picked_up'): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i> Food already picked up!
                            </div>
                        <?php elseif ($status === 'in_transit' || $status === 'delivered'): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i> This delivery is already <?= $status ?>.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i> Pickup not available. Status: <?= $status ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="map-legend">
                <div class="legend-item">🏍️ Rider (Live)</div>
                <div class="legend-item"><span class="marker-chip marker-donor"></span> Donor pickup</div>
                <div class="legend-item"><span class="marker-chip marker-recipient"></span> Recipient dropoff</div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="pickup-card p-0">
                <div id="pickupMap"></div>
            </div>
        </div>
    </div>
</div>

<script>
    const riderId = <?= json_encode($riderId) ?>;
    const pickupLat = <?= json_encode($pickupLat) ?>;
    const pickupLng = <?= json_encode($pickupLng) ?>;
    const recipientLat = <?= json_encode($recipientLat) ?>;
    const recipientLng = <?= json_encode($recipientLng) ?>;
    const donorId = <?= json_encode($donorId) ?>;
    const recipientId = <?= json_encode($recipientId) ?>;
    const riderName = <?= json_encode($riderName) ?>;
    const donorName = <?= json_encode($donorName) ?>;
    const recipientName = <?= json_encode($recipientName) ?>;
    const baseUrl = <?= json_encode(BASE_URL) ?>;

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof L === 'undefined') return;
        
        const mapContainer = document.getElementById('pickupMap');
        if (!mapContainer) return;
        
        let centerLat = pickupLat || recipientLat || 30.1798;
        let centerLng = pickupLng || recipientLng || 66.9750;
        
        const map = L.map('pickupMap', { scrollWheelZoom: false, zoomControl: true })
            .setView([centerLat, centerLng], 13);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);
        
        L.control.zoom({ position: 'topright' }).addTo(map);
        
        const donorIcon = L.divIcon({ className: 'custom-marker-donor', iconSize: [16, 16], iconAnchor: [8, 8] });
        const recipientIcon = L.divIcon({ className: 'custom-marker-recipient', iconSize: [16, 16], iconAnchor: [8, 8] });
        
        const bounds = L.latLngBounds();
        let riderMarkerLive = null;
        
        // Donor (pickup) marker
        if (pickupLat && pickupLng) {
            L.marker([pickupLat, pickupLng], { icon: donorIcon })
                .addTo(map)
                .bindPopup('<strong>📍 Pickup</strong><br>' + escapeHtml(donorName));
            bounds.extend([pickupLat, pickupLng]);
        }
        
        // Recipient marker
        if (recipientLat && recipientLng) {
            L.marker([recipientLat, recipientLng], { icon: recipientIcon })
                .addTo(map)
                .bindPopup('<strong>🏠 Dropoff</strong><br>' + escapeHtml(recipientName));
            bounds.extend([recipientLat, recipientLng]);
        }
        
        // ============================================
        // LIVE RIDER LOCATION
        // ============================================
        async function fetchRiderLocation() {
            try {
                const res = await fetch(baseUrl + 'api/get_live_location.php?user_id=' + riderId, {
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                
                if (!data.success || !data.latitude || !data.longitude) return;
                
                const lat = parseFloat(data.latitude);
                const lng = parseFloat(data.longitude);
                
                console.log('📍 Rider LIVE:', lat, lng);
                
                // Update fields
                const displayEl = document.getElementById('riderLocationDisplay');
                const coordsEl = document.getElementById('riderCoordsDisplay');
                if (displayEl) displayEl.textContent = '📍 ' + escapeHtml(data.name || riderName);
                if (coordsEl) coordsEl.textContent = lat.toFixed(5) + ', ' + lng.toFixed(5);
                
                // Update marker
                const riderIcon = L.divIcon({
                    className: '',
                    html: '<div style="font-size:28px; line-height:1; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.35));">🏍️</div>',
                    iconSize: [28, 28],
                    iconAnchor: [14, 14]
                });
                
                if (riderMarkerLive) {
                    riderMarkerLive.setLatLng([lat, lng]);
                } else {
                    riderMarkerLive = L.marker([lat, lng], { icon: riderIcon })
                        .addTo(map)
                        .bindPopup('<strong>🏍️ You (Live)</strong>');
                }
                
                bounds.extend([lat, lng]);
                
                if (bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
                }
                
            } catch (e) {
                console.log('Rider location error:', e);
            }
        }
        
        // Fetch immediately and every 5 seconds
        fetchRiderLocation();
        setInterval(fetchRiderLocation, 5000);
        
        setTimeout(function() {
            map.invalidateSize();
            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
            }
        }, 500);
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