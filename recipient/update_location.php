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
$userId = $user['id'];

// Get current user data
$stmt = $db->prepare('SELECT id, name, latitude, longitude FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    
    if ($latitude == 0 || $longitude == 0) {
        $error = 'Please set your location on the map.';
    } elseif (!validate_quetta_bounds($latitude, $longitude)) {
        $error = 'Location must be within Quetta city bounds (30.13-30.25 lat, 66.92-67.05 lng)';
    } else {
        $stmt = $db->prepare('UPDATE users SET latitude = ?, longitude = ? WHERE id = ?');
        $stmt->bind_param('ddi', $latitude, $longitude, $userId);
        
        if ($stmt->execute()) {
            $message = '✅ Location updated successfully!';
            $userData['latitude'] = $latitude;
            $userData['longitude'] = $longitude;
        } else {
            $error = '❌ Failed to update location.';
        }
    }
}

$db->close();

$includeMap = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    #locationMap {
        height: 350px;
        border-radius: 12px;
        overflow: hidden;
        background: #e8ecf1;
        border: 1px solid rgba(26,58,107,0.06);
    }
    .location-card {
        max-width: 800px;
        margin: 0 auto;
        background: white;
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 2px 20px rgba(0,0,0,0.08);
        border: 1px solid rgba(26,58,107,0.06);
    }
    .btn-update {
        background: linear-gradient(135deg, #2b6cb0, #1a365d);
        color: white;
        border: none;
        padding: 14px 40px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 1.1rem;
        transition: all 0.3s ease;
        width: 100%;
    }
    .btn-update:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 25px rgba(43,108,176,0.3);
        color: white;
    }
    .btn-gps {
        background: #2b6cb0;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 10px;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    .btn-gps:hover {
        background: #1a365d;
        transform: translateY(-2px);
        color: white;
    }
    .location-status {
        padding: 10px 16px;
        border-radius: 10px;
        font-weight: 500;
        margin-top: 10px;
    }
    .location-status.success {
        background: #d1fae5;
        color: #065f46;
    }
    .location-status.error {
        background: #fee2e2;
        color: #991b1b;
    }
    .location-status.info {
        background: #e0f2fe;
        color: #0369a1;
    }
    .location-status.loading {
        background: #fef3c7;
        color: #b7791f;
    }
    .custom-marker-profile {
        background: #2b6cb0;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 2px 15px rgba(43,108,176,0.4);
        animation: pulse-marker 2s infinite;
    }
    @keyframes pulse-marker {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.15); }
    }
    .form-control-profile {
        border: 2px solid #e8e8f0;
        border-radius: 12px;
        padding: 12px 16px;
        transition: all 0.3s ease;
        width: 100%;
    }
    .form-control-profile:focus {
        border-color: #2b6cb0;
        box-shadow: 0 0 0 4px rgba(43,108,176,0.08);
        outline: none;
    }
    .form-label-profile {
        font-weight: 600;
        color: #2d3748;
        font-size: 0.9rem;
        margin-bottom: 6px;
    }
    .preset-btn {
        background: #f7fafc;
        border: 1px solid #e8e8f0;
        border-radius: 8px;
        padding: 6px 14px;
        font-size: 0.8rem;
        transition: all 0.3s ease;
        cursor: pointer;
    }
    .preset-btn:hover {
        background: #2b6cb0;
        color: white;
        border-color: #2b6cb0;
    }
    .preset-btn.active {
        background: #2b6cb0;
        color: white;
        border-color: #2b6cb0;
    }
    .gps-spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255,255,255,0.3);
        border-top-color: white;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
</style>

<div class="container py-4">
    <div class="location-card">
        <h2 class="fw-bold mb-3">
            <i class="fas fa-map-pin text-primary me-2"></i> Update Your Location
        </h2>
        <p class="text-muted mb-4">Set your location so riders can find you easily for deliveries.</p>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success border-0 rounded-4">
                <i class="fas fa-check-circle me-2"></i> <?= $message ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger border-0 rounded-4">
                <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Current Location Status -->
        <div class="mb-3">
            <div id="locationStatus" class="location-status <?= ($userData['latitude'] && $userData['longitude']) ? 'success' : 'info' ?>">
                <i class="fas fa-info-circle me-2"></i>
                <?php if ($userData['latitude'] && $userData['longitude']): ?>
                    📍 Current location: <?= number_format($userData['latitude'], 6) ?>, <?= number_format($userData['longitude'], 6) ?>
                    <?php if (validate_quetta_bounds($userData['latitude'], $userData['longitude'])): ?>
                        ✅ Within Quetta
                    <?php else: ?>
                        ⚠️ Outside Quetta
                    <?php endif; ?>
                <?php else: ?>
                    ⚠️ No location set. Use GPS or click on the map.
                <?php endif; ?>
            </div>
        </div>

        <!-- GPS Button -->
        <div class="row g-2 mb-3">
            <div class="col-md-8">
                <button type="button" id="gpsButton" class="btn-gps w-100">
                    <i class="fas fa-satellite-dish me-2"></i> 
                    <span id="gpsButtonText">Get GPS Location</span>
                </button>
            </div>
            <div class="col-md-4">
                <button type="button" id="findMaps" class="btn-gps w-100" style="background: #ea4335;">
                    <i class="fab fa-google me-2"></i> Maps
                </button>
            </div>
        </div>

        <!-- Map -->
        <div id="locationMap" class="mb-3"></div>

        <!-- Quick Presets -->
        <div class="mb-3">
            <label class="form-label-profile">Quick Location Presets</label>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="preset-btn" data-lat="30.1850" data-lng="66.9800">Jinnah Road</button>
                <button type="button" class="preset-btn" data-lat="30.1790" data-lng="66.9760">Shahrah-e-Zarghun</button>
                <button type="button" class="preset-btn" data-lat="30.1750" data-lng="66.9700">Brewery Road</button>
                <button type="button" class="preset-btn" data-lat="30.1900" data-lng="66.9900">Satellite Town</button>
                <button type="button" class="preset-btn" data-lat="30.1700" data-lng="66.9650">Sariab Road</button>
                <button type="button" class="preset-btn" data-lat="30.1880" data-lng="66.9820">Alamdar Road</button>
                <button type="button" class="preset-btn" data-lat="30.1950" data-lng="66.9950">Pashtoonabad</button>
            </div>
            <div class="form-text text-muted">Quetta bounds: Lat 30.13–30.25, Lng 66.92–67.05</div>
        </div>

        <!-- Coordinates -->
        <form method="POST" action="">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label-profile">Latitude</label>
                    <input type="number" step="any" name="latitude" id="latitude" 
                           class="form-control-profile" 
                           value="<?= htmlspecialchars($userData['latitude'] ?? '') ?>"
                           placeholder="e.g., 30.1798" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label-profile">Longitude</label>
                    <input type="number" step="any" name="longitude" id="longitude" 
                           class="form-control-profile" 
                           value="<?= htmlspecialchars($userData['longitude'] ?? '') ?>"
                           placeholder="e.g., 66.9750" required>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn-update">
                    <i class="fas fa-save me-2"></i> Save Location
                </button>
            </div>
        </form>

        <!-- Back Button -->
        <div class="mt-3 text-center">
            <a href="<?= BASE_URL ?>recipient/dashboard.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================
    // MAP INITIALIZATION
    // ============================================
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const statusDiv = document.getElementById('locationStatus');
    const gpsButton = document.getElementById('gpsButton');
    const gpsButtonText = document.getElementById('gpsButtonText');

    let currentLat = parseFloat(latInput.value) || 30.1798;
    let currentLng = parseFloat(lngInput.value) || 66.9750;

    const map = L.map('locationMap').setView([currentLat, currentLng], 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    // Marker
    const markerIcon = L.divIcon({
        className: 'custom-marker-profile',
        iconSize: [20, 20],
        iconAnchor: [10, 10]
    });

    let marker = L.marker([currentLat, currentLng], { 
        icon: markerIcon, 
        draggable: true 
    }).addTo(map);

    // ============================================
    // UPDATE LOCATION FUNCTION
    // ============================================
    function updateLocation(lat, lng) {
        currentLat = lat;
        currentLng = lng;
        
        latInput.value = lat.toFixed(6);
        lngInput.value = lng.toFixed(6);
        
        marker.setLatLng([lat, lng]);
        map.setView([lat, lng], 14);
        
        // Check if in Quetta
        if (lat >= 30.13 && lat <= 30.25 && lng >= 66.92 && lng <= 67.05) {
            statusDiv.className = 'location-status success';
            statusDiv.innerHTML = '<i class="fas fa-check-circle me-2"></i> ✅ Location set: ' + lat.toFixed(6) + ', ' + lng.toFixed(6) + ' (Within Quetta)';
        } else {
            statusDiv.className = 'location-status error';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i> ⚠️ Location outside Quetta bounds: ' + lat.toFixed(6) + ', ' + lng.toFixed(6);
        }
    }

    // ============================================
    // GPS BUTTON
    // ============================================
    if (gpsButton) {
        gpsButton.addEventListener('click', function() {
            if (!navigator.geolocation) {
                statusDiv.className = 'location-status error';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i> ❌ GPS not supported by your browser.';
                return;
            }

            gpsButton.disabled = true;
            gpsButtonText.innerHTML = '<span class="gps-spinner"></span> Getting location...';
            statusDiv.className = 'location-status loading';
            statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Getting your location...';

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    updateLocation(lat, lng);
                    statusDiv.className = 'location-status success';
                    statusDiv.innerHTML = '<i class="fas fa-check-circle me-2"></i> ✅ GPS location acquired!';
                    gpsButton.disabled = false;
                    gpsButtonText.innerHTML = '<i class="fas fa-satellite-dish"></i> Get GPS Location';
                },
                function(error) {
                    let errorMsg = '';
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            errorMsg = 'Permission denied. Please allow location access.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMsg = 'Location unavailable. Please try again.';
                            break;
                        case error.TIMEOUT:
                            errorMsg = 'Request timed out. Please try again.';
                            break;
                        default:
                            errorMsg = error.message;
                    }
                    statusDiv.className = 'location-status error';
                    statusDiv.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i> ❌ ' + errorMsg;
                    gpsButton.disabled = false;
                    gpsButtonText.innerHTML = '<i class="fas fa-satellite-dish"></i> Get GPS Location';
                },
                { enableHighAccuracy: true, timeout: 15000 }
            );
        });
    }

    // ============================================
    // MAP EVENTS
    // ============================================
    marker.on('dragend', function() {
        const pos = marker.getLatLng();
        updateLocation(pos.lat, pos.lng);
    });

    map.on('click', function(e) {
        const pos = e.latlng;
        marker.setLatLng([pos.lat, pos.lng]);
        updateLocation(pos.lat, pos.lng);
    });

    // ============================================
    // PRESET BUTTONS
    // ============================================
    document.querySelectorAll('.preset-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const lat = parseFloat(this.dataset.lat);
            const lng = parseFloat(this.dataset.lng);
            updateLocation(lat, lng);
            marker.setLatLng([lat, lng]);
            map.setView([lat, lng], 14);
        });
    });

    // ============================================
    // GOOGLE MAPS BUTTON
    // ============================================
    document.getElementById('findMaps').addEventListener('click', function() {
        const lat = latInput.value || 30.1798;
        const lng = lngInput.value || 66.9750;
        window.open(`https://www.google.com/maps/search/?api=1&query=${lat},${lng}`, '_blank');
    });

    // ============================================
    // INIT
    // ============================================
    if (currentLat && currentLng) {
        updateLocation(currentLat, currentLng);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>