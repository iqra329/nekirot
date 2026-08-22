<?php
// ============================================
// NEKIROT QUETTA - PROFILE PAGE
// With live location tracking
// ============================================

include_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

require_login();
$user = current_user();

if (!$user) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$db = get_db_connection();
$userId = intval($user['id']);
$userType = $user['type'];

// Check if latitude/longitude columns exist
$hasLocationColumns = false;
$columnCheck = $db->query("SHOW COLUMNS FROM users LIKE 'latitude'");
if ($columnCheck && $columnCheck->num_rows > 0) {
    $hasLocationColumns = true;
}

// If columns don't exist, add them
if (!$hasLocationColumns) {
    $db->query("ALTER TABLE users ADD COLUMN latitude DECIMAL(10,6) DEFAULT NULL");
    $db->query("ALTER TABLE users ADD COLUMN longitude DECIMAL(10,6) DEFAULT NULL");
    $hasLocationColumns = true;
}

// Get user data
$stmt = $db->prepare('SELECT id, name, phone, email, latitude, longitude, user_type FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();

// If latitude/longitude are NULL, set defaults
if ($userData && (empty($userData['latitude']) || empty($userData['longitude']))) {
    $userData['latitude'] = $userData['latitude'] ?? 30.1798;
    $userData['longitude'] = $userData['longitude'] ?? 66.9750;
}

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? $userData['name'] ?? '');
    $phone = trim($_POST['phone'] ?? $userData['phone'] ?? '');
    $latitude = floatval($_POST['latitude'] ?? $userData['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? $userData['longitude'] ?? 0);
    
    // Validation
    if (empty($name)) {
        $error = '❌ Name is required.';
    } elseif (empty($phone)) {
        $error = '❌ Phone number is required.';
    } elseif ($latitude <= 0 || $longitude <= 0) {
        $error = '❌ Please set your location using GPS or map.';
    } elseif (($userType === 'donor' || $userType === 'rider') && $latitude && $longitude) {
        // Inline Quetta bounds validation
        if ($latitude < 30.13 || $latitude > 30.25 || $longitude < 66.92 || $longitude > 67.05) {
            $error = '⚠️ Location must be within Quetta city bounds (30.13-30.25 lat, 66.92-67.05 lng)';
        }
    }
    
    if (empty($error)) {
        $stmt = $db->prepare('UPDATE users SET name = ?, phone = ?, latitude = ?, longitude = ? WHERE id = ?');
        $stmt->bind_param('ssddi', $name, $phone, $latitude, $longitude, $userId);
        
        if ($stmt->execute()) {
            $message = '✅ Profile updated successfully!';
            
            // Refresh user data
            $stmt = $db->prepare('SELECT id, name, phone, email, latitude, longitude, user_type FROM users WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $userData = $stmt->get_result()->fetch_assoc();
            
            // Update session
            $_SESSION['user_name'] = $userData['name'];
        } else {
            $error = '❌ Failed to update profile. Error: ' . $db->error;
        }
    }
}

$db->close();

$includeMap = true;
require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .leaflet-div-icon {
        background: transparent !important;
        border: none !important;
    }
    .profile-container { max-width: 800px; margin: 0 auto; }
    .profile-card { background: white; border-radius: 20px; box-shadow: 0 4px 30px rgba(0,0,0,0.08); overflow: hidden; }
    .profile-header { background: linear-gradient(135deg, #1a365d, #2b6cb0); padding: 30px; color: white; }
    .profile-header .avatar {
        width: 80px; height: 80px; border-radius: 50%;
        background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center;
        font-size: 2.5rem; border: 3px solid rgba(255,255,255,0.3);
    }
    .profile-body { padding: 30px; }
    .form-control-profile {
        border: 2px solid #e8e8f0; border-radius: 12px; padding: 12px 16px;
        transition: all 0.3s ease; width: 100%;
    }
    .form-control-profile:focus { border-color: #2b6cb0; box-shadow: 0 0 0 4px rgba(43,108,176,0.08); outline: none; }
    .form-label-profile { font-weight: 600; color: #2d3748; font-size: 0.9rem; margin-bottom: 6px; }
    .btn-update-location {
        background: #2b6cb0; color: white; border: none; padding: 10px 24px;
        border-radius: 12px; font-weight: 600; transition: all 0.3s ease;
        display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
        z-index: 1000;
        position: relative;
    }
    .btn-update-location:hover { background: #1a365d; transform: translateY(-2px); box-shadow: 0 4px 20px rgba(43,108,176,0.3); color: white; }
    .btn-update-location:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    .btn-save-profile {
        background: linear-gradient(135deg, #2b6cb0, #1a365d); color: white; border: none;
        padding: 14px 40px; border-radius: 12px; font-weight: 600; transition: all 0.3s ease; width: 100%;
    }
    .btn-save-profile:hover { transform: translateY(-2px); box-shadow: 0 4px 25px rgba(43,108,176,0.3); color: white; }
    #locationMap { height: 300px; border-radius: 12px; overflow: hidden; background: #e8ecf1; border: 2px solid #e8e8f0; }
    .location-status { padding: 12px 16px; border-radius: 10px; font-weight: 500; margin-top: 10px; }
    .location-status.success { background: #d1fae5; color: #065f46; }
    .location-status.error { background: #fee2e2; color: #991b1b; }
    .location-status.info { background: #e0f2fe; color: #0369a1; }
    .location-status.loading { background: #fef3c7; color: #b7791f; }
    .badge-role { padding: 6px 16px; border-radius: 30px; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; }
    .badge-role.donor { background: #d1fae5; color: #065f46; }
    .badge-role.recipient { background: #fef3c7; color: #b7791f; }
    .badge-role.rider { background: #e0f2fe; color: #0369a1; }
    .gps-spinner {
        display: inline-block; width: 16px; height: 16px;
        border: 2px solid rgba(255,255,255,0.3); border-top-color: white;
        border-radius: 50%; animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (max-width: 768px) {
        .profile-header { padding: 20px; }
        .profile-body { padding: 20px; }
        #locationMap { height: 200px; }
    }
</style>

<div class="container py-4 profile-container">
    <div class="profile-card">
        <div class="profile-header">
            <div class="d-flex align-items-center gap-4">
                <div class="avatar"><i class="fas fa-user"></i></div>
                <div>
                    <h2 class="fw-bold mb-1"><?= htmlspecialchars($userData['name']) ?></h2>
                    <p class="mb-0 opacity-75"><i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($userData['email'] ?? 'No email') ?></p>
                    <p class="mb-0 opacity-75"><i class="fas fa-phone me-1"></i> <?= htmlspecialchars($userData['phone']) ?></p>
                    <div class="mt-2"><span class="badge-role <?= $userType ?>"><?= ucfirst($userType) ?></span></div>
                </div>
            </div>
        </div>

        <div class="profile-body">
            <?php if ($message): ?>
                <div class="alert alert-success border-0 rounded-4"><i class="fas fa-check-circle me-2"></i> <?= $message ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger border-0 rounded-4"><i class="fas fa-exclamation-circle me-2"></i> <?= $error ?></div>
            <?php endif; ?>

            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0">
                        <i class="fas fa-map-pin text-primary me-2"></i> Your Location
                    </h5>
                    <button type="button" class="btn-update-location" id="gpsButton">
                        <i class="fas fa-satellite-dish"></i> <span id="gpsButtonText">Update GPS</span>
                    </button>
                </div>

                <div id="locationMap"></div>

                <div id="locationStatus" class="location-status info">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php if ($userData['latitude'] && $userData['longitude']): ?>
                        📍 Location set: <?= number_format($userData['latitude'], 4) ?>, <?= number_format($userData['longitude'], 4) ?>
                    <?php else: ?>
                        ⚠️ No location set. Click "Update GPS" or click on the map.
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" id="profileForm">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label-profile">Full Name</label>
                        <input type="text" name="name" class="form-control-profile" value="<?= htmlspecialchars($userData['name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-profile">Phone Number</label>
                        <input type="tel" name="phone" class="form-control-profile" value="<?= htmlspecialchars($userData['phone']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-profile">Latitude</label>
                        <input type="number" step="any" name="latitude" id="latitude" class="form-control-profile" value="<?= htmlspecialchars($userData['latitude'] ?? '') ?>" placeholder="e.g., 30.1798" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-profile">Longitude</label>
                        <input type="number" step="any" name="longitude" id="longitude" class="form-control-profile" value="<?= htmlspecialchars($userData['longitude'] ?? '') ?>" placeholder="e.g., 66.9750" required>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn-save-profile"><i class="fas fa-save me-2"></i> Save Profile</button>
                </div>
            </form>

            <div class="mt-4">
                <label class="form-label-profile">Quick Location Presets</label>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm preset-btn" data-lat="30.1798" data-lng="66.9750" data-name="Quetta Center">📍 Center</button>
                    <button type="button" class="btn btn-outline-primary btn-sm preset-btn" data-lat="30.1850" data-lng="66.9800" data-name="Jinnah Road">🏛️ Jinnah Road</button>
                    <button type="button" class="btn btn-outline-primary btn-sm preset-btn" data-lat="30.1750" data-lng="66.9700" data-name="Brewery Road">🏭 Brewery Road</button>
                    <button type="button" class="btn btn-outline-primary btn-sm preset-btn" data-lat="30.1900" data-lng="66.9900" data-name="Satellite Town">🛰️ Satellite Town</button>
                    <button type="button" class="btn btn-outline-primary btn-sm preset-btn" data-lat="30.1700" data-lng="66.9650" data-name="Sariab Road">🛣️ Sariab Road</button>
                    <button type="button" class="btn btn-outline-primary btn-sm preset-btn" data-lat="30.1650" data-lng="66.9600" data-name="Airport Road">✈️ Airport Road</button>
                    <button type="button" class="btn btn-outline-primary btn-sm preset-btn" data-lat="30.1820" data-lng="66.9780" data-name="Mission Road">🏥 Mission Road</button>
                </div>
            </div>

            <div class="mt-4 text-center">
                <a href="<?= BASE_URL . $userType . '/dashboard.php' ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Back to Dashboard</a>
            </div>
        </div>
    </div>
</div>

<script>
let map;
let marker;

function initMap(lat, lng) {
    if (map) {
        map.remove();
    }
    
    map = L.map('locationMap').setView([lat, lng], 15);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
    
    const userType = '<?= $userType ?>';
    let icon;
    
    if (userType === 'rider') {
        icon = L.divIcon({
            className: '',
            html: '<div style="font-size:32px; line-height:1;">🏍️</div>',
            iconSize: [32, 32],
            iconAnchor: [16, 16]
        });
    } else if (userType === 'donor') {
        icon = L.divIcon({
            className: '',
            html: '<div style="background:#48bb78; width:18px; height:18px; border-radius:50%; border:3px solid white;"></div>',
            iconSize: [18, 18],
            iconAnchor: [9, 9]
        });
    } else {
        icon = L.divIcon({
            className: '',
            html: '<div style="background:#ed8936; width:18px; height:18px; border-radius:50%; border:3px solid white;"></div>',
            iconSize: [18, 18],
            iconAnchor: [9, 9]
        });
    }
    
    marker = L.marker([lat, lng], { icon: icon, draggable: true }).addTo(map);
    
    marker.on('dragend', function() {
        const pos = marker.getLatLng();
        document.getElementById('latitude').value = pos.lat.toFixed(6);
        document.getElementById('longitude').value = pos.lng.toFixed(6);
        updateStatus(pos.lat, pos.lng);
    });
    
    map.on('click', function(e) {
        marker.setLatLng(e.latlng);
        document.getElementById('latitude').value = e.latlng.lat.toFixed(6);
        document.getElementById('longitude').value = e.latlng.lng.toFixed(6);
        updateStatus(e.latlng.lat, e.latlng.lng);
    });
}

function updateStatus(lat, lng) {
    const statusDiv = document.getElementById('locationStatus');
    if (lat >= 30.13 && lat <= 30.25 && lng >= 66.92 && lng <= 67.05) {
        statusDiv.className = 'location-status success';
        statusDiv.innerHTML = '✅ Location: ' + lat.toFixed(6) + ', ' + lng.toFixed(6) + ' <span class="badge bg-success">Quetta</span>';
    } else {
        statusDiv.className = 'location-status error';
        statusDiv.innerHTML = '⚠️ Outside Quetta: ' + lat.toFixed(6) + ', ' + lng.toFixed(6);
    }
}

// GPS Button Click Handler
document.getElementById('gpsButton').addEventListener('click', function() {
    const gpsButton = this;
    const gpsButtonText = document.getElementById('gpsButtonText');
    const statusDiv = document.getElementById('locationStatus');
    
    // Show loading state
    gpsButton.disabled = true;
    gpsButtonText.innerHTML = '<span class="gps-spinner"></span> Getting...';
    statusDiv.className = 'location-status loading';
    statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Getting your location...';
    
    if (!navigator.geolocation) {
        statusDiv.className = 'location-status error';
        statusDiv.innerHTML = '❌ Geolocation is not supported by your browser';
        gpsButton.disabled = false;
        gpsButtonText.innerHTML = '<i class="fas fa-satellite-dish"></i> Update GPS';
        return;
    }
    
    navigator.geolocation.getCurrentPosition(
        function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            
            // Update inputs
            document.getElementById('latitude').value = lat.toFixed(6);
            document.getElementById('longitude').value = lng.toFixed(6);
            
            // Update map
            if (map && marker) {
                marker.setLatLng([lat, lng]);
                map.setView([lat, lng], 15);
            }
            
            // Update status
            updateStatus(lat, lng);
            
            // Show success message
            statusDiv.className = 'location-status success';
            statusDiv.innerHTML = '✅ GPS location updated! Click "Save Profile" to save your location.';
            
            // Reset button
            gpsButton.disabled = false;
            gpsButtonText.innerHTML = '<i class="fas fa-satellite-dish"></i> Update GPS';
        },
        function(error) {
            gpsButton.disabled = false;
            gpsButtonText.innerHTML = '<i class="fas fa-satellite-dish"></i> Update GPS';
            statusDiv.className = 'location-status error';
            
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    statusDiv.innerHTML = '❌ Permission denied. Please allow location access in your browser settings.';
                    break;
                case error.POSITION_UNAVAILABLE:
                    statusDiv.innerHTML = '❌ Location information is unavailable.';
                    break;
                case error.TIMEOUT:
                    statusDiv.innerHTML = '❌ Location request timed out.';
                    break;
                default:
                    statusDiv.innerHTML = '❌ Error: ' + error.message;
            }
        },
        {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
        }
    );
});

// Preset buttons
document.querySelectorAll('.preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const lat = parseFloat(this.dataset.lat);
        const lng = parseFloat(this.dataset.lng);
        const name = this.dataset.name;
        
        document.getElementById('latitude').value = lat;
        document.getElementById('longitude').value = lng;
        
        if (map && marker) {
            marker.setLatLng([lat, lng]);
            map.setView([lat, lng], 15);
        }
        
        updateStatus(lat, lng);
    });
});

// Initialize map on page load
document.addEventListener('DOMContentLoaded', function() {
    const initialLat = parseFloat(document.getElementById('latitude').value) || 30.1798;
    const initialLng = parseFloat(document.getElementById('longitude').value) || 66.9750;
    initMap(initialLat, initialLng);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>