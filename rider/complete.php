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

$rescueId = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($rescueId <= 0) {
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

$db = get_db_connection();

$stmt = $db->prepare(
    'SELECT r.*, 
            u.name AS recipient_name, u.phone AS recipient_phone, u.latitude AS recipient_lat, u.longitude AS recipient_lng,
            l.id AS listing_id, l.latitude AS donor_lat, l.longitude AS donor_lng,
            d.name AS donor_name, d.phone AS donor_phone
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
if ($assignedRiderId > 0 && $assignedRiderId !== intval($user['id'])) {
    $db->close();
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

$status = strtolower(trim($rescue['status'] ?? 'pending'));
$canComplete = ($status === 'in_transit');
$error = '';
$success = '';

$stmt = $db->prepare('SELECT latitude, longitude FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$riderLocation = $stmt->get_result()->fetch_assoc();
$riderLat = floatval($riderLocation['latitude'] ?? 0);
$riderLng = floatval($riderLocation['longitude'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canComplete) {
        $error = 'This delivery cannot be completed because it is not in transit.';
    } elseif ($riderLat === 0 || $riderLng === 0) {
        $error = 'Your saved location is missing. Please update your profile before marking delivery complete.';
    } else {
        $db->begin_transaction();
        try {
            $newStatus = 'delivered';
            $update = $db->prepare('UPDATE rescues SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?');
            $update->bind_param('sis', $newStatus, $rescueId, $status);
            $update->execute();

            if ($update->affected_rows !== 1) {
                throw new Exception('Unable to update rescue status.');
            }

            // ============================================
            // FIXED: Removed 'status' from tracking insert
            // ============================================
            $tracking = $db->prepare(
                'INSERT INTO tracking (rescue_id, rider_id, latitude, longitude, tracked_at) VALUES (?, ?, ?, ?, NOW())'
            );
            $tracking->bind_param('iidd', $rescueId, $user['id'], $riderLat, $riderLng);
            $tracking->execute();

            if (!empty($rescue['listing_id'])) {
                $stmt = $db->prepare('UPDATE listings SET status = ? WHERE id = ?');
                $completedStatus = 'completed';
                $stmt->bind_param('si', $completedStatus, $rescue['listing_id']);
                $stmt->execute();
            }

            $db->commit();
            $success = '✅ Delivery completed successfully. Thank you for helping the community! Redirecting to your dashboard…';
            $canComplete = false;
            $status = $newStatus;
        } catch (Exception $ex) {
            $db->rollback();
            $error = 'Unable to complete the delivery. ' . $ex->getMessage();
        }
    }
}

$db->close();

$pickupLat = floatval($rescue['donor_lat'] ?? 0);
$pickupLng = floatval($rescue['donor_lng'] ?? 0);
if ($pickupLat === 0 || $pickupLng === 0) {
    $pickupLat = floatval($rescue['latitude'] ?? 0);
    $pickupLng = floatval($rescue['longitude'] ?? 0);
}
$recipientLat = floatval($rescue['recipient_lat'] ?? 0);
$recipientLng = floatval($rescue['recipient_lng'] ?? 0);
$donorName = $rescue['donor_name'] ?: 'Donor';
$donorPhone = $rescue['donor_phone'] ?: 'N/A';
$recipientName = $rescue['recipient_name'] ?: 'Recipient';
$recipientPhone = $rescue['recipient_phone'] ?: 'N/A';

$distanceKm = 0;
if ($riderLat && $riderLng && $recipientLat && $recipientLng) {
    $distanceKm = calculate_distance_km($riderLat, $riderLng, $recipientLat, $recipientLng);
}

$includeMap = false;
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    #completeMap {
        height: 420px;
        border-radius: 18px;
        border: 1px solid rgba(26,58,107,0.08);
        background: #f7fafc;
        overflow: hidden;
    }
    .complete-card {
        border-radius: 18px;
        box-shadow: 0 18px 40px rgba(15,23,42,0.08);
        border: 1px solid rgba(26,58,107,0.08);
        background: white;
    }
    .complete-card .card-header {
        background: transparent;
        border-bottom: 1px solid rgba(226,232,240,0.9);
        font-weight: 700;
        color: #1a365d;
    }
    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        margin-bottom: 18px;
    }
    .info-box {
        background: #f8fafc;
        padding: 16px;
        border-radius: 16px;
        border: 1px solid rgba(66,153,225,0.12);
    }
    .info-box h6 {
        margin-bottom: 8px;
        font-size: 0.9rem;
        color: #4a5568;
    }
    .info-box p {
        margin: 0;
        color: #1a202c;
        font-weight: 700;
    }
    .btn-complete {
        width: 100%;
        background: linear-gradient(135deg, #2b6cb0, #1a365d);
        color: white;
        border: none;
        padding: 14px 24px;
        border-radius: 18px;
        font-weight: 700;
    }
    .btn-complete:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 26px rgba(43,108,176,0.2);
    }
    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        border-radius: 999px;
        font-weight: 700;
        background: #e0f2fe;
        color: #0369a1;
    }
    .status-pill.delivered {
        background: #d1fae5;
        color: #065f46;
    }
    .eta-box {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 12px 18px;
        border-radius: 20px;
        background: #e0f2fe;
        color: #0369a1;
        font-weight: 700;
    }
    .alert-success,
    .alert-danger {
        border-radius: 16px;
    }
    .custom-marker-pickup {
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
    @keyframes pulse-rider {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.12); }
    }
</style>

<div class="container py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                <div>
                    <h1 class="h3 fw-bold">Complete Delivery</h1>
                    <p class="text-muted mb-0">Mark the rescue delivered once you reach the recipient.</p>
                </div>
                <span class="status-pill <?= htmlspecialchars($status === 'delivered' ? 'delivered' : '') ?>">
                    <?= htmlspecialchars(str_replace('_', ' ', ucfirst($status))) ?>
                </span>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="complete-card p-4">
                <div class="card-header mb-3">Delivery Summary</div>

                <div class="info-grid">
                    <div class="info-box">
                        <h6>Pickup Location</h6>
                        <p><?= $pickupLat && $pickupLng ? htmlspecialchars(number_format($pickupLat, 5) . ', ' . number_format($pickupLng, 5)) : 'Unknown' ?></p>
                    </div>
                    <div class="info-box">
                        <h6>Recipient Location</h6>
                        <p><?= $recipientLat && $recipientLng ? htmlspecialchars(number_format($recipientLat, 5) . ', ' . number_format($recipientLng, 5)) : 'Unknown' ?></p>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-box">
                        <h6>Donor</h6>
                        <p><?= htmlspecialchars($donorName) ?></p>
                    </div>
                    <div class="info-box">
                        <h6>Recipient</h6>
                        <p><?= htmlspecialchars($recipientName) ?></p>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-box">
                        <h6>Donor Contact</h6>
                        <p><?= htmlspecialchars($donorPhone) ?></p>
                    </div>
                    <div class="info-box">
                        <h6>Recipient Contact</h6>
                        <p><?= htmlspecialchars($recipientPhone) ?></p>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-box">
                        <h6>Distance to Recipient</h6>
                        <p><?= $distanceKm > 0 ? htmlspecialchars(number_format($distanceKm, 2) . ' km') : 'Unknown' ?></p>
                    </div>
                    <div class="info-box">
                        <h6>Delivery Time Recorded</h6>
                        <p><?= htmlspecialchars($success ? date('M j, Y h:i A') : 'Will be recorded on completion') ?></p>
                    </div>
                </div>

                <?php if (!$success): ?>
                    <form method="post" class="mt-3">
                        <input type="hidden" name="id" value="<?= intval($rescueId) ?>">
                        <button type="submit" class="btn btn-complete">Complete Delivery</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="complete-card p-0">
                <div id="completeMap"></div>
            </div>
        </div>
    </div>
</div>

<script>
    const pickupLat = <?= json_encode($pickupLat) ?>;
    const pickupLng = <?= json_encode($pickupLng) ?>;
    const recipientLat = <?= json_encode($recipientLat) ?>;
    const recipientLng = <?= json_encode($recipientLng) ?>;
    const riderLat = <?= json_encode($riderLat) ?>;
    const riderLng = <?= json_encode($riderLng) ?>;
    const donorName = <?= json_encode($donorName) ?>;
    const recipientName = <?= json_encode($recipientName) ?>;
    const redirect = <?= json_encode(!empty($success)) ?>;

    document.addEventListener('DOMContentLoaded', function () {
        const map = L.map('completeMap', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        const bounds = L.latLngBounds();

        if (pickupLat && pickupLng) {
            const pickupIcon = L.divIcon({ className: 'custom-marker-pickup', iconSize: [16, 16], iconAnchor: [8, 8] });
            L.marker([pickupLat, pickupLng], { icon: pickupIcon })
                .addTo(map)
                .bindPopup('<strong>📍 Pickup</strong><br>' + escapeHtml(donorName));
            bounds.extend([pickupLat, pickupLng]);
        }

        if (recipientLat && recipientLng) {
            const recipientIcon = L.divIcon({ className: 'custom-marker-recipient', iconSize: [16, 16], iconAnchor: [8, 8] });
            L.marker([recipientLat, recipientLng], { icon: recipientIcon })
                .addTo(map)
                .bindPopup('<strong>🏠 Recipient</strong><br>' + escapeHtml(recipientName));
            bounds.extend([recipientLat, recipientLng]);
        }

        if (riderLat && riderLng) {
            const riderIcon = L.divIcon({ className: 'custom-marker-rider', iconSize: [22, 22], iconAnchor: [11, 11] });
            L.marker([riderLat, riderLng], { icon: riderIcon })
                .addTo(map)
                .bindPopup('<strong>🏍️ Rider</strong>');
            bounds.extend([riderLat, riderLng]);
        }

        if (pickupLat && pickupLng && recipientLat && recipientLng) {
            L.polyline([[pickupLat, pickupLng], [recipientLat, recipientLng]], {
                color: '#2b6cb0',
                weight: 4,
                opacity: 0.65,
                dashArray: '8, 6'
            }).addTo(map);
        }

        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
        } else {
            map.setView([30.1798, 66.9750], 12);
        }

        if (redirect) {
            setTimeout(function () {
                window.location.href = '<?= BASE_URL ?>rider/dashboard.php';
            }, 2800);
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>>