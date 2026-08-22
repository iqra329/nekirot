<?php
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
$rescueId = intval($_GET['rescue_id'] ?? 0);

if (!$rescueId) {
    $stmt = $db->prepare('SELECT r.id FROM rescues r JOIN listings l ON l.id = r.listing_id WHERE l.donor_id = ? AND r.status NOT IN ("delivered", "cancelled") ORDER BY r.created_at DESC LIMIT 1');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $rescueId = intval($row['id'] ?? 0);
}

if (!$rescueId) {
    $db->close();
    header('Location: ' . BASE_URL . 'donor/dashboard.php');
    exit;
}

$stmt = $db->prepare(
    'SELECT r.*, l.latitude AS pickup_lat, l.longitude AS pickup_lng, l.id AS listing_id,
            u.name AS recipient_name, u.phone AS recipient_phone, u.latitude AS recipient_lat, u.longitude AS recipient_lng,
            d.name AS donor_name, d.phone AS donor_phone,
            rider.name AS rider_name, rider.phone AS rider_phone, rider.latitude AS rider_lat, rider.longitude AS rider_lng
     FROM rescues r
     JOIN listings l ON l.id = r.listing_id
     LEFT JOIN users u ON u.id = r.recipient_id
     LEFT JOIN users d ON d.id = l.donor_id
     LEFT JOIN users rider ON rider.id = r.assigned_rider_id
     WHERE r.id = ? AND l.donor_id = ?
     LIMIT 1'
);
$stmt->bind_param('ii', $rescueId, $user['id']);
$stmt->execute();
$rescue = $stmt->get_result()->fetch_assoc();

if (!$rescue) {
    $db->close();
    header('Location: ' . BASE_URL . 'donor/dashboard.php');
    exit;
}

$stmt = $db->prepare('SELECT latitude, longitude, status, tracked_at FROM tracking WHERE rescue_id = ? ORDER BY tracked_at DESC LIMIT 1');
$stmt->bind_param('i', $rescueId);
$stmt->execute();
$latestTracking = $stmt->get_result()->fetch_assoc();

$db->close();

$status = $rescue['status'] ?? 'pending';
$pickupLat = floatval($rescue['pickup_lat'] ?? 0);
$pickupLng = floatval($rescue['pickup_lng'] ?? 0);
$recipientLat = floatval($rescue['recipient_lat'] ?: $rescue['latitude']);
$recipientLng = floatval($rescue['recipient_lng'] ?: $rescue['longitude']);

$donorName = $rescue['donor_name'] ?: 'Donor';
$donorPhone = $rescue['donor_phone'] ?: 'N/A';
$recipientName = $rescue['recipient_name'] ?: 'Recipient';
$recipientPhone = $rescue['recipient_phone'] ?: 'N/A';
$riderName = $rescue['rider_name'] ?: 'Awaiting rider';
$riderPhone = $rescue['rider_phone'] ?: 'N/A';

$currentLat = null;
$currentLng = null;
$currentTrackedAt = null;
$currentStatus = $status;
if ($latestTracking) {
    $currentLat = floatval($latestTracking['latitude']);
    $currentLng = floatval($latestTracking['longitude']);
    $currentTrackedAt = $latestTracking['tracked_at'];
    $currentStatus = $latestTracking['status'] ?: $currentStatus;
}

$targetIsRecipient = in_array($status, ['picked_up', 'pickup_confirmed', 'in_transit', 'delivered'], true);
$destinationLat = $targetIsRecipient ? $recipientLat : $pickupLat;
$destinationLng = $targetIsRecipient ? $recipientLng : $pickupLng;
$destinationLabel = $targetIsRecipient ? 'Recipient dropoff' : 'Pickup location';

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    #donorTrackMap { height: 520px; border-radius: 18px; border: 1px solid rgba(26,58,107,0.08); background: #f7fafc; }
    .track-card { background: white; border-radius: 18px; border: 1px solid rgba(26,58,107,0.08); box-shadow: 0 18px 40px rgba(15,23,42,0.08); }
    .track-card-header { padding: 24px; border-bottom: 1px solid rgba(226,232,240,0.9); }
    .track-card-body { padding: 24px; }
    .info-row { display: grid; gap: 16px; margin-bottom: 18px; }
    .info-box { background: #f8fafc; border-radius: 16px; padding: 18px; border: 1px solid rgba(66,153,225,0.12); }
    .info-box strong { display: block; color: #4a5568; margin-bottom: 8px; }
    .status-pill { display: inline-flex; align-items: center; padding: 10px 18px; border-radius: 999px; font-weight: 700; text-transform: capitalize; }
    .status-pill.pending { background: #fef3c7; color: #b7791f; }
    .status-pill.accepted, .status-pill.rider_assigned, .status-pill.rider_en_route_pickup { background: #e0f2fe; color: #0369a1; }
    .status-pill.picked_up, .status-pill.pickup_confirmed, .status-pill.in_transit { background: #d1fae5; color: #065f46; }
    .status-pill.delivered { background: #d1fae5; color: #065f46; }
    .eta-card { background: #e0f2fe; color: #0369a1; border-radius: 16px; padding: 18px; font-weight: 700; }
    .marker-label { font-size: 0.92rem; font-weight: 600; }
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
    @keyframes pulse-rider { 0%,100%{transform:scale(1);}50%{transform:scale(1.12);} }
    .status-detail { font-size: 0.93rem; color: #4a5568; }
</style>

<div class="container py-4">
    <div class="row gx-4 gy-4">
        <div class="col-xl-8">
            <div id="donorTrackMap"></div>
        </div>
        <div class="col-xl-4">
            <div class="track-card">
                <div class="track-card-header">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <h1 class="h4 mb-2">Donor Rider Tracking</h1>
                            <p class="text-muted mb-0">See where the rider is in real time and follow their route to the destination.</p>
                        </div>
                        <span class="status-pill <?= htmlspecialchars($status) ?>"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($status))) ?></span>
                    </div>
                </div>
                <div class="track-card-body">
                    <div class="info-row">
                        <div class="info-box">
                            <strong>Rider</strong>
                            <p><?= htmlspecialchars($riderName) ?><br><small><?= htmlspecialchars($riderPhone) ?></small></p>
                        </div>
                        <div class="info-box">
                            <strong>Target</strong>
                            <p><?= htmlspecialchars($destinationLabel) ?></p>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-box">
                            <strong>Pickup</strong>
                            <p><?= htmlspecialchars($donorName) ?><br><small><?= htmlspecialchars($pickupLat) ?>, <?= htmlspecialchars($pickupLng) ?></small></p>
                        </div>
                        <div class="info-box">
                            <strong>Dropoff</strong>
                            <p><?= htmlspecialchars($recipientName) ?><br><small><?= htmlspecialchars($recipientLat) ?>, <?= htmlspecialchars($recipientLng) ?></small></p>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-box">
                            <strong>Last rider update</strong>
                            <p id="trackingTimestamp"><?= htmlspecialchars($currentTrackedAt ?: 'Waiting for rider') ?></p>
                        </div>
                        <div class="info-box">
                            <strong>Current rider status</strong>
                            <p id="trackingStatus"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($currentStatus))) ?></p>
                        </div>
                    </div>
                    <div class="eta-card" id="etaCard">
                        ETA: <span id="etaText">Calculating...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const apiEndpoint = <?= json_encode(BASE_URL . 'api/tracking.php') ?>;
    const rescueId = <?= intval($rescueId) ?>;
    const pickupLat = <?= json_encode($pickupLat) ?>;
    const pickupLng = <?= json_encode($pickupLng) ?>;
    const recipientLat = <?= json_encode($recipientLat) ?>;
    const recipientLng = <?= json_encode($recipientLng) ?>;
    const destinationLat = <?= json_encode($destinationLat) ?>;
    const destinationLng = <?= json_encode($destinationLng) ?>;
    const destinationLabel = <?= json_encode($destinationLabel) ?>;

    let map;
    let riderMarker;
    let pickupMarker;
    let recipientMarker;
    let routeLine;
    let latestCoords = {
        lat: <?= json_encode($currentLat ?: 0) ?>,
        lng: <?= json_encode($currentLng ?: 0) ?>
    };

    function initMap() {
        map = L.map('donorTrackMap', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors', maxZoom: 19,
        }).addTo(map);

        pickupMarker = L.marker([pickupLat, pickupLng], {
            icon: L.divIcon({ className: 'custom-marker-donor', iconSize: [18, 18], iconAnchor: [9, 9] }),
        }).addTo(map).bindPopup('<strong>Pickup</strong>');

        recipientMarker = L.marker([recipientLat, recipientLng], {
            icon: L.divIcon({ className: 'custom-marker-recipient', iconSize: [18, 18], iconAnchor: [9, 9] }),
        }).addTo(map).bindPopup('<strong>Recipient</strong>');

        if (latestCoords.lat && latestCoords.lng) {
            riderMarker = L.marker([latestCoords.lat, latestCoords.lng], {
                icon: L.divIcon({ className: 'custom-marker-rider', iconSize: [22, 22], iconAnchor: [11, 11] }),
            }).addTo(map).bindPopup('<strong>Rider</strong>');
            updateRoute();
        }

        fitMap();
    }

    function updateRoute() {
        if (!latestCoords.lat || !latestCoords.lng || !destinationLat || !destinationLng) {
            return;
        }
        if (routeLine) {
            routeLine.setLatLngs([[latestCoords.lat, latestCoords.lng], [destinationLat, destinationLng]]);
        } else {
            routeLine = L.polyline([[latestCoords.lat, latestCoords.lng], [destinationLat, destinationLng]], {
                color: '#2b6cb0', weight: 4, opacity: 0.75, dashArray: '8,6'
            }).addTo(map);
        }
        fitMap();
        updateEta();
    }

    function fitMap() {
        const bounds = L.latLngBounds();
        bounds.extend([pickupLat, pickupLng]);
        bounds.extend([recipientLat, recipientLng]);
        if (latestCoords.lat && latestCoords.lng) {
            bounds.extend([latestCoords.lat, latestCoords.lng]);
        }
        if (bounds.isValid()) {
            map.fitBounds(bounds.pad(0.18));
        }
    }

    function updateEta() {
        if (!latestCoords.lat || !latestCoords.lng || !destinationLat || !destinationLng) {
            document.getElementById('etaText').textContent = 'Waiting for rider';
            return;
        }
        const distance = haversine(latestCoords.lat, latestCoords.lng, destinationLat, destinationLng);
        const minutes = Math.max(1, Math.round(distance / 30 * 60));
        document.getElementById('etaText').textContent = `${distance.toFixed(1)} km • ${minutes} min`;
    }

    function haversine(lat1, lon1, lat2, lon2) {
        const toRad = x => x * Math.PI / 180;
        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    async function fetchTracking() {
        try {
            const response = await fetch(`${apiEndpoint}?rescue_id=${rescueId}`);
            const data = await response.json();
            if (!data.success) {
                console.warn('Tracking API error', data);
                return;
            }
            const latest = data.latest || {};
            if (latest.latitude && latest.longitude) {
                latestCoords.lat = latest.latitude;
                latestCoords.lng = latest.longitude;
                if (riderMarker) {
                    riderMarker.setLatLng([latestCoords.lat, latestCoords.lng]);
                } else {
                    riderMarker = L.marker([latestCoords.lat, latestCoords.lng], {
                        icon: L.divIcon({ className: 'custom-marker-rider', iconSize: [22, 22], iconAnchor: [11, 11] }),
                    }).addTo(map).bindPopup('<strong>Rider</strong>');
                }
                updateRoute();
            }
            if (latest.status) {
                document.getElementById('trackingStatus').textContent = latest.status.replace(/_/g, ' ');
            }
            if (latest.tracked_at) {
                document.getElementById('trackingTimestamp').textContent = latest.tracked_at;
            }
        } catch (error) {
            console.error('Failed to fetch tracking', error);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initMap();
        fetchTracking();
        setInterval(fetchTracking, 10000);
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
