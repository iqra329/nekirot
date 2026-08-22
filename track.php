<?php
// ============================================
// NEKIROT QUETTA - TRACKING PAGE
// Real-time tracking with road-based route
// ============================================

include_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
$user = current_user();

$db = get_db_connection();

$rescueId = intval($_GET['id'] ?? $_GET['rescue_id'] ?? 0);

if (!$rescueId) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('/track\/(\d+)/', $uri, $matches)) {
        $rescueId = intval($matches[1]);
    }
}

if (!$rescueId) {
    if ($user['type'] === 'recipient') {
        $stmt = $db->prepare('SELECT id FROM rescues WHERE recipient_id = ? AND status NOT IN ("delivered", "cancelled") ORDER BY created_at DESC LIMIT 1');
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $rescueId = intval($row['id'] ?? 0);
    } elseif ($user['type'] === 'rider') {
        $stmt = $db->prepare('SELECT id FROM rescues WHERE assigned_rider_id = ? AND status NOT IN ("delivered", "cancelled") ORDER BY created_at DESC LIMIT 1');
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $rescueId = intval($row['id'] ?? 0);
    }
}

if (!$rescueId) {
    $db->close();
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$stmt = $db->prepare(
    'SELECT r.*, l.latitude AS pickup_lat, l.longitude AS pickup_lng,
            u.name AS recipient_name, u.phone AS recipient_phone, u.latitude AS recipient_lat, u.longitude AS recipient_lng,
            d.name AS donor_name, d.phone AS donor_phone,
            rider.name AS rider_name, rider.phone AS rider_phone,
            rider.id AS rider_id
     FROM rescues r
     LEFT JOIN listings l ON l.id = r.listing_id
     LEFT JOIN users u ON u.id = r.recipient_id
     LEFT JOIN users d ON d.id = l.donor_id
     LEFT JOIN users rider ON rider.id = r.assigned_rider_id
     WHERE r.id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $rescueId);
$stmt->execute();
$rescue = $stmt->get_result()->fetch_assoc();

if (!$rescue) {
    $db->close();
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$userType = $user['type'] ?? '';
$userId = intval($user['id'] ?? 0);

$hasAccess = false;
if ($userType === 'recipient' && intval($rescue['recipient_id']) === $userId) $hasAccess = true;
elseif ($userType === 'rider' && intval($rescue['assigned_rider_id']) === $userId) $hasAccess = true;
elseif ($userType === 'donor') $hasAccess = true;
elseif ($userType === 'admin') $hasAccess = true;

if (!$hasAccess) {
    $db->close();
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$db->close();

$status = strtolower(trim($rescue['status'] ?? 'pending'));
$pickupLat = floatval($rescue['pickup_lat'] ?? 0);
$pickupLng = floatval($rescue['pickup_lng'] ?? 0);
$recipientLat = floatval($rescue['recipient_lat'] ?: $rescue['latitude']);
$recipientLng = floatval($rescue['recipient_lng'] ?: $rescue['longitude']);

$donorName = $rescue['donor_name'] ?: 'Donor';
$donorPhone = $rescue['donor_phone'] ?: 'N/A';
$recipientName = $rescue['recipient_name'] ?: 'Recipient';
$recipientPhone = $rescue['recipient_phone'] ?: 'N/A';

$riderAssigned = !empty($rescue['assigned_rider_id']);
$riderId = intval($rescue['assigned_rider_id'] ?? 0);

if ($riderAssigned) {
    $riderName = $rescue['rider_name'] ?: 'Rider';
    $riderPhone = $rescue['rider_phone'] ?: 'N/A';
} else {
    $riderName = 'Awaiting rider';
    $riderPhone = 'N/A';
}

$showRider = $riderAssigned && in_array($status, ['accepted', 'rider_assigned', 'picked_up', 'in_transit', 'delivered']);
$showRoute = in_array($status, ['picked_up', 'in_transit', 'delivered']);
$isInTransit = ($status === 'in_transit');
$isDelivered = ($status === 'delivered');

$etaMinutes = 0;
if ($pickupLat && $pickupLng && $recipientLat && $recipientLng) {
    $distanceKm = calculate_distance_km($pickupLat, $pickupLng, $recipientLat, $recipientLng);
    $etaMinutes = max(1, round(($distanceKm / 20) * 60));
}

$statusSteps = ['pending', 'accepted', 'rider_assigned', 'picked_up', 'in_transit', 'delivered'];
$currentStatus = $status;

$statusMessages = [
    'pending' => '⏳ Waiting for a rider to accept your request.',
    'accepted' => '✅ Rider has accepted your request.',
    'rider_assigned' => '🏍️ Rider assigned to your delivery.',
    'picked_up' => '🚚 Food picked up! On the way to you.',
    'in_transit' => '🔄 Rider is on the way with your food!',
    'delivered' => '🎉 Delivered! Enjoy your meal!'
];

$statusMessage = $statusMessages[$currentStatus] ?? 'Tracking...';

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .leaflet-div-icon { background: transparent !important; border: none !important; }
    #recipientTrackMap { height: 520px; border-radius: 18px; border: 1px solid rgba(26,58,107,0.08); background: #f7fafc; }
    .track-card { background: white; border-radius: 18px; border: 1px solid rgba(26,58,107,0.08); box-shadow: 0 18px 40px rgba(15,23,42,0.08); }
    .track-card-header { padding: 24px; border-bottom: 1px solid rgba(226,232,240,0.9); }
    .track-card-body { padding: 24px; }
    .status-pill { display: inline-flex; align-items: center; padding: 10px 18px; border-radius: 999px; font-weight: 700; text-transform: capitalize; }
    .status-pill.pending { background: #fef3c7; color: #b7791f; }
    .status-pill.accepted { background: #e0f2fe; color: #0369a1; }
    .status-pill.rider_assigned { background: #d1fae5; color: #065f46; }
    .status-pill.picked_up { background: #d1fae5; color: #065f46; }
    .status-pill.in_transit { background: #fef3c7; color: #b7791f; }
    .status-pill.delivered { background: #d1fae5; color: #065f46; }
    .info-box { background: #f8fafc; border-radius: 16px; padding: 18px; border: 1px solid rgba(66,153,225,0.12); }
    .info-box strong { display: block; color: #4a5568; margin-bottom: 8px; font-size: 0.8rem; text-transform: uppercase; }
    .info-box p { margin: 0; font-weight: 500; }
    .eta-card { background: #e0f2fe; color: #0369a1; border-radius: 16px; padding: 18px; font-weight: 700; text-align: center; }
    .eta-card.delivered { background: #d1fae5; color: #065f46; }
    .eta-card .eta-value { font-size: 1.8rem; }
    .progress-track { background: #e2e8f0; border-radius: 999px; height: 14px; overflow: hidden; margin-bottom: 15px; }
    .progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #2b6cb0, #63b3ed, #48bb78); border-radius: 999px; transition: width 0.1s linear; }
    .step-pill { display: inline-flex; align-items: center; justify-content: center; min-width: 38px; height: 38px; border-radius: 999px; color: white; font-weight: 700; margin-right: 8px; }
    .step-pill.completed { background: #48bb78; }
    .step-pill.active { background: #2b6cb0; }
    .step-pill.pending { background: #cbd5e1; color: #334155; }
    .step-label { font-size: 0.75rem; color: #475569; margin-top: 6px; text-transform: capitalize; }
    .status-message-box { padding: 15px; border-radius: 12px; margin-bottom: 15px; background: #ebf8ff; border-left: 4px solid #2b6cb0; }
    .status-message-box.waiting { background: #fef3c7; border-left-color: #b7791f; }
    .status-message-box.delivered { background: #d1fae5; border-left-color: #48bb78; }
    .btn-cancel { background: #fee2e2; color: #991b1b; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 600; width: 100%; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .waiting-banner { 
        background: linear-gradient(135deg, #fef3c7, #fde68a); 
        border: 2px dashed #f59e0b; 
        border-radius: 16px; 
        padding: 20px; 
        text-align: center; 
        margin-bottom: 15px; 
    }
    .waiting-banner .icon { font-size: 3rem; margin-bottom: 10px; }
    .waiting-banner h5 { color: #b7791f; font-weight: 700; }
    .live-indicator {
        display: inline-block;
        width: 10px;
        height: 10px;
        background: #48bb78;
        border-radius: 50%;
        animation: pulse 2s infinite;
        margin-right: 5px;
    }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
</style>

<div class="container py-4">
    <div class="row gx-4 gy-4">
        <div class="col-xl-8">
            <div id="recipientTrackMap"></div>
        </div>
        <div class="col-xl-4">
            <div class="track-card">
                <div class="track-card-header">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <h1 class="h4 mb-2">Track Rescue #<?= $rescueId ?></h1>
                            <p class="text-muted mb-0">
                                <span class="live-indicator"></span>Live tracking
                            </p>
                        </div>
                        <span class="status-pill <?= htmlspecialchars($status) ?>" id="statusPill">
                            <?= htmlspecialchars(str_replace('_', ' ', ucfirst($status))) ?>
                        </span>
                    </div>
                </div>
                <div class="track-card-body">
                    <?php if (!$riderAssigned): ?>
                        <div class="waiting-banner">
                            <div class="icon">⏳</div>
                            <h5>Waiting for a Rider</h5>
                            <p class="text-muted mb-0">Your request is pending. A rider will be assigned soon.</p>
                        </div>
                    <?php endif; ?>

                    <div class="status-message-box <?= (!$riderAssigned) ? 'waiting' : '' ?> <?= ($status == 'delivered') ? 'delivered' : '' ?>" id="statusMessageBox">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="statusMessageText"><?= $statusMessage ?></span>
                    </div>

                    <div class="progress-track">
                        <div class="progress-fill" id="progressFill" style="width: <?= $isDelivered ? '100%' : '0%' ?>%"></div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mb-4" id="statusSteps"></div>

                    <div class="eta-card <?= $isDelivered ? 'delivered' : '' ?> mb-3" id="etaCard">
                        <div class="eta-value" id="etaText">
                            <?php 
                            if ($isDelivered) {
                                echo '✅ Delivered!';
                            } elseif (!$riderAssigned) {
                                echo '⏳ Waiting...';
                            } else {
                                echo 'Calculating...';
                            }
                            ?>
                        </div>
                        <small id="etaLabel">
                            <?php 
                            if ($isDelivered) {
                                echo 'Delivery completed';
                            } elseif (!$riderAssigned) {
                                echo 'No rider assigned yet';
                            } else {
                                echo 'Estimated arrival time';
                            }
                            ?>
                        </small>
                    </div>

                    <div class="grid-2 mb-3">
                        <div class="info-box">
                            <strong>Rider</strong>
                            <p><?= htmlspecialchars($riderName) ?></p>
                            <small><?= htmlspecialchars($riderPhone) ?></small>
                        </div>
                        <div class="info-box">
                            <strong>Status</strong>
                            <p id="statusText"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($status))) ?></p>
                        </div>
                    </div>
                    <div class="grid-2 mb-3">
                        <div class="info-box">
                            <strong>Pickup</strong>
                            <p><?= htmlspecialchars($donorName) ?></p>
                        </div>
                        <div class="info-box">
                            <strong>Dropoff</strong>
                            <p><?= htmlspecialchars($recipientName) ?></p>
                        </div>
                    </div>
                    <div class="d-grid gap-2">
                        <?php if ($status !== 'delivered' && $status !== 'cancelled' && $userType === 'recipient'): ?>
                            <a href="<?= BASE_URL ?>recipient/cancel.php?id=<?= $rescueId ?>" class="btn-cancel"
                               onclick="return confirm('Cancel this rescue?')">
                                <i class="fas fa-times me-2"></i> Cancel
                            </a>
                        <?php endif; ?>
                        <?php if ($riderAssigned && $riderPhone !== 'N/A'): ?>
                            <a href="tel:<?= $riderPhone ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-phone me-2"></i> Call Rider
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const pickupLat = <?= json_encode($pickupLat) ?>;
    const pickupLng = <?= json_encode($pickupLng) ?>;
    const recipientLat = <?= json_encode($recipientLat) ?>;
    const recipientLng = <?= json_encode($recipientLng) ?>;
    const isDelivered = <?= json_encode($isDelivered) ?>;
    const showRider = <?= json_encode($showRider) ?>;
    const showRoute = <?= json_encode($showRoute) ?>;
    const donorName = <?= json_encode($donorName) ?>;
    const recipientName = <?= json_encode($recipientName) ?>;
    const currentStatus = '<?= $currentStatus ?>';
    const rescueId = <?= json_encode($rescueId) ?>;
    const baseUrl = <?= json_encode(BASE_URL) ?>;
    const statusSteps = ['pending', 'accepted', 'rider_assigned', 'picked_up', 'in_transit', 'delivered'];

    let map;
    let riderMarker;
    let routePolyline;
    let roadPathCoordinates = [];
    let pollingInterval = null;

    function initMap() {
        map = L.map('recipientTrackMap', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors', maxZoom: 19,
        }).addTo(map);

        const bounds = L.latLngBounds();

        // Pickup marker (green)
        if (pickupLat && pickupLng) {
            L.marker([pickupLat, pickupLng], {
                icon: L.divIcon({
                    className: '',
                    html: '<div style="background:#48bb78;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 10px rgba(72,187,120,0.5);"></div>',
                    iconSize: [16, 16], iconAnchor: [8, 8]
                }),
            }).addTo(map).bindPopup('<strong>📍 Food Location</strong><br>' + escapeHtml(donorName));
            bounds.extend([pickupLat, pickupLng]);
        }

        // Recipient marker (orange)
        if (recipientLat && recipientLng) {
            L.marker([recipientLat, recipientLng], {
                icon: L.divIcon({
                    className: '',
                    html: '<div style="background:#ed8936;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 10px rgba(237,137,54,0.5);"></div>',
                    iconSize: [16, 16], iconAnchor: [8, 8]
                }),
            }).addTo(map).bindPopup('<strong>🏠 Your Location</strong><br>' + escapeHtml(recipientName));
            bounds.extend([recipientLat, recipientLng]);
        }

        // Create rider marker if assigned
        if (showRider) {
            const riderIcon = L.divIcon({
                className: '',
                html: '<div style="font-size:30px;line-height:1;filter:drop-shadow(0 2px 6px rgba(0,0,0,0.3));">🏍️</div>',
                iconSize: [30, 30], iconAnchor: [15, 15]
            });

            const initialRiderPos = isDelivered ? [recipientLat, recipientLng] : [pickupLat, pickupLng];
            riderMarker = L.marker(initialRiderPos, { icon: riderIcon })
                .addTo(map)
                .bindPopup('<strong>🏍️ Rider</strong>');
            
            bounds.extend(initialRiderPos);
        }

        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [50, 50], maxZoom: 15 });
        }

        renderSteps(currentStatus);
    }

    // ============================================
    // FETCH ROAD ROUTE FROM OSRM
    // ============================================
    async function fetchRoadRoute() {
        if (!showRoute) return;
        if (!pickupLat || !pickupLng || !recipientLat || !recipientLng) return;
        
        try {
            const osrmUrl = 
                'https://router.project-osrm.org/route/v1/driving/' +
                pickupLng + ',' + pickupLat + ';' +
                recipientLng + ',' + recipientLat +
                '?overview=full&geometries=geojson';
            
            const response = await fetch(osrmUrl);
            const data = await response.json();
            
            if (data.code !== 'Ok' || !data.routes || data.routes.length === 0) {
                return;
            }
            
            const route = data.routes[0];
            const routeGeometry = route.geometry;
            
            if (!routeGeometry || !routeGeometry.coordinates || routeGeometry.coordinates.length < 2) {
                return;
            }
            
            const allCoords = routeGeometry.coordinates.map(function(coord) {
                return [coord[1], coord[0]];
            });
            
            // Find closest point to recipient
            let closestIndex = 0;
            let closestDistance = Infinity;
            
            for (let i = 0; i < allCoords.length; i++) {
                const dist = calculateSegmentDistance(allCoords[i], [recipientLat, recipientLng]);
                if (dist < closestDistance) {
                    closestDistance = dist;
                    closestIndex = i;
                }
            }
            
            // Build road path
            roadPathCoordinates = [[pickupLat, pickupLng]];
            
            for (let i = 1; i <= closestIndex; i++) {
                roadPathCoordinates.push(allCoords[i]);
            }
            
            roadPathCoordinates.push([recipientLat, recipientLng]);
            
            // Draw road route
            if (routePolyline) map.removeLayer(routePolyline);
            routePolyline = L.polyline(roadPathCoordinates, {
                color: '#2b6cb0',
                weight: 4,
                opacity: 0.75,
                dashArray: '8, 6'
            }).addTo(map);
            
        } catch (error) {
            console.log('OSRM error:', error);
        }
    }

    function calculateSegmentDistance(point1, point2) {
        const R = 6371;
        const dLat = (point2[0] - point1[0]) * Math.PI / 180;
        const dLng = (point2[1] - point1[1]) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(point1[0] * Math.PI / 180) * Math.cos(point2[0] * Math.PI / 180) *
                  Math.sin(dLng / 2) * Math.sin(dLng / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    function renderSteps(status) {
        const container = document.getElementById('statusSteps');
        if (!container) return;
        container.innerHTML = '';
        const idx = statusSteps.indexOf(status);
        const validIdx = idx >= 0 ? idx : 0;
        statusSteps.forEach((step, index) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'text-center';
            const pill = document.createElement('div');
            pill.className = 'step-pill ' + (index < validIdx ? 'completed' : index === validIdx ? 'active' : 'pending');
            pill.textContent = index + 1;
            const label = document.createElement('div');
            label.className = 'step-label';
            label.textContent = step.replace(/_/g, ' ');
            wrapper.appendChild(pill);
            wrapper.appendChild(label);
            container.appendChild(wrapper);
        });
    }

    // ============================================
    // POLL DATABASE FOR RIDER LOCATION AND STATUS
    // ============================================
    async function pollRiderLocation() {
        try {
            const response = await fetch(baseUrl + 'api/get_rider_location.php?rescue_id=' + rescueId, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json();
            
            if (!data.success) return;
            
            // Check if status changed to delivered
            if (data.status === 'delivered') {
                updateUIForDelivered();
                if (pollingInterval) clearInterval(pollingInterval);
                return;
            }
            
            // Update rider position if available
            if (data.rider_latitude && data.rider_longitude && riderMarker) {
                const lat = data.rider_latitude;
                const lng = data.rider_longitude;
                
                riderMarker.setLatLng([lat, lng]);
                
                // Update progress
                const totalDistance = calculateDistance(pickupLat, pickupLng, recipientLat, recipientLng);
                const coveredDistance = calculateDistance(pickupLat, pickupLng, lat, lng);
                const progressPercent = Math.min(100, Math.round((coveredDistance / totalDistance) * 100));
                document.getElementById('progressFill').style.width = progressPercent + '%';
                
                // Update ETA
                const remainingDistance = calculateDistance(lat, lng, recipientLat, recipientLng);
                const remainingMinutes = Math.max(1, Math.round((remainingDistance / 20) * 60));
                document.getElementById('etaText').textContent = remainingMinutes + ' min';
            }
            
        } catch (e) {
            console.log('Polling error:', e);
        }
    }

    function updateUIForDelivered() {
        const statusPill = document.getElementById('statusPill');
        if (statusPill) {
            statusPill.className = 'status-pill delivered';
            statusPill.textContent = 'Delivered';
        }
        
        const statusText = document.getElementById('statusText');
        if (statusText) statusText.textContent = 'Delivered';
        
        const statusMessageText = document.getElementById('statusMessageText');
        if (statusMessageText) statusMessageText.textContent = '🎉 Delivered! Enjoy your meal!';
        
        const statusMessageBox = document.getElementById('statusMessageBox');
        if (statusMessageBox) statusMessageBox.className = 'status-message-box delivered';
        
        const etaCard = document.getElementById('etaCard');
        if (etaCard) etaCard.className = 'eta-card delivered mb-3';
        
        const etaText = document.getElementById('etaText');
        if (etaText) etaText.textContent = '✅ Delivered!';
        
        const etaLabel = document.getElementById('etaLabel');
        if (etaLabel) etaLabel.textContent = 'Delivery completed';
        
        const progressFill = document.getElementById('progressFill');
        if (progressFill) progressFill.style.width = '100%';
        
        if (riderMarker) {
            riderMarker.setLatLng([recipientLat, recipientLng]);
            riderMarker.setPopupContent('<strong>🏍️ Rider</strong><br>✅ Delivered!');
        }
        
        renderSteps('delivered');
    }

    function calculateDistance(lat1, lng1, lat2, lng2) {
        const R = 6371;
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLng = (lng2 - lng1) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLng/2) * Math.sin(dLng/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    document.addEventListener('DOMContentLoaded', async () => {
        initMap();
        
        if (isDelivered) {
            updateUIForDelivered();
        }
        
        // Fetch road route
        await fetchRoadRoute();
        
        // Start polling every 2 seconds
        pollingInterval = setInterval(pollRiderLocation, 2000);
    });

    window.addEventListener('beforeunload', function() {
        if (pollingInterval) clearInterval(pollingInterval);
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>