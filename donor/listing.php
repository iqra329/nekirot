<?php
include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// ============================================
// AUTHENTICATION - MUST BE LOGGED IN
// ============================================
require_login();

$user = current_user();

if (!$user) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Check if user is a donor
if ($user['type'] !== 'donor') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

// ============================================
// GET LISTING ID
// ============================================
$listingId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$listingId) {
    header('Location: ' . BASE_URL . 'donor/dashboard.php');
    exit;
}

// ============================================
// FETCH LISTING DATA
// ============================================
$db = get_db_connection();

$stmt = $db->prepare('SELECT l.*, u.name as donor_name, u.phone as donor_phone, u.email as donor_email 
                      FROM listings l
                      JOIN users u ON u.id = l.donor_id
                      WHERE l.id = ? AND l.donor_id = ?');
$stmt->bind_param('ii', $listingId, $user['id']);
$stmt->execute();
$listing = $stmt->get_result()->fetch_assoc();

$db->close();

// ============================================
// CHECK IF LISTING EXISTS
// ============================================
if (!$listing) {
    header('Location: ' . BASE_URL . 'donor/dashboard.php');
    exit;
}

// ============================================
// GET PHOTO URL
// ============================================
$photo_url = $listing['photo_url'] ?? '';

// ============================================
// FORMAT FUNCTIONS
// ============================================
function formatDMS($coordinate, $isLatitude) {
    $degrees = floor($coordinate);
    $minutes = floor(($coordinate - $degrees) * 60);
    $seconds = round(($coordinate - $degrees - $minutes/60) * 3600, 2);
    $direction = '';
    
    if ($isLatitude) {
        $direction = $coordinate >= 0 ? 'N' : 'S';
    } else {
        $direction = $coordinate >= 0 ? 'E' : 'W';
    }
    
    return sprintf("%d° %02d' %05.2f\" %s", 
        abs($degrees), 
        abs($minutes), 
        abs($seconds), 
        $direction
    );
}

function calcDistance($lat1, $lng1, $lat2, $lng2) {
    $rad = pi() / 180;
    $dLat = ($lat2 - $lat1) * $rad;
    $dLng = ($lng2 - $lng1) * $rad;
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos($lat1 * $rad) * cos($lat2 * $rad)
        * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return 6371 * $c;
}

$centerLat = 30.1820;
$centerLng = 66.9780;
$distance = calcDistance($centerLat, $centerLng, floatval($listing['latitude']), floatval($listing['longitude']));

$badgeClass = match (strtolower($listing['status'])) {
    'published' => 'success',
    'draft' => 'secondary',
    'expired' => 'warning',
    default => 'info',
};

$includeMap = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    #mapPreview { 
        min-height: 380px; 
        border-radius: 8px; 
        background: #e8ecf1;
    }
    .card { 
        border-radius: 12px; 
        border: none;
        box-shadow: 0 2px 15px rgba(0,0,0,0.06);
        transition: all 0.3s ease;
    }
    .card:hover {
        box-shadow: 0 4px 30px rgba(0,0,0,0.1);
    }
    .badge-status {
        padding: 8px 20px;
        border-radius: 30px;
        font-weight: 600;
        font-size: 0.85rem;
    }
    .share-input-group {
        border-radius: 10px;
        overflow: hidden;
    }
    .share-input-group .form-control {
        border: 2px solid #e8e8f0;
        border-right: none;
        padding: 12px 16px;
        font-size: 0.95rem;
    }
    .share-input-group .btn {
        border: 2px solid #e8e8f0;
        border-left: none;
        padding: 12px 24px;
        font-weight: 600;
    }
    .share-input-group .btn:hover {
        background: #0d6efd;
        color: white;
        border-color: #0d6efd;
    }
    .custom-marker {
        background: #0d6efd;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 2px 10px rgba(13, 110, 253, 0.4);
        position: relative;
    }
    .custom-marker::after {
        content: '';
        position: absolute;
        top: -8px;
        left: -8px;
        right: -8px;
        bottom: -8px;
        border-radius: 50%;
        background: rgba(13, 110, 253, 0.15);
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.5); opacity: 0; }
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
    .leaflet-popup-content {
        font-family: 'Inter', sans-serif;
        min-width: 200px;
    }
    
    /* FOOD IMAGE STYLES */
    .food-image-container {
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 20px;
        border: 1px solid #e8e8f0;
    }
    .food-image-container img {
        width: 100%;
        height: auto;
        max-height: 250px;
        object-fit: cover;
        display: block;
    }
    .no-image-placeholder {
        background: #f8fafc;
        border: 2px dashed #e8e8f0;
        border-radius: 12px;
        padding: 30px;
        text-align: center;
        color: #94a3b8;
        margin-bottom: 20px;
    }
    .no-image-placeholder i {
        font-size: 3rem;
        margin-bottom: 10px;
        display: block;
    }
</style>

<div class="container py-4">
    <div class="row g-4">
        <!-- Header -->
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row align-items-start justify-content-between gap-3 mb-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-primary rounded-pill px-3 py-2">
                            <i class="fas fa-list-ul me-1"></i> Listing Overview
                        </span>
                        <span class="badge bg-<?= $badgeClass ?> badge-status">
                            <?= htmlspecialchars($listing['status']) ?>
                        </span>
                    </div>
                    <h1 class="mt-2 mb-1 display-6 fw-bold"><?= htmlspecialchars($listing['title']) ?></h1>
                    <p class="text-muted mb-0">
                        <i class="fas fa-calendar-alt me-1"></i> 
                        Created: <?= date('M j, Y', strtotime($listing['created_at'])) ?>
                        <span class="mx-2">•</span>
                        <i class="fas fa-clock me-1"></i>
                        <?= timeAgo($listing['created_at']) ?>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>donor/dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
        </div>

        <!-- Map -->
        <div class="col-lg-8">
            <div class="card">
                <div id="mapPreview"></div>
                <div class="card-body bg-white">
                    <div class="row gy-3">
                        <div class="col-sm-6">
                            <div class="p-3 rounded-3 bg-light">
                                <div class="text-uppercase text-muted small mb-2">Coordinates</div>
                                <div class="fw-semibold mb-1">
                                    <?= htmlspecialchars($listing['latitude']) ?>, <?= htmlspecialchars($listing['longitude']) ?>
                                </div>
                                <div class="small text-muted">
                                    <?= formatDMS(floatval($listing['latitude']), true) ?> / 
                                    <?= formatDMS(floatval($listing['longitude']), false) ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 rounded-3 bg-light">
                                <div class="text-uppercase text-muted small mb-2">Distance from Quetta Center</div>
                                <div class="fw-semibold mb-1">
                                    <?= number_format($distance, 1) ?> km
                                </div>
                                <div class="small text-muted">
                                    <i class="fas fa-map-pin text-primary"></i> 
                                    <?= $distance < 5 ? '📍 Within city center' : '📍 Outer Quetta area' ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-3 rounded-3 bg-light">
                                <div class="text-uppercase text-muted small mb-2">Contact Phone</div>
                                <div class="fw-semibold">
                                    <i class="fas fa-phone text-primary me-2"></i>
                                    <?= htmlspecialchars($listing['contact_phone'] ?? 'Not provided') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <!-- ============================================ -->
                    <!-- FOOD IMAGE DISPLAY -->
                    <!-- ============================================ -->
                    <?php if ($photo_url && file_exists(__DIR__ . '/../' . $photo_url)): ?>
                        <div class="food-image-container">
                            <img src="<?= BASE_URL . $photo_url ?>" alt="<?= htmlspecialchars($listing['title']) ?>">
                        </div>
                    <?php else: ?>
                        <div class="no-image-placeholder">
                            <i class="fas fa-utensils"></i>
                            <span>No food photo uploaded</span>
                        </div>
                    <?php endif; ?>

                    <h5 class="card-title fw-bold mb-3">
                        <i class="fas fa-align-left text-primary me-2"></i> Description
                    </h5>
                    <p class="text-muted mb-4" style="line-height: 1.8;">
                        <?= nl2br(htmlspecialchars($listing['description'] ?? 'No description provided')) ?>
                    </p>
                    <hr>
                    <ul class="list-unstyled mb-0">
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Listing ID</span>
                            <strong>#<?= intval($listing['id']) ?></strong>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Status</span>
                            <strong><?= htmlspecialchars($listing['status']) ?></strong>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Latitude</span>
                            <strong><?= htmlspecialchars($listing['latitude']) ?></strong>
                        </li>
                        <li class="d-flex justify-content-between py-2">
                            <span class="text-muted">Longitude</span>
                            <strong><?= htmlspecialchars($listing['longitude']) ?></strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Share Section -->
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <h5 class="fw-bold mb-1">
                                <i class="fas fa-share-alt text-primary me-2"></i> Share Listing
                            </h5>
                            <p class="text-muted mb-md-0">Share with riders and recipients</p>
                        </div>
                        <div class="col-md-8">
                            <div class="input-group share-input-group">
                                <input type="text" class="form-control" id="listingLink" 
                                       value="<?= htmlspecialchars(BASE_URL . 'donor/listing.php?id=' . $listing['id']) ?>" readonly>
                                <button class="btn btn-outline-primary" type="button" id="copyLinkBtn" onclick="copyLink()">
                                    <i class="fas fa-copy me-1"></i> Copy
                                </button>
                            </div>
                            <small class="text-muted mt-1 d-block">
                                <i class="fas fa-info-circle me-1"></i>
                                Link: <strong><?= BASE_URL ?>donor/listing.php?id=<?= $listing['id'] ?></strong>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Leaflet Map Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const lat = <?= floatval($listing['latitude']) ?>;
    const lng = <?= floatval($listing['longitude']) ?>;
    const title = '<?= addslashes($listing['title']) ?>';
    
    const map = L.map('mapPreview').setView([lat, lng], 15);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
    
    const markerIcon = L.divIcon({
        className: 'custom-marker',
        iconSize: [20, 20],
        iconAnchor: [10, 10]
    });
    
    L.marker([lat, lng], { icon: markerIcon })
        .addTo(map)
        .bindPopup(`<strong>🍽️ ${title}</strong>`)
        .openPopup();
    
    L.control.scale({
        position: 'bottomright',
        metric: true,
        imperial: false
    }).addTo(map);
});

function copyLink() {
    const link = document.getElementById('listingLink').value;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(link).then(() => {
            alert('✅ Link copied to clipboard!');
        });
    } else {
        const textarea = document.createElement('textarea');
        textarea.value = link;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('✅ Link copied to clipboard!');
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>