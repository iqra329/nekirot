<?php
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
$rescueId = intval($_GET['id'] ?? $_GET['rescue_id'] ?? 0);

if (!$rescueId) {
    $stmt = $db->prepare('SELECT id FROM rescues WHERE recipient_id = ? AND status NOT IN ("delivered", "cancelled") ORDER BY created_at DESC LIMIT 1');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $rescueId = intval($row['id'] ?? 0);
}

if (!$rescueId) {
    $db->close();
    header('Location: ' . BASE_URL . 'recipient/dashboard.php');
    exit;
}

$stmt = $db->prepare(
    'SELECT r.*, l.latitude AS pickup_lat, l.longitude AS pickup_lng,
            u.name AS recipient_name, u.phone AS recipient_phone, u.latitude AS recipient_lat, u.longitude AS recipient_lng,
            d.name AS donor_name, d.phone AS donor_phone,
            rider.name AS rider_name, rider.phone AS rider_phone,
            rider.latitude AS rider_lat, rider.longitude AS rider_lng
     FROM rescues r
     LEFT JOIN listings l ON l.id = r.listing_id
     LEFT JOIN users u ON u.id = r.recipient_id
     LEFT JOIN users d ON d.id = l.donor_id
     LEFT JOIN users rider ON rider.id = r.assigned_rider_id
     WHERE r.id = ? AND r.recipient_id = ?
     LIMIT 1'
);
$stmt->bind_param('ii', $rescueId, $user['id']);
$stmt->execute();
$rescue = $stmt->get_result()->fetch_assoc();

if (!$rescue) {
    $db->close();
    header('Location: ' . BASE_URL . 'recipient/dashboard.php');
    exit;
}

// ============================================
// FIXED: Removed 'status' from tracking query
// ============================================
$stmt = $db->prepare('SELECT latitude, longitude, tracked_at FROM tracking WHERE rescue_id = ? ORDER BY tracked_at DESC LIMIT 1');
$stmt->bind_param('i', $rescueId);
$stmt->execute();
$latestTracking = $stmt->get_result()->fetch_assoc();

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'latest' => $latestTracking ?: new stdClass(),
        'status' => $rescue['status'],  // Get status from rescue table
        'tracked_at' => $latestTracking['tracked_at'] ?? null,
        'rider_assigned' => !empty($rescue['assigned_rider_id']),
        'rider_name' => $rescue['rider_name'] ?? null,
        'rider_phone' => $rescue['rider_phone'] ?? null,
    ]);
    $db->close();
    exit;
}

$db->close();

// ============================================
// VARIABLES
// ============================================
$status = $rescue['status'] ?? 'pending';
$pickupLat = floatval($rescue['pickup_lat'] ?? 0);
$pickupLng = floatval($rescue['pickup_lng'] ?? 0);
$recipientLat = floatval($rescue['recipient_lat'] ?: $rescue['latitude']);
$recipientLng = floatval($rescue['recipient_lng'] ?: $rescue['longitude']);

$donorName = $rescue['donor_name'] ?: 'Donor';
$donorPhone = $rescue['donor_phone'] ?: 'N/A';
$recipientName = $rescue['recipient_name'] ?: 'Recipient';
$recipientPhone = $rescue['recipient_phone'] ?: 'N/A';

// ============================================
// CHECK IF RIDER IS ASSIGNED
// ============================================
$riderAssigned = !empty($rescue['assigned_rider_id']);

if ($riderAssigned) {
    $riderName = $rescue['rider_name'] ?: 'Rider';
    $riderPhone = $rescue['rider_phone'] ?: 'N/A';
    $riderLat = floatval($rescue['rider_lat'] ?? 0);
    $riderLng = floatval($rescue['rider_lng'] ?? 0);
} else {
    $riderName = 'Awaiting rider';
    $riderPhone = 'N/A';
    $riderLat = 0;
    $riderLng = 0;
}

$currentLat = null;
$currentLng = null;
$currentTrackedAt = null;

if ($latestTracking) {
    $currentLat = floatval($latestTracking['latitude']);
    $currentLng = floatval($latestTracking['longitude']);
    $currentTrackedAt = $latestTracking['tracked_at'];
}

// ============================================
// STATUS STEPS - Use rescue status
// ============================================
$statusSteps = ['pending', 'accepted', 'rider_assigned', 'picked_up', 'in_transit', 'delivered'];

$currentStatus = $status;
$currentStep = array_search($currentStatus, $statusSteps, true);

if ($currentStep === false) {
    $currentStep = 0;
}

$progressPercent = round((($currentStep + 1) / count($statusSteps)) * 100);

// ============================================
// STATUS MESSAGES
// ============================================
$statusMessages = [
    'pending' => '⏳ Waiting for a rider to be assigned.',
    'accepted' => '✅ Rider has accepted the rescue. Heading to pickup.',
    'rider_assigned' => '🏍️ Rider is assigned and on the way to pickup.',
    'picked_up' => '🚚 Food has been picked up. Heading to you!',
    'in_transit' => '🔄 Food is on the way to you!',
    'delivered' => '🎉 Food has been delivered!'
];

$statusMessage = $statusMessages[$currentStatus] ?? 'Tracking your delivery...';

if (!$riderAssigned) {
    $statusMessage = '⏳ Waiting for a rider to be assigned.';
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
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
    .status-pill.cancelled { background: #fee2e2; color: #991b1b; }
    
    .info-row { display: grid; gap: 16px; margin-bottom: 18px; }
    .info-box { background: #f8fafc; border-radius: 16px; padding: 18px; border: 1px solid rgba(66,153,225,0.12); }
    .info-box strong { display: block; color: #4a5568; margin-bottom: 8px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .info-box p { margin: 0; font-weight: 500; }
    
    .eta-card { background: #e0f2fe; color: #0369a1; border-radius: 16px; padding: 18px; font-weight: 700; text-align: center; }
    .eta-card.waiting { background: #fef3c7; color: #b7791f; }
    .eta-card .eta-value { font-size: 1.8rem; }
    
    .progress-track { background: #e2e8f0; border-radius: 999px; height: 14px; overflow: hidden; margin-bottom: 18px; }
    .progress-fill { height: 100%; background: linear-gradient(90deg, #2b6cb0, #63b3ed); transition: width 0.3s ease; }
    .progress-fill.waiting { background: linear-gradient(90deg, #a0aec0, #cbd5e1); }
    
    .step-pill { display: inline-flex; align-items: center; justify-content: center; min-width: 38px; height: 38px; border-radius: 999px; color: white; font-weight: 700; margin-right: 8px; }
    .step-pill.completed { background: #48bb78; }
    .step-pill.active { background: #2b6cb0; }
    .step-pill.pending { background: #cbd5e1; color: #334155; }
    .step-label { font-size: 0.75rem; color: #475569; margin-top: 6px; text-transform: capitalize; }
    
    .custom-marker-donor { background: #48bb78; width: 18px; height: 18px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 12px rgba(72,187,120,0.35); }
    .custom-marker-recipient { background: #ed8936; width: 18px; height: 18px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 12px rgba(237,137,54,0.35); }
    .custom-marker-rider {
        background: transparent !important;
        width: 26px;
        height: 26px;
        border: none;
        box-shadow: none;
        position: relative;
        animation: pulse-rider 1.5s infinite;
    }
    .custom-marker-rider::after {
        content: '🏍️';
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        filter: drop-shadow(0 2px 6px rgba(0,0,0,0.25));
    }
    .custom-marker-rider.waiting { background: transparent !important; animation: none; }
    
    @keyframes pulse-rider { 0%,100%{transform:scale(1);}50%{transform:scale(1.12);} }
    
    .status-message-box { padding: 15px; border-radius: 12px; margin-bottom: 15px; background: #ebf8ff; border-left: 4px solid #2b6cb0; }
    .status-message-box.waiting { background: #fef3c7; border-left-color: #b7791f; }
    .status-message-box.delivered { background: #d1fae5; border-left-color: #48bb78; }
    
    .btn-cancel { background: #fee2e2; color: #991b1b; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 600; width: 100%; transition: all 0.3s; text-align: center; display: inline-block; }
    .btn-cancel:hover { background: #fecaca; transform: translateY(-2px); text-decoration: none; color: #991b1b; }
    
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
</style>

<div class="container py-4">
    <div class="row gx-4 gy-4">
        <!-- Map Column -->
        <div class="col-xl-8">
            <div id="recipientTrackMap"></div>
        </div>
        
        <!-- Info Column -->
        <div class="col-xl-4">
            <div class="track-card">
                <div class="track-card-header">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <h1 class="h4 mb-2">Track Rescue #<?= $rescueId ?></h1>
                            <p class="text-muted mb-0">Follow your delivery in real-time</p>
                        </div>
                        <span class="status-pill <?= htmlspecialchars($status) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', ucfirst($status))) ?>
                        </span>
                    </div>
                </div>
                <div class="track-card-body">
                    <!-- Status Message -->
                    <div class="status-message-box <?= (!$riderAssigned) ? 'waiting' : '' ?> <?= ($status == 'delivered') ? 'delivered' : '' ?>">
                        <i class="fas fa-info-circle me-2"></i>
                        <?= $statusMessage ?>
                    </div>
                    
                    <!-- Progress -->
                    <div class="progress-track">
                        <div class="progress-fill <?= (!$riderAssigned) ? 'waiting' : '' ?>" id="progressFill" style="width: <?= $progressPercent ?>%"></div>
                    </div>
                    
                    <!-- Steps -->
                    <div class="d-flex flex-wrap gap-2 mb-4" id="statusSteps"></div>
                    
                    <!-- ETA -->
                    <div class="eta-card <?= (!$riderAssigned) ? 'waiting' : '' ?> mb-3">
                        <?php if ($riderAssigned): ?>
                            <div class="eta-value" id="etaText">Calculating...</div>
                            <small>Estimated arrival time</small>
                        <?php else: ?>
                            <div class="eta-value">⏳</div>
                            <small>Waiting for rider to be assigned</small>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Rider Info -->
                    <div class="grid-2 mb-3">
                        <div class="info-box">
                            <strong>Rider</strong>
                            <p id="riderNameDisplay"><?= htmlspecialchars($riderName) ?></p>
                            <small id="riderPhoneDisplay"><?= htmlspecialchars($riderPhone) ?></small>
                        </div>
                        <div class="info-box">
                            <strong>Last Update</strong>
                            <p id="trackingTimestamp"><?= htmlspecialchars($currentTrackedAt ?: 'Waiting...') ?></p>
                        </div>
                    </div>
                    
                    <!-- Location Info -->
                    <div class="grid-2 mb-3">
                        <div class="info-box">
                            <strong>Pickup</strong>
                            <p><?= htmlspecialchars($donorName) ?></p>
                            <small><?= number_format($pickupLat, 6) ?>, <?= number_format($pickupLng, 6) ?></small>
                        </div>
                        <div class="info-box">
                            <strong>Dropoff</strong>
                            <p><?= htmlspecialchars($recipientName) ?></p>
                            <small><?= number_format($recipientLat, 6) ?>, <?= number_format($recipientLng, 6) ?></small>
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div class="d-grid gap-2">
                        <?php if ($status !== 'delivered' && $status !== 'cancelled'): ?>
                            <a href="<?= BASE_URL ?>recipient/cancel.php?id=<?= $rescueId ?>" 
                               class="btn-cancel"
                               onclick="return confirm('Are you sure you want to cancel this rescue?')">
                                <i class="fas fa-times me-2"></i> Cancel Request
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($riderAssigned && $riderPhone !== 'N/A'): ?>
                            <a href="tel:<?= $riderPhone ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-phone me-2"></i> Call Rider
                            </a>
                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $riderPhone) ?>" 
                               target="_blank" class="btn btn-success btn-sm">
                                <i class="fab fa-whatsapp me-2"></i> WhatsApp
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const rescueId = <?= intval($rescueId) ?>;
    const pickupLat = <?= json_encode($pickupLat) ?>;
    const pickupLng = <?= json_encode($pickupLng) ?>;
    const recipientLat = <?= json_encode($recipientLat) ?>;
    const recipientLng = <?= json_encode($recipientLng) ?>;
    const riderAssigned = <?= json_encode($riderAssigned) ?>;
    const currentStatus = '<?= $currentStatus ?>';
    const statusSteps = ['pending', 'accepted', 'rider_assigned', 'picked_up', 'in_transit', 'delivered'];
    
    const ajaxUrl = window.location.pathname + '?rescue_id=' + rescueId + '&ajax=1';

    let map;
    let riderMarker;
    let routeLine;
    let latestCoords = { 
        lat: <?= json_encode($currentLat ?: ($riderAssigned ? $riderLat : 0)) ?>, 
        lng: <?= json_encode($currentLng ?: ($riderAssigned ? $riderLng : 0)) ?> 
    };

    function initMap() {
        let centerLat = pickupLat || recipientLat || 30.1798;
        let centerLng = pickupLng || recipientLng || 66.9750;
        
        map = L.map('recipientTrackMap', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors', maxZoom: 19,
        }).addTo(map);

        // Donor marker
        if (pickupLat && pickupLng) {
            L.marker([pickupLat, pickupLng], {
                icon: L.divIcon({ className: 'custom-marker-donor', iconSize: [18, 18], iconAnchor: [9, 9] }),
            }).addTo(map).bindPopup('<strong>📦 Pickup</strong><br><?= htmlspecialchars($donorName) ?>');
        }

        // Recipient marker
        if (recipientLat && recipientLng) {
            L.marker([recipientLat, recipientLng], {
                icon: L.divIcon({ className: 'custom-marker-recipient', iconSize: [18, 18], iconAnchor: [9, 9] }),
            }).addTo(map).bindPopup('<strong>🏠 Dropoff</strong><br><?= htmlspecialchars($recipientName) ?>');
        }

        // Rider marker
        if (riderAssigned && latestCoords.lat && latestCoords.lng) {
            riderMarker = L.marker([latestCoords.lat, latestCoords.lng], {
                icon: L.divIcon({ className: 'custom-marker-rider', iconSize: [22, 22], iconAnchor: [11, 11] }),
            }).addTo(map).bindPopup('<strong>🏍️ Rider</strong><br><?= htmlspecialchars($riderName) ?>');
            updateRoute();
        } else {
            // Show waiting marker at center
            riderMarker = L.marker([centerLat, centerLng], {
                icon: L.divIcon({ className: 'custom-marker-rider waiting', iconSize: [22, 22], iconAnchor: [11, 11] }),
            }).addTo(map).bindPopup('<strong>⏳ Waiting for Rider</strong>');
        }

        renderSteps(currentStatus);
        fitMap();
    }

    function updateRoute() {
        if (!latestCoords.lat || !latestCoords.lng || !recipientLat || !recipientLng) return;
        const path = [[latestCoords.lat, latestCoords.lng], [recipientLat, recipientLng]];
        if (routeLine) {
            routeLine.setLatLngs(path);
        } else {
            routeLine = L.polyline(path, { color: '#2b6cb0', weight: 4, opacity: 0.75, dashArray: '8,6' }).addTo(map);
        }
        fitMap();
        updateEta();
    }

    function fitMap() {
        const bounds = L.latLngBounds();
        if (pickupLat && pickupLng) bounds.extend([pickupLat, pickupLng]);
        if (recipientLat && recipientLng) bounds.extend([recipientLat, recipientLng]);
        if (latestCoords.lat && latestCoords.lng) bounds.extend([latestCoords.lat, latestCoords.lng]);
        if (bounds.isValid()) map.fitBounds(bounds.pad(0.18));
    }

    function haversine(lat1, lon1, lat2, lon2) {
        const toRad = x => x * Math.PI / 180;
        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function updateEta() {
        const etaEl = document.getElementById('etaText');
        if (!etaEl) return;
        
        if (!riderAssigned) {
            etaEl.textContent = '⏳ Waiting for rider';
            return;
        }
        
        if (!latestCoords.lat || !latestCoords.lng) {
            etaEl.textContent = 'Calculating...';
            return;
        }
        
        const distance = haversine(latestCoords.lat, latestCoords.lng, recipientLat, recipientLng);
        const minutes = Math.max(1, Math.round(distance / 20 * 60));
        etaEl.textContent = `${distance.toFixed(1)} km • ${minutes} min`;
    }

    function renderSteps(status) {
        const container = document.getElementById('statusSteps');
        if (!container) return;
        container.innerHTML = '';
        
        const idx = statusSteps.indexOf(status);
        const validIdx = idx >= 0 ? idx : 0;
        const percent = Math.round(((validIdx + 1) / statusSteps.length) * 100);
        const progressFill = document.getElementById('progressFill');
        if (progressFill) {
            progressFill.style.width = `${percent}%`;
            if (!riderAssigned) {
                progressFill.classList.add('waiting');
            } else {
                progressFill.classList.remove('waiting');
            }
        }

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

    async function fetchTracking() {
        try {
            const res = await fetch(ajaxUrl, { cache: 'no-store' });
            const data = await res.json();
            if (!data.success) return;
            
            // Update rider name if available
            if (data.rider_name) {
                document.getElementById('riderNameDisplay').textContent = data.rider_name;
            }
            if (data.rider_phone) {
                document.getElementById('riderPhoneDisplay').textContent = data.rider_phone;
            }
            
            // Update location
            const latest = data.latest || {};
            if (latest.latitude && latest.longitude) {
                latestCoords = { lat: latest.latitude, lng: latest.longitude };
                if (riderMarker) {
                    riderMarker.setLatLng([latestCoords.lat, latestCoords.lng]);
                }
                updateRoute();
            }
            
            // Update status - using the rescue status from AJAX
            if (data.status) {
                const label = data.status.replace(/_/g, ' ');
                const pill = document.querySelector('.status-pill');
                if (pill) {
                    pill.textContent = label;
                    pill.className = `status-pill ${data.status}`;
                }
                renderSteps(data.status);
            }
            
            if (data.tracked_at) {
                document.getElementById('trackingTimestamp').textContent = data.tracked_at;
            }
            
            updateEta();
            
        } catch (error) {
            console.error('Tracking fetch failed', error);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initMap();
        fetchTracking();
        setInterval(fetchTracking, 10000);
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>