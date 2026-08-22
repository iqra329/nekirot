<?php
// ============================================
// NEKIROT QUETTA - ACCEPT DELIVERY PAGE
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

$stmt = $db->prepare(
    'SELECT r.*, 
            u.name AS recipient_name, u.phone AS recipient_phone, 
            u.latitude AS recipient_lat, u.longitude AS recipient_lng,
            l.id AS listing_id, 
            l.latitude AS donor_lat, l.longitude AS donor_lng, 
            l.contact_phone AS donor_phone,
            d.name AS donor_name, d.phone AS donor_user_phone,
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

$assignedRiderId = intval($rescue['assigned_rider_id'] ?? 0);
$currentStatus = strtolower(trim($rescue['status'] ?? 'pending'));
$allowedStatuses = ['pending', 'accepted_by_recipient'];

if ($assignedRiderId > 0 && $assignedRiderId !== intval($user['id'])) {
    $db->close();
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS active_count FROM rescues WHERE assigned_rider_id = ? AND status NOT IN ("delivered", "cancelled")'
    );
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $activeCount = intval($stmt->get_result()->fetch_assoc()['active_count']);

    if ($activeCount > 0) {
        $error = 'You already have an active delivery. Complete or cancel it before accepting a new one.';
    } elseif (!in_array($currentStatus, $allowedStatuses, true)) {
        $error = 'This rescue cannot be accepted because it is not pending.';
    } else {
        $db->begin_transaction();
        try {
            $newStatus = 'accepted';
            $update = $db->prepare(
                'UPDATE rescues SET status = ?, assigned_rider_id = ?, updated_at = NOW() WHERE id = ? AND status IN ("pending", "accepted_by_recipient") AND (assigned_rider_id IS NULL OR assigned_rider_id = 0)'
            );
            $update->bind_param('sii', $newStatus, $user['id'], $rescueId);
            $update->execute();

            if ($update->affected_rows !== 1) {
                throw new Exception('Unable to assign you to this rescue. It may already be taken.');
            }

            if (!empty($rescue['listing_id'])) {
                $db->query("UPDATE listings SET status = 'matched' WHERE id = " . intval($rescue['listing_id']));
            }

            $db->commit();
            $success = '✅ Delivery accepted successfully. Redirecting to your dashboard…';
            $currentStatus = $newStatus;
            $assignedRiderId = $user['id'];
        } catch (Exception $ex) {
            $db->rollback();
            $error = 'Unable to accept the delivery. ' . $ex->getMessage();
        }
    }
}

$db->close();

// Locations
$pickupLat = floatval($rescue['donor_lat'] ?? 0);
$pickupLng = floatval($rescue['donor_lng'] ?? 0);

if ($pickupLat === 0 || $pickupLng === 0) {
    $pickupLat = floatval($rescue['donor_user_lat'] ?? 0);
    $pickupLng = floatval($rescue['donor_user_lng'] ?? 0);
}

if ($pickupLat === 0 || $pickupLng === 0) {
    $pickupLat = floatval($rescue['latitude'] ?? 0);
    $pickupLng = floatval($rescue['longitude'] ?? 0);
}

$dropoffLat = floatval($rescue['recipient_lat'] ?? 0);
$dropoffLng = floatval($rescue['recipient_lng'] ?? 0);

if ($dropoffLat === 0 || $dropoffLng === 0) {
    $dropoffLat = floatval($rescue['latitude'] ?? 0);
    $dropoffLng = floatval($rescue['longitude'] ?? 0);
}

$hasPickupLocation = ($pickupLat !== 0 && $pickupLng !== 0);
$hasDropoffLocation = ($dropoffLat !== 0 && $dropoffLng !== 0);

$donorName = $rescue['donor_name'] ?: 'Donor';
$donorPhone = $rescue['donor_user_phone'] ?: $rescue['donor_phone'] ?: 'N/A';
$recipientName = $rescue['recipient_name'] ?: 'Recipient';
$recipientPhone = $rescue['recipient_phone'] ?: 'N/A';

$riderId = intval($user['id']);

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
    #acceptMap {
        height: 400px !important;
        min-height: 400px !important;
        width: 100% !important;
        border-radius: 18px;
        border: 1px solid rgba(26,58,107,0.08);
        background: #f7fafc;
        display: block !important;
        position: relative !important;
        z-index: 1 !important;
    }
    .leaflet-control-zoom {
        border: none !important;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1) !important;
        z-index: 10 !important;
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
    .accept-card {
        background: white;
        border-radius: 18px;
        border: 1px solid rgba(26,58,107,0.08);
        box-shadow: 0 18px 40px rgba(15,23,42,0.08);
    }
    .accept-card .card-header {
        background: transparent;
        border-bottom: 1px solid rgba(226,232,240,0.9);
        font-weight: 700;
        color: #1a365d;
    }
    .info-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 16px;
    }
    .info-item {
        background: #f8fafc;
        padding: 14px 16px;
        border-radius: 14px;
        border: 1px solid rgba(66,153,225,0.12);
    }
    .info-item h6 {
        margin-bottom: 8px;
        font-size: 0.85rem;
        color: #4a5568;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .info-item p {
        margin-bottom: 0;
        font-size: 0.95rem;
        color: #1a202c;
        font-weight: 600;
    }
    .info-item .location-missing {
        color: #dc2626;
        font-weight: 400;
        font-size: 0.85rem;
    }
    .btn-accept-page {
        background: linear-gradient(135deg, #2b6cb0, #1a365d);
        border: none;
        color: white;
        padding: 14px 24px;
        border-radius: 18px;
        font-weight: 700;
        width: 100%;
        letter-spacing: 0.02em;
    }
    .btn-accept-page:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 26px rgba(43,108,176,0.2);
        color: white;
    }
    .btn-accept-page:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        border-radius: 999px;
        font-weight: 700;
        color: #1a365d;
        background: #e0f2fe;
    }
    .status-pill.accepted {
        background: #d1fae5;
        color: #065f46;
    }
    .alert-success,
    .alert-danger {
        border-radius: 16px;
    }
    .location-warning {
        background: #fef3c7;
        border: 1px solid #f6c23e;
        border-radius: 12px;
        padding: 12px 16px;
        color: #b7791f;
        font-weight: 500;
        margin-bottom: 16px;
    }
    .location-warning i {
        margin-right: 8px;
    }
    .custom-marker-pickup {
        background: #48bb78;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 2px 10px rgba(72,187,120,0.4);
    }
    .custom-marker-dropoff {
        background: #fc8181;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 2px 10px rgba(252,129,129,0.4);
    }
</style>

<div class="container py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                <div>
                    <h1 class="h3 fw-bold">Accept Delivery</h1>
                    <p class="text-muted mb-0">Confirm this rescue and assign it to your rider account.</p>
                </div>
                <span class="status-pill <?= htmlspecialchars($currentStatus === 'accepted' ? 'accepted' : '') ?>">
                    <?= htmlspecialchars(str_replace('_', ' ', ucfirst($currentStatus))) ?>
                </span>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$hasDropoffLocation): ?>
        <div class="location-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>⚠️ Recipient location is missing!</strong> 
        </div>
    <?php endif; ?>

    <?php if (!$hasPickupLocation): ?>
        <div class="location-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>⚠️ Donor location is missing!</strong> 
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="accept-card p-4">
                <div class="card-header mb-3">Rescue & Contact Details</div>

                <div class="info-row">
                    <div class="info-item">
                        <h6>Rescue Title</h6>
                        <p><?= htmlspecialchars($rescue['title']) ?></p>
                    </div>
                    <div class="info-item">
                        <h6>Status</h6>
                        <p><?= htmlspecialchars(str_replace('_', ' ', ucfirst($currentStatus))) ?></p>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-item">
                        <h6>📦 Donor</h6>
                        <p><?= htmlspecialchars($donorName) ?></p>
                        <small><?= htmlspecialchars($donorPhone) ?></small>
                    </div>
                    <div class="info-item">
                        <h6>📍 Pickup Location</h6>
                        <?php if ($hasPickupLocation): ?>
                            <p><?= number_format($pickupLat, 5) . ', ' . number_format($pickupLng, 5) ?></p>
                        <?php else: ?>
                            <p class="location-missing">❌ Location not set</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-item">
                        <h6>🏠 Recipient</h6>
                        <p><?= htmlspecialchars($recipientName) ?></p>
                        <small><?= htmlspecialchars($recipientPhone) ?></small>
                    </div>
                    <div class="info-item">
                        <h6>📍 Dropoff Location</h6>
                        <?php if ($hasDropoffLocation): ?>
                            <p><?= number_format($dropoffLat, 5) . ', ' . number_format($dropoffLng, 5) ?></p>
                        <?php else: ?>
                            <p class="location-missing">❌ Location not set</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Rider Live Location -->
                <div class="info-row">
                    <div class="info-item">
                        <h6>🏍️ Your Current Location</h6>
                        <p id="riderLocationDisplay">Loading...</p>
                    </div>
                    <div class="info-item">
                        <h6>📍 Your Coordinates (Live)</h6>
                        <p id="riderCoordsDisplay">Fetching live location...</p>
                    </div>
                </div>

                <?php if (!$success): ?>
                    <form method="post" class="mt-4">
                        <input type="hidden" name="id" value="<?= intval($rescueId) ?>">
                        <button type="submit" class="btn-accept-page" <?= (!$hasPickupLocation || !$hasDropoffLocation) ? 'disabled' : '' ?>>
                            <?php if (!$hasPickupLocation || !$hasDropoffLocation): ?>
                                ⚠️ Locations Missing - Contact Donor/Recipient
                            <?php else: ?>
                                🚀 Accept Delivery
                            <?php endif; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="accept-card p-0">
                <div id="acceptMap"></div>
            </div>
        </div>
    </div>
</div>

<script>
    const pickupLat = <?= json_encode($pickupLat) ?>;
    const pickupLng = <?= json_encode($pickupLng) ?>;
    const dropoffLat = <?= json_encode($dropoffLat) ?>;
    const dropoffLng = <?= json_encode($dropoffLng) ?>;
    const riderId = <?= json_encode($riderId) ?>;
    const donorName = <?= json_encode($donorName) ?>;
    const recipientName = <?= json_encode($recipientName) ?>;
    const hasPickup = <?= json_encode($hasPickupLocation) ?>;
    const hasDropoff = <?= json_encode($hasDropoffLocation) ?>;
    const redirect = <?= json_encode(!empty($success)) ?>;
    const baseUrl = <?= json_encode(BASE_URL) ?>;

    document.addEventListener('DOMContentLoaded', function () {
        const mapContainer = document.getElementById('acceptMap');
        if (!mapContainer) return;
        if (typeof L === 'undefined') return;

        const map = L.map('acceptMap', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        const bounds = L.latLngBounds();
        let riderMarkerLive = null;

        // Pickup marker
        if (hasPickup && pickupLat && pickupLng) {
            const pickupIcon = L.divIcon({ className: 'custom-marker-pickup', iconSize: [18, 18], iconAnchor: [9, 9] });
            L.marker([pickupLat, pickupLng], { icon: pickupIcon })
                .addTo(map)
                .bindPopup('<strong>📍 Pickup</strong><br>' + escapeHtml(donorName));
            bounds.extend([pickupLat, pickupLng]);
        }

        // Dropoff marker
        if (hasDropoff && dropoffLat && dropoffLng) {
            const dropoffIcon = L.divIcon({ className: 'custom-marker-dropoff', iconSize: [18, 18], iconAnchor: [9, 9] });
            L.marker([dropoffLat, dropoffLng], { icon: dropoffIcon })
                .addTo(map)
                .bindPopup('<strong>🏠 Dropoff</strong><br>' + escapeHtml(recipientName));
            bounds.extend([dropoffLat, dropoffLng]);
        }

        // Live rider location
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
                
                // Update display fields
                const displayEl = document.getElementById('riderLocationDisplay');
                const coordsEl = document.getElementById('riderCoordsDisplay');
                if (displayEl) {
                    displayEl.textContent = data.name ? '📍 ' + data.name : '📍 Live';
                }
                if (coordsEl) {
                    coordsEl.textContent = lat.toFixed(5) + ', ' + lng.toFixed(5);
                }
                
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
                    map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
                }
                
            } catch (e) {
                console.log('Rider location error:', e);
            }
        }

        fetchRiderLocation();
        setInterval(fetchRiderLocation, 5000);

        setTimeout(function() {
            map.invalidateSize();
            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
            } else {
                map.setView([30.1798, 66.9750], 12);
            }
        }, 500);

        if (redirect) {
            setTimeout(function () {
                window.location.href = baseUrl + 'rider/dashboard.php';
            }, 2600);
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