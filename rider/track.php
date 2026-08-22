<?php
// ============================================
// NEKIROT QUETTA - RIDER LIVE TRACKING
// Rider moves from pickup to dropoff automatically
// Live location from database
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

$riderId = intval($user['id']);

$db = get_db_connection();
$rescueId = intval($_GET['rescue_id'] ?? 0);

if (!$rescueId) {
    $stmt = $db->prepare('SELECT id FROM rescues WHERE assigned_rider_id = ? AND status NOT IN ("delivered", "cancelled") ORDER BY created_at DESC LIMIT 1');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $rescueId = intval($row['id'] ?? 0);
}

if (!$rescueId) {
    $db->close();
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

$stmt = $db->prepare(
    'SELECT r.*, l.latitude AS pickup_lat, l.longitude AS pickup_lng, l.id AS listing_id,
            d.name AS donor_name, d.phone AS donor_phone, d.id AS donor_id,
            u.name AS recipient_name, u.phone AS recipient_phone, u.latitude AS recipient_lat, u.longitude AS recipient_lng, u.id AS recipient_id
     FROM rescues r
     LEFT JOIN listings l ON l.id = r.listing_id
     LEFT JOIN users d ON d.id = l.donor_id
     LEFT JOIN users u ON u.id = r.recipient_id
     WHERE r.id = ? AND r.assigned_rider_id = ?
     LIMIT 1'
);
$stmt->bind_param('ii', $rescueId, $user['id']);
$stmt->execute();
$rescue = $stmt->get_result()->fetch_assoc();

if (!$rescue) {
    $db->close();
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

$db->close();

$status = $rescue['status'] ?? 'pending';
$pickupLat = floatval($rescue['pickup_lat'] ?: $rescue['latitude']);
$pickupLng = floatval($rescue['pickup_lng'] ?: $rescue['longitude']);
$recipientLat = floatval($rescue['recipient_lat'] ?: $rescue['latitude']);
$recipientLng = floatval($rescue['recipient_lng'] ?: $rescue['longitude']);

$donorName = $rescue['donor_name'] ?: 'Donor';
$donorPhone = $rescue['donor_phone'] ?: 'N/A';
$recipientName = $rescue['recipient_name'] ?: 'Recipient';
$recipientPhone = $rescue['recipient_phone'] ?: 'N/A';

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .leaflet-div-icon {
        background: transparent !important;
        border: none !important;
    }
    #trackMap { height: 480px; border-radius: 18px; border: 1px solid rgba(26,58,107,0.08); background: #f7fafc; }
    .track-panel { background: white; border-radius: 18px; border: 1px solid rgba(26,58,107,0.08); box-shadow: 0 18px 40px rgba(15,23,42,0.08); }
    .track-panel .header { padding: 24px; border-bottom: 1px solid rgba(226,232,240,0.9); }
    .track-panel .body { padding: 24px; }
    .track-step { display: inline-flex; align-items: center; gap: 10px; padding: 10px 16px; border-radius: 999px; font-weight: 700; background: #e0f2fe; color: #0369a1; text-transform: capitalize; }
    .track-step.delivered { background: #d1fae5; color: #065f46; }
    .summary-card { background: #f8fafc; border-radius: 16px; padding: 18px; border: 1px solid rgba(66,153,225,0.12); }
    .summary-card h6 { margin-bottom: 10px; color: #4a5568; font-size: 0.95rem; }
    .summary-card p { margin-bottom: 0; font-weight: 700; color: #1a202c; }
    .info-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-bottom: 18px; }
    .info-box { background: white; border-radius: 16px; padding: 18px; border: 1px solid rgba(26,58,107,0.08); }
    .info-box strong { display: block; margin-bottom: 6px; color: #4a5568; }
    .eta-card { margin-top: 16px; padding: 18px; background: #e0f2fe; border-radius: 16px; color: #0369a1; font-weight: 700; }
    .live-badge {
        display: inline-block;
        background: #d1fae5;
        color: #065f46;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 700;
        animation: pulse-live 2s infinite;
    }
    @keyframes pulse-live { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    @keyframes bike-bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
</style>

<div class="container py-4">
    <div class="row gx-4 gy-4">
        <div class="col-xl-8">
            <div id="trackMap"></div>
        </div>
        <div class="col-xl-4">
            <div class="track-panel">
                <div class="header">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <h1 class="h4 mb-2">Rider Live Tracking</h1>
                            <p class="text-muted mb-0">Live location from database <span class="live-badge">LIVE</span></p>
                        </div>
                        <span class="track-step <?= htmlspecialchars($status === 'delivered' ? 'delivered' : '') ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', ucfirst($status))) ?>
                        </span>
                    </div>
                </div>
                <div class="body">
                    <div class="summary-card mb-3">
                        <h6>Rescue ID</h6>
                        <p>#<?= intval($rescueId) ?></p>
                    </div>

                    <div class="info-row">
                        <div class="info-box">
                            <strong>🏍️ Your Live Location</strong>
                            <p id="riderLocationDisplay">Loading...</p>
                        </div>
                        <div class="info-box">
                            <strong>📍 Your Coordinates</strong>
                            <p id="riderCoordsDisplay">Fetching...</p>
                        </div>
                    </div>

                    <div class="info-row">
                        <div class="info-box">
                            <strong>📦 Pickup (Donor)</strong>
                            <p><?= htmlspecialchars($donorName) ?><br><small><?= htmlspecialchars($donorPhone) ?></small></p>
                        </div>
                        <div class="info-box">
                            <strong>🏠 Dropoff (Recipient)</strong>
                            <p><?= htmlspecialchars($recipientName) ?><br><small><?= htmlspecialchars($recipientPhone) ?></small></p>
                        </div>
                    </div>

                    <div class="eta-card">
                        ETA: <span id="etaText">Calculating...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const baseUrl = <?= json_encode(BASE_URL) ?>;
    const riderId = <?= json_encode($riderId) ?>;
    const pickupLat = <?= json_encode($pickupLat) ?>;
    const pickupLng = <?= json_encode($pickupLng) ?>;
    const recipientLat = <?= json_encode($recipientLat) ?>;
    const recipientLng = <?= json_encode($recipientLng) ?>;

    let map;
    let riderMarkerLive;
    let routeLine;
    let liveDbInterval;

    // Bike icon
    const riderIcon = L.divIcon({
        className: '',
        html: '<div style="font-size:30px; line-height:1; filter: drop-shadow(0 3px 8px rgba(0,0,0,0.35)); animation: bike-bounce 2s infinite;">🏍️</div>',
        iconSize: [30, 30],
        iconAnchor: [15, 15],
        popupAnchor: [0, -15]
    });

    // Pickup marker (green)
    const pickupIcon = L.divIcon({
        className: '',
        html: '<div style="background:#48bb78;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 10px rgba(72,187,120,0.5);"></div>',
        iconSize: [16, 16],
        iconAnchor: [8, 8]
    });

    // Recipient marker (orange)
    const recipientIcon = L.divIcon({
        className: '',
        html: '<div style="background:#ed8936;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 10px rgba(237,137,54,0.5);"></div>',
        iconSize: [16, 16],
        iconAnchor: [8, 8]
    });

    function initMap() {
        map = L.map('trackMap', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        // Pickup marker
        L.marker([pickupLat, pickupLng], { icon: pickupIcon })
            .addTo(map)
            .bindPopup('<strong>📍 Pickup</strong>');

        // Recipient marker
        L.marker([recipientLat, recipientLng], { icon: recipientIcon })
            .addTo(map)
            .bindPopup('<strong>🏠 Dropoff</strong>');

        // Dashed line from pickup to dropoff
        routeLine = L.polyline(
            [[pickupLat, pickupLng], [recipientLat, recipientLng]],
            { color: '#2b6cb0', weight: 4, opacity: 0.75, dashArray: '8, 6' }
        ).addTo(map);

        fitMap();
    }

    // ============================================
    // LIVE LOCATION FROM DATABASE
    // ============================================
    async function fetchLiveLocationFromDB() {
        try {
            const res = await fetch(baseUrl + 'api/get_live_location.php?user_id=' + riderId, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            
            if (!data.success || !data.latitude || !data.longitude) return;
            
            const lat = parseFloat(data.latitude);
            const lng = parseFloat(data.longitude);
            
            console.log('📍 Rider LIVE from DB:', lat, lng);
            
            // Update fields
            const displayEl = document.getElementById('riderLocationDisplay');
            const coordsEl = document.getElementById('riderCoordsDisplay');
            if (displayEl) displayEl.textContent = '📍 ' + escapeHtml(data.name || 'Rider');
            if (coordsEl) coordsEl.textContent = lat.toFixed(5) + ', ' + lng.toFixed(5);
            
            // Update or create rider marker
            if (riderMarkerLive) {
                riderMarkerLive.setLatLng([lat, lng]);
            } else {
                riderMarkerLive = L.marker([lat, lng], { icon: riderIcon })
                    .addTo(map)
                    .bindPopup('<strong>🏍️ You (Live)</strong>');
            }
            
            // Update ETA
            updateEta(lat, lng);
            
            // Fit map
            fitMap();
            
        } catch (e) {
            console.log('Live DB location error:', e);
        }
    }

    function updateEta(lat, lng) {
        const distanceKm = haversineDistance(lat, lng, recipientLat, recipientLng);
        const minutes = Math.max(1, Math.round((distanceKm / 20) * 60));
        document.getElementById('etaText').textContent = `${distanceKm.toFixed(1)} km • ${minutes} min`;
    }

    function haversineDistance(lat1, lon1, lat2, lon2) {
        const toRad = x => x * Math.PI / 180;
        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return 6371 * c;
    }

    function fitMap() {
        const bounds = L.latLngBounds();
        bounds.extend([pickupLat, pickupLng]);
        bounds.extend([recipientLat, recipientLng]);
        if (riderMarkerLive) {
            const pos = riderMarkerLive.getLatLng();
            bounds.extend([pos.lat, pos.lng]);
        }
        if (bounds.isValid()) {
            map.fitBounds(bounds.pad(0.18));
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initMap();
        
        // Fetch live location immediately
        fetchLiveLocationFromDB();
        
        // Refresh every 3 seconds
        liveDbInterval = setInterval(fetchLiveLocationFromDB, 3000);
    });

    window.addEventListener('beforeunload', function() {
        if (liveDbInterval) clearInterval(liveDbInterval);
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