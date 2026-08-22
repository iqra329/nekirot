<?php
// ============================================
// NEKIROT QUETTA - LIVE IMPACT MAP
// With live location for donors and riders
// Rider uses live location from users table
// ============================================

include_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$includeMap = true;
require_once __DIR__ . '/includes/header.php';

$db = get_db_connection();

// ============================================
// GET LISTINGS (Available + Matched)
// ============================================
$stmt = $db->prepare('
    SELECT l.id, l.title, l.description, l.contact_phone, l.latitude, l.longitude, l.status, l.created_at, 
           u.name AS donor_name, u.phone AS donor_phone, u.id AS donor_id
    FROM listings l 
    JOIN users u ON u.id = l.donor_id 
    WHERE l.status IN (?, ?) 
    ORDER BY l.created_at DESC
');
$statusPublished = 'published';
$statusMatched = 'matched';
$stmt->bind_param('ss', $statusPublished, $statusMatched);
$stmt->execute();
$result = $stmt->get_result();
$listings = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// ============================================
// GET ACTIVE RESCUES
// ============================================
$stmt = $db->prepare('
    SELECT r.id, r.title, r.description, r.status, 
           r.latitude AS rescue_latitude, r.longitude AS rescue_longitude, 
           r.created_at, 
           recipient.name AS recipient_name, recipient.phone AS recipient_phone, recipient.id AS recipient_id,
           rider.name AS rider_name, rider.phone AS rider_phone, rider.id AS rider_id
    FROM rescues r 
    LEFT JOIN users recipient ON recipient.id = r.recipient_id 
    LEFT JOIN users rider ON rider.id = r.assigned_rider_id 
    WHERE r.status IN (?, ?, ?) 
    ORDER BY r.created_at DESC
');
$statusAccepted = 'accepted';
$statusRiderAssigned = 'rider_assigned';
$statusInTransit = 'in_transit';
$stmt->bind_param('sss', $statusAccepted, $statusRiderAssigned, $statusInTransit);
$stmt->execute();
$result = $stmt->get_result();
$rescues = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// ============================================
// GET TODAY'S DELIVERIES
// ============================================
$stmt = $db->prepare('SELECT COUNT(*) AS total FROM rescues WHERE status = ? AND DATE(created_at) = CURDATE()');
$statusDelivered = 'delivered';
$stmt->bind_param('s', $statusDelivered);
$stmt->execute();
$deliveredToday = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);

// ============================================
// GET TOTAL RESCUES
// ============================================
$stmt = $db->prepare('SELECT COUNT(*) AS total FROM rescues');
$stmt->execute();
$totalRescues = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);

$db->close();
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .leaflet-div-icon {
        background: transparent !important;
        border: none !important;
    }
    #liveMap {
        height: 550px;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 8px 35px rgba(0,0,0,0.12);
        background: #e8ecf1;
        border: 2px solid rgba(43,108,176,0.15);
    }
    .stat-card-map {
        background: linear-gradient(145deg, #ffffff, #f0f7ff);
        border-radius: 16px;
        padding: 20px 16px;
        text-align: center;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        height: 100%;
        border: 1px solid rgba(43,108,176,0.15);
        position: relative;
        overflow: hidden;
    }
    .stat-card-map::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #1a3a6b, #2b6cb0, #d4a84b);
    }
    .stat-card-map:hover { transform: translateY(-6px); box-shadow: 0 12px 35px rgba(43,108,176,0.2); }
    .stat-card-map .stat-number { font-size: 1.8rem; font-weight: 800; color: #1a365d; }
    .stat-card-map .stat-label { color: #718096; font-size: 0.85rem; font-weight: 500; }
    .stat-card-map .stat-icon { font-size: 1.8rem; display: block; margin-bottom: 5px; }
    .leaflet-control-zoom { border: none !important; box-shadow: 0 2px 10px rgba(0,0,0,0.1) !important; border-radius: 10px !important; overflow: hidden; }
    .leaflet-control-zoom a { background: white !important; color: #1a1a2e !important; font-weight: 600 !important; border: none !important; }
    .leaflet-control-zoom a:hover { background: #0d6efd !important; color: white !important; }
    .leaflet-popup-content-wrapper { border-radius: 16px !important; box-shadow: 0 10px 35px rgba(0,0,0,0.15) !important; }
    .leaflet-popup-content { font-family: 'Inter', sans-serif; min-width: 240px; padding: 15px; max-width: 300px; }
    .card-map { background: white; border-radius: 18px; border: 1px solid rgba(43,108,176,0.1); box-shadow: 0 4px 25px rgba(0,0,0,0.08); }
    .card-map-body { padding: 20px; }
    .pulse-animation { animation: pulse-dot 1.5s infinite; }
    @keyframes pulse-dot { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.6; transform: scale(1.1); } }
    @keyframes bike-bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
    .activity-item { display: flex; align-items: center; gap: 12px; padding: 12px 10px; border-bottom: 1px solid #f0f0f0; transition: all 0.3s ease; border-radius: 10px; }
    .activity-item:hover { background: #f0f7ff; padding-left: 16px; }
    .activity-item:last-child { border-bottom: none; }
    .activity-item .badge-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
    .badge-icon.food { background: #ebf8ff; color: #2b6cb0; }
    .badge-icon.rider { background: #fee2e2; color: #dc2626; }
    .activity-time { font-size: 0.75rem; color: #a0aec0; white-space: nowrap; margin-left: auto; }
    .status-pill-mini { font-size: 0.65rem; padding: 4px 10px; border-radius: 999px; font-weight: 700; display: inline-block; }
    .status-pill-mini.available { background: #d1fae5; color: #065f46; }
    .status-pill-mini.matched { background: #fef3c7; color: #b7791f; }
    .status-pill-mini.in_transit { background: #e0f2fe; color: #0369a1; }
    .legend-item { display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #4a5568; padding: 8px 14px; background: #f8fafc; border-radius: 999px; border: 1px solid rgba(43,108,176,0.1); }
    .legend-dot { width: 12px; height: 12px; border-radius: 50%; border: 2px solid white; display: inline-block; box-shadow: 0 0 0 1px rgba(0,0,0,0.1); }
    @media (max-width: 768px) {
        #liveMap { height: 350px; }
        .stat-card-map .stat-number { font-size: 1.3rem; }
    }
</style>

<div class="fade-in">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="fw-bold text-primary-dark"><i class="fas fa-map"></i> Live Impact Map</h1>
            <p class="text-muted">Real-time food rescue activity across Quetta</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-success pulse-animation" style="font-size: 0.9rem; padding: 8px 16px; border-radius: 999px;">
                <i class="fas fa-circle text-white me-1" style="font-size: 8px;"></i> LIVE
            </span>
            <span class="badge bg-primary" style="font-size: 0.9rem; padding: 8px 16px; border-radius: 999px;">
                <i class="fas fa-users me-1"></i> <?= count($listings) + count($rescues) ?> Active
            </span>
        </div>
    </div>

    <div id="liveMap"></div>

    <div class="row mt-3 g-3">
        <div class="col-3 col-md-3">
            <div class="stat-card-map">
                <span class="stat-icon">🍽️</span>
                <div class="stat-number"><?= count($listings) ?></div>
                <div class="stat-label">Available</div>
            </div>
        </div>
        <div class="col-3 col-md-3">
            <div class="stat-card-map">
                <span class="stat-icon">🏍️</span>
                <div class="stat-number"><?= count($rescues) ?></div>
                <div class="stat-label">Active Riders</div>
            </div>
        </div>
        <div class="col-3 col-md-3">
            <div class="stat-card-map">
                <span class="stat-icon">✅</span>
                <div class="stat-number"><?= $deliveredToday ?></div>
                <div class="stat-label">Today's Deliveries</div>
            </div>
        </div>
        <div class="col-3 col-md-3">
            <div class="stat-card-map">
                <span class="stat-icon">⭐</span>
                <div class="stat-number"><?= $totalRescues ?></div>
                <div class="stat-label">Total Rescues</div>
            </div>
        </div>
    </div>

    <div class="card-map mt-3">
        <div class="card-map-body">
            <div class="d-flex flex-wrap gap-3 justify-content-center">
                <span class="legend-item"><span class="legend-dot" style="background:#2b6cb0;"></span> 🔵 Donor (Live)</span>
                <span class="legend-item"><span class="legend-dot" style="background:#48bb78;"></span> 🟢 Available Food</span>
                <span class="legend-item"><span class="legend-dot" style="background:#d69e2e;"></span> 🤝 Matched</span>
                <span class="legend-item">🏍️ Rider (Live)</span>
            </div>
        </div>
    </div>

    <div class="card-map mt-3">
        <div class="card-map-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0"><i class="fas fa-clock text-primary me-2"></i> Recent Activity</h5>
                <span class="badge bg-light text-muted" style="border-radius: 999px; padding: 6px 14px;"><?= count($rescues) + count($listings) ?> events</span>
            </div>
            <div style="max-height: 300px; overflow-y: auto;">
                <?php if (empty($rescues) && empty($listings)): ?>
                    <div class="text-center text-muted py-4">No activity yet.</div>
                <?php else: ?>
                    <?php 
                    $allActivities = array_merge($rescues, $listings);
                    usort($allActivities, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
                    $allActivities = array_slice($allActivities, 0, 10);
                    ?>
                    <?php foreach ($allActivities as $item): ?>
                        <?php if (isset($item['rider_name']) && $item['rider_name']): ?>
                            <div class="activity-item">
                                <span class="badge-icon rider">🏍️</span>
                                <div>
                                    <strong><?= htmlspecialchars($item['rider_name']) ?></strong>
                                    <span class="text-muted small">is delivering</span>
                                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                                    <span class="status-pill-mini in_transit ms-1">🚚 <?= ucfirst(str_replace('_', ' ', $item['status'])) ?></span>
                                </div>
                                <span class="activity-time"><?= timeAgo($item['created_at']) ?></span>
                            </div>
                        <?php else: ?>
                            <div class="activity-item">
                                <span class="badge-icon food">🍽️</span>
                                <div>
                                    <strong><?= htmlspecialchars($item['donor_name']) ?></strong>
                                    <span class="text-muted small">posted</span>
                                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                                    <?php if ($item['status'] === 'matched'): ?>
                                        <span class="status-pill-mini matched ms-1">🤝 Matched</span>
                                    <?php else: ?>
                                        <span class="status-pill-mini available ms-1">✅ Available</span>
                                    <?php endif; ?>
                                </div>
                                <span class="activity-time"><?= timeAgo($item['created_at']) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const baseUrl = <?= json_encode(BASE_URL) ?>;
    const listings = <?= json_encode($listings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const rescues = <?= json_encode($rescues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    const map = L.map('liveMap').setView([30.1798, 66.9750], 12);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
    
    L.control.scale({ position: 'bottomright', metric: true, imperial: false }).addTo(map);
    map.zoomControl.setPosition('topright');
    
    const bounds = L.latLngBounds();
    let liveMarkers = {};

    // ============================================
    // FOOD LISTING MARKERS (Green = Available, Yellow = Matched)
    // ============================================
    const foodIcon = L.divIcon({
        className: '',
        html: '<div style="background:#48bb78;width:14px;height:14px;border-radius:50%;border:2px solid white;box-shadow:0 2px 10px rgba(72,187,120,0.4);"></div>',
        iconSize: [14, 14],
        iconAnchor: [7, 7]
    });
    const matchedIcon = L.divIcon({
        className: '',
        html: '<div style="background:#d69e2e;width:14px;height:14px;border-radius:50%;border:2px solid white;box-shadow:0 2px 10px rgba(214,158,46,0.4);"></div>',
        iconSize: [14, 14],
        iconAnchor: [7, 7]
    });

    listings.forEach(function(listing) {
        if (!listing.latitude || !listing.longitude) return;
        const icon = listing.status === 'matched' ? matchedIcon : foodIcon;
        const lat = parseFloat(listing.latitude);
        const lng = parseFloat(listing.longitude);
        L.marker([lat, lng], { icon: icon })
            .addTo(map)
            .bindPopup('<strong>🍽️ ' + escapeHtml(listing.title) + '</strong><br><small>' + escapeHtml(listing.donor_name) + '</small>');
        bounds.extend([lat, lng]);
    });

    // ============================================
    // LIVE DONOR LOCATIONS (Blue Circle)
    // ============================================
    async function fetchDonorLocation(donorId, donorName) {
        if (!donorId) return;
        try {
            const res = await fetch(baseUrl + 'api/get_live_location.php?user_id=' + donorId, { cache: 'no-store' });
            const data = await res.json();
            if (!data.success || !data.latitude || !data.longitude) return;
            const lat = parseFloat(data.latitude);
            const lng = parseFloat(data.longitude);
            
            console.log('📍 Donor LIVE:', data.name, lat, lng);
            
            const donorIcon = L.divIcon({
                className: '',
                html: '<div style="background:#2b6cb0;width:18px;height:18px;border-radius:50%;border:3px solid white;box-shadow:0 2px 12px rgba(43,108,176,0.5);"></div>',
                iconSize: [18, 18],
                iconAnchor: [9, 9]
            });
            
            if (liveMarkers['donor_' + donorId]) {
                liveMarkers['donor_' + donorId].setLatLng([lat, lng]);
            } else {
                liveMarkers['donor_' + donorId] = L.marker([lat, lng], { icon: donorIcon })
                    .addTo(map)
                    .bindPopup('<strong>🔵 ' + escapeHtml(data.name || donorName) + '</strong> (Donor)');
            }
            bounds.extend([lat, lng]);
        } catch (e) { console.log('Donor live error:', e); }
    }

    // ============================================
    // LIVE RIDER LOCATIONS (Bike Icon)
    // ============================================
    async function fetchRiderLocation(riderId, riderName, rescueTitle, rescueStatus) {
        if (!riderId) return;
        try {
            const res = await fetch(baseUrl + 'api/get_live_location.php?user_id=' + riderId, { cache: 'no-store' });
            const data = await res.json();
            if (!data.success || !data.latitude || !data.longitude) return;
            const lat = parseFloat(data.latitude);
            const lng = parseFloat(data.longitude);
            
            console.log('📍 Rider LIVE on map:', data.name, lat, lng);
            
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
                    .bindPopup(
                        '<strong>🏍️ ' + escapeHtml(data.name || riderName) + '</strong><br>' +
                        '<small>' + escapeHtml(rescueTitle || '') + '</small><br>' +
                        '<span class="badge bg-warning">' + (rescueStatus || '').replace(/_/g, ' ') + '</span>'
                    );
            }
            bounds.extend([lat, lng]);
            
            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
            }
        } catch (e) { console.log('Rider live error:', e); }
    }

    // Fetch all live locations
    listings.forEach(function(listing) {
        if (listing.donor_id) {
            fetchDonorLocation(listing.donor_id, listing.donor_name);
            setInterval(function() { fetchDonorLocation(listing.donor_id, listing.donor_name); }, 5000);
        }
    });

    rescues.forEach(function(rescue) {
        if (rescue.rider_id) {
            fetchRiderLocation(rescue.rider_id, rescue.rider_name, rescue.title, rescue.status);
            setInterval(function() { fetchRiderLocation(rescue.rider_id, rescue.rider_name, rescue.title, rescue.status); }, 5000);
        }
    });

    // Initial fit
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

setInterval(function() { location.reload(); }, 30000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>