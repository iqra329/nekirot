<?php
include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// ============================================
// ADDED: sanitize_text function
// ============================================
function sanitize_text($value) {
    return trim(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

require_login();
$user = current_user();
if ($user['type'] !== 'donor') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$db = get_db_connection();
$phone = '';
$stmt = $db->prepare('SELECT phone, latitude, longitude FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$profile = $result->fetch_assoc();
if ($profile) {
    $phone = $profile['phone'];
    $userLat = $profile['latitude'] ?? 30.1850;
    $userLng = $profile['longitude'] ?? 66.9800;
} else {
    $userLat = 30.1850;
    $userLng = 66.9800;
}

// ============================================
// CHECK IF COLUMNS EXIST
// ============================================
$hasQuantity = false;
$columnCheck = $db->query("SHOW COLUMNS FROM listings LIKE 'quantity'");
if ($columnCheck && $columnCheck->num_rows > 0) {
    $hasQuantity = true;
}

$hasDeadlineColumn = false;
$columnCheck = $db->query("SHOW COLUMNS FROM listings LIKE 'pickup_deadline'");
if ($columnCheck && $columnCheck->num_rows > 0) {
    $hasDeadlineColumn = true;
}

$hasVegetarianColumn = false;
$columnCheck = $db->query("SHOW COLUMNS FROM listings LIKE 'is_vegetarian'");
if ($columnCheck && $columnCheck->num_rows > 0) {
    $hasVegetarianColumn = true;
}

$hasEmergencyColumn = false;
$columnCheck = $db->query("SHOW COLUMNS FROM listings LIKE 'is_emergency'");
if ($columnCheck && $columnCheck->num_rows > 0) {
    $hasEmergencyColumn = true;
}

// Check if photo_url column exists
$hasPhotoColumn = false;
$columnCheck = $db->query("SHOW COLUMNS FROM listings LIKE 'photo_url'");
if ($columnCheck && $columnCheck->num_rows > 0) {
    $hasPhotoColumn = true;
}

// If photo column doesn't exist, add it
if (!$hasPhotoColumn) {
    $db->query("ALTER TABLE listings ADD COLUMN photo_url VARCHAR(500) DEFAULT NULL");
    $hasPhotoColumn = true;
}

$errors = [];
$success = false;
$foodType = 'Chicken Biryani';
$quantity = 50;
$deadline = date('Y-m-d\TH:i', strtotime('+2 hours'));
$description = 'Freshly cooked halal chicken biryani ready for pickup.';
$latitude = $userLat;
$longitude = $userLng;
$vegetarian = false;
$emergency = false;
$photo_url = '';
$locationStatus = '📍 Location ready';
$addressLabel = 'Quetta, Pakistan';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $foodType = sanitize_text($_POST['food_type'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    $deadline = sanitize_text($_POST['deadline'] ?? '');
    $description = sanitize_text($_POST['description'] ?? '');
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    $vegetarian = isset($_POST['vegetarian']);
    $emergency = isset($_POST['emergency']);

    // ============================================
    // IMAGE UPLOAD HANDLING
    // ============================================
    if (isset($_FILES['food_image']) && $_FILES['food_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['food_image'];
        $fileName = $file['name'];
        $fileTmpName = $file['tmp_name'];
        $fileSize = $file['size'];
        
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (!in_array($fileExt, $allowedExtensions)) {
            $errors['image'] = 'Invalid file type. Allowed: JPG, PNG, GIF, WEBP';
        } elseif ($fileSize > 5 * 1024 * 1024) {
            $errors['image'] = 'File too large. Maximum size is 5MB.';
        } else {
            $newFileName = 'food_' . $user['id'] . '_' . time() . '_' . uniqid() . '.' . $fileExt;
            $uploadDir = __DIR__ . '/../uploads/food/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $uploadPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($fileTmpName, $uploadPath)) {
                $photo_url = 'uploads/food/' . $newFileName;
            } else {
                $errors['image'] = 'Failed to upload image. Please try again.';
            }
        }
    }

    // Validation
    if ($foodType === '') {
        $errors['food_type'] = 'Food type is required.';
    }
    if ($quantity < 1 || $quantity > 1000) {
        $errors['quantity'] = 'Quantity must be between 1 and 1000.';
    }
    if ($deadline === '') {
        $errors['deadline'] = 'Pickup deadline is required.';
    } elseif (strtotime($deadline) < time() + 3600) {
        $minDeadline = date('h:i A', strtotime('+1 hour'));
        $errors['deadline'] = 'Deadline must be at least 1 hour from now.';
    }
    if ($description === '' || strlen($description) < 10) {
        $errors['description'] = 'Description must be at least 10 characters.';
    } elseif (strlen($description) > 500) {
        $errors['description'] = 'Description cannot exceed 500 characters.';
    }
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        $errors['location'] = 'Latitude and longitude must be valid numbers.';
    } else {
        $lat = floatval($latitude);
        $lng = floatval($longitude);
        if (!validate_quetta_bounds($lat, $lng)) {
            $errors['location'] = 'Location must fall inside Quetta bounds.';
        }
    }

    if (empty($errors)) {
        $title = sprintf('%s — %d plates', $foodType, $quantity);
        $status = 'published';
        
        $fullDescription = sprintf(
            "Pickup by %s\n\n%s\n\nVegetarian: %s\nEmergency: %s\nLocation: %s, %s",
            date('d M Y H:i', strtotime($deadline)), 
            $description,
            $vegetarian ? 'Yes' : 'No',
            $emergency ? 'Yes' : 'No',
            $latitude,
            $longitude
        );

        $columns = ['donor_id', 'title', 'description', 'contact_phone', 'latitude', 'longitude', 'status', 'created_at', 'updated_at'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?', 'NOW()', 'NOW()'];
        $params = [$user['id'], $title, $fullDescription, $phone, $latitude, $longitude, $status];
        $types = 'isssdds';
        
        if ($hasQuantity) {
            $columns[] = 'quantity';
            $placeholders[] = '?';
            $params[] = $quantity;
            $types .= 'i';
        }
        
        if ($hasDeadlineColumn) {
            $columns[] = 'pickup_deadline';
            $placeholders[] = '?';
            $params[] = $deadline;
            $types .= 's';
        }
        
        if ($hasVegetarianColumn) {
            $columns[] = 'is_vegetarian';
            $placeholders[] = '?';
            $params[] = $vegetarian ? 1 : 0;
            $types .= 'i';
        }
        
        if ($hasEmergencyColumn) {
            $columns[] = 'is_emergency';
            $placeholders[] = '?';
            $params[] = $emergency ? 1 : 0;
            $types .= 'i';
        }
        
        // ADD PHOTO COLUMN
        if ($hasPhotoColumn && $photo_url !== '') {
            $columns[] = 'photo_url';
            $placeholders[] = '?';
            $params[] = $photo_url;
            $types .= 's';
        }
        
        $sql = 'INSERT INTO listings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        
        $insert = $db->prepare($sql);
        $insert->bind_param($types, ...$params);
        
        if ($insert->execute()) {
            $success = true;
            $locationStatus = '✅ Broadcast created!';
        } else {
            $errors['submit'] = 'Unable to publish. Error: ' . $db->error;
        }
    }
}

$includeMap = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    #broadcastMap {
        height: 350px;
        border-radius: 12px;
        overflow: hidden;
        background: #e8ecf1;
        border: 1px solid rgba(26,58,107,0.06);
    }
    .card-broadcast {
        background: white;
        border-radius: 16px;
        border: 1px solid rgba(26,58,107,0.06);
        box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        transition: all 0.3s ease;
        overflow: hidden;
    }
    .card-broadcast:hover {
        box-shadow: 0 8px 40px rgba(0,0,0,0.1);
    }
    .card-broadcast-header {
        padding: 20px 24px;
        background: linear-gradient(135deg, #1a365d, #2b6cb0);
        color: white;
    }
    .card-broadcast-header h4 {
        margin: 0;
        font-weight: 700;
    }
    .card-broadcast-body {
        padding: 24px;
    }
    .form-control-broadcast {
        border: 2px solid #e8e8f0;
        border-radius: 12px;
        padding: 12px 16px;
        transition: all 0.3s ease;
        width: 100%;
        font-size: 1rem;
    }
    .form-control-broadcast:focus {
        border-color: #2b6cb0;
        box-shadow: 0 0 0 4px rgba(43,108,176,0.08);
        outline: none;
    }
    .form-control-broadcast.error {
        border-color: #dc2626;
        box-shadow: 0 0 0 4px rgba(220,38,38,0.08);
    }
    .form-label-broadcast {
        font-weight: 600;
        color: #2d3748;
        font-size: 0.9rem;
        margin-bottom: 6px;
    }
    .btn-broadcast {
        background: linear-gradient(135deg, #2b6cb0, #1a365d);
        color: white;
        border: none;
        padding: 14px 40px;
        border-radius: 12px;
        font-weight: 600;
        transition: all 0.3s ease;
        width: 100%;
        font-size: 1.1rem;
    }
    .btn-broadcast:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 25px rgba(43,108,176,0.3);
        color: white;
    }
    .btn-broadcast:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
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
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 28px;
    }
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: #cbd5e1;
        transition: .4s;
        border-radius: 28px;
    }
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 4px;
        bottom: 4px;
        background: white;
        transition: .4s;
        border-radius: 50%;
    }
    .toggle-switch input:checked + .toggle-slider {
        background: #2b6cb0;
    }
    .toggle-switch input:checked + .toggle-slider:before {
        transform: translateX(22px);
    }
    .custom-marker-broadcast {
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
    .char-count {
        font-size: 0.85rem;
        color: #718096;
    }
    .char-count .count {
        font-weight: 600;
    }
    .char-count .count.warning {
        color: #d69e2e;
    }
    .char-count .count.danger {
        color: #dc2626;
    }
    .deadline-hint {
        font-size: 0.8rem;
        color: #718096;
        margin-top: 5px;
    }
    .deadline-hint strong {
        color: #2b6cb0;
    }
    @media (max-width: 768px) {
        .card-broadcast-body {
            padding: 16px;
        }
        #broadcastMap {
            height: 220px;
        }
    }
</style>

<div class="container py-4">
    <div class="row justify-content-center">
        <!-- Header -->
        <div class="col-12 text-center mb-4">
            <h1 class="fw-bold text-primary-dark">
                <i class="fas fa-broadcast text-primary me-2"></i> Broadcast Food
            </h1>
            <p class="text-muted">Share surplus food with Quetta's community</p>
        </div>

        <!-- Success Message -->
        <?php if ($success): ?>
            <div class="col-12 mb-4">
                <div class="alert alert-success border-0 rounded-4 shadow-sm">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle fa-2x text-success me-3"></i>
                        <div>
                            <strong>✅ Broadcast Published!</strong>
                            <?php if ($photo_url): ?>
                                <div>📷 Photo uploaded successfully!</div>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>donor/dashboard.php" class="btn btn-sm btn-success mt-2">
                                <i class="fas fa-arrow-right me-1"></i> Go to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Errors -->
        <?php if (!empty($errors)): ?>
            <div class="col-12 mb-4">
                <div class="alert alert-danger border-0 rounded-4 shadow-sm">
                    <strong>⚠️ Please fix these errors:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $key => $message): ?>
                            <li><?= htmlspecialchars($message) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <!-- Main Form -->
        <div class="col-12">
            <div class="card-broadcast">
                <div class="card-broadcast-header">
                    <h4><i class="fas fa-utensils me-2"></i> Food Broadcast Form</h4>
                </div>
                <div class="card-broadcast-body">
                    <!-- ✅ ADDED enctype="multipart/form-data" -->
                    <form id="broadcastForm" method="post" enctype="multipart/form-data" novalidate>
                        <div class="row g-4">
                            <!-- Left Column -->
                            <div class="col-lg-7">
                                <!-- Location Section -->
                                <div class="mb-4">
                                    <h5 class="fw-bold text-primary-dark">
                                        <i class="fas fa-map-pin text-primary me-2"></i> Location
                                    </h5>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <button type="button" id="geoButton" class="btn-gps w-100">
                                                <i class="fas fa-satellite-dish me-2"></i> Get GPS Location
                                            </button>
                                        </div>
                                    </div>
                                    <div id="locationStatus" class="location-status info mt-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <?= $locationStatus ?>
                                    </div>
                                </div>

                                <!-- Coordinates -->
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label-broadcast">Latitude</label>
                                        <input type="number" step="any" name="latitude" id="latitude" 
                                               class="form-control-broadcast" 
                                               value="<?= $latitude ?>" 
                                               placeholder="e.g., 30.1798" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-broadcast">Longitude</label>
                                        <input type="number" step="any" name="longitude" id="longitude" 
                                               class="form-control-broadcast" 
                                               value="<?= $longitude ?>" 
                                               placeholder="e.g., 66.9750" required>
                                    </div>
                                </div>

                                <!-- Quick Presets -->
                                <div class="mb-3">
                                    <label class="form-label-broadcast">Quick Location Presets</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="button" class="preset-btn" data-lat="30.1850" data-lng="66.9800">Jinnah Road</button>
                                        <button type="button" class="preset-btn" data-lat="30.1790" data-lng="66.9760">Shahrah-e-Zarghun</button>
                                        <button type="button" class="preset-btn" data-lat="30.1750" data-lng="66.9700">Brewery Road</button>
                                        <button type="button" class="preset-btn" data-lat="30.1900" data-lng="66.9900">Satellite Town</button>
                                        <button type="button" class="preset-btn" data-lat="30.1700" data-lng="66.9650">Sariab Road</button>
                                        <button type="button" class="preset-btn" data-lat="30.1650" data-lng="66.9600">Airport Road</button>
                                        <button type="button" class="preset-btn" data-lat="30.1880" data-lng="66.9820">Alamdar Road</button>
                                        <button type="button" class="preset-btn" data-lat="30.1950" data-lng="66.9950">Pashtoonabad</button>
                                    </div>
                                    <div class="form-text text-muted">Quetta bounds: Lat 30.13–30.25, Lng 66.92–67.05</div>
                                </div>

                                <!-- Food Details -->
                                <div class="mb-3">
                                    <label class="form-label-broadcast">Food Type</label>
                                    <input type="text" name="food_type" id="food_type" 
                                           class="form-control-broadcast" 
                                           value="<?= htmlspecialchars($foodType) ?>" 
                                           placeholder="e.g., Chicken Biryani, Beef Pulao" required>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label-broadcast">Quantity (plates)</label>
                                        <div class="input-group">
                                            <button type="button" id="decreaseQty" class="btn btn-outline-secondary">−</button>
                                            <input type="number" name="quantity" id="quantity" 
                                                   class="form-control-broadcast text-center" 
                                                   value="<?= $quantity ?>" min="1" max="1000" required>
                                            <button type="button" id="increaseQty" class="btn btn-outline-secondary">+</button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-broadcast">Pickup Deadline</label>
                                        <input type="datetime-local" name="deadline" id="deadline" 
                                               class="form-control-broadcast" 
                                               value="<?= $deadline ?>" 
                                               min="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>" required>
                                        <div class="deadline-hint">
                                            <i class="fas fa-info-circle"></i> 
                                            Must be at least <strong>1 hour from now</strong>. 
                                            Current server time: <strong><?= date('h:i A') ?></strong>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label-broadcast">Description</label>
                                    <textarea name="description" id="description" rows="4" 
                                              class="form-control-broadcast" 
                                              maxlength="500" required><?= htmlspecialchars($description) ?></textarea>
                                    <div class="char-count mt-1">
                                        <span class="count" id="charCount"><?= strlen($description) ?></span> / 500 characters
                                    </div>
                                </div>

                                <!-- ✅ ADDED: IMAGE UPLOAD -->
                                <div class="mb-3">
                                    <label class="form-label-broadcast">📷 Food Photo (Optional)</label>
                                    <input type="file" name="food_image" id="food_image" 
                                           class="form-control-broadcast" 
                                           accept=".jpg,.jpeg,.png,.gif,.webp">
                                    <div class="form-text text-muted">
                                        Allowed: JPG, PNG, GIF, WEBP — Max 5MB
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center gap-3">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="vegetarian" id="vegetarian" <?= $vegetarian ? 'checked' : '' ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span class="fw-semibold">🌱 Vegetarian</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center gap-3">
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="emergency" id="emergency" <?= $emergency ? 'checked' : '' ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <span class="fw-semibold text-danger">🚨 Emergency</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Column -->
                            <div class="col-lg-5">
                                <!-- Map -->
                                <div class="mb-3">
                                    <label class="form-label-broadcast">📍 Map Preview</label>
                                    <div id="broadcastMap"></div>
                                </div>

                                <!-- Preview Card -->
                                <div class="card-broadcast">
                                    <div class="card-broadcast-header" style="background: linear-gradient(135deg, #2d3748, #1a365d);">
                                        <h4 class="h6 mb-0"><i class="fas fa-eye me-2"></i> Listing Preview</h4>
                                    </div>
                                    <div class="card-broadcast-body">
                                        <h5 class="fw-bold text-primary-dark" id="previewTitle">
                                            <?= htmlspecialchars($foodType) ?> — <?= $quantity ?> plates
                                        </h5>
                                        <p class="text-muted small mb-1" id="previewLocation">
                                            <i class="fas fa-map-marker-alt text-primary me-1"></i>
                                            <?php 
                                            $centerLat = 30.1820;
                                            $centerLng = 66.9780;
                                            $dist = calculate_distance_km($centerLat, $centerLng, floatval($latitude), floatval($longitude));
                                            echo number_format($dist, 1); 
                                            ?> km from Quetta center
                                        </p>
                                        <p class="text-muted small mb-2" id="previewDeadline">
                                            <i class="fas fa-clock text-primary me-1"></i>
                                            Pickup by <?= date('M j, Y h:i A', strtotime($deadline)) ?>
                                        </p>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <div class="p-2 bg-light rounded-3 text-center">
                                                    <span class="text-muted small">Vegetarian</span>
                                                    <div class="fw-semibold" id="previewVegetarian"><?= $vegetarian ? 'Yes' : 'No' ?></div>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="p-2 bg-light rounded-3 text-center">
                                                    <span class="text-muted small">Emergency</span>
                                                    <div class="fw-semibold" id="previewEmergency"><?= $emergency ? 'Yes' : 'No' ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit -->
                                <button type="submit" id="submitButton" class="btn-broadcast mt-3">
                                    <i class="fas fa-rocket me-2"></i> Broadcast to Quetta
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Leaflet Map Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================
    // MAP INITIALIZATION
    // ============================================
    const lat = parseFloat(document.getElementById('latitude').value) || 30.1850;
    const lng = parseFloat(document.getElementById('longitude').value) || 66.9800;
    
    const map = L.map('broadcastMap').setView([lat, lng], 14);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
    
    const markerIcon = L.divIcon({
        className: 'custom-marker-broadcast',
        iconSize: [20, 20],
        iconAnchor: [10, 10]
    });
    
    let marker = L.marker([lat, lng], { icon: markerIcon, draggable: true })
        .addTo(map)
        .bindPopup('📍 Your food location')
        .openPopup();
    
    marker.on('dragend', function() {
        const pos = marker.getLatLng();
        updateLocationUI(pos.lat, pos.lng);
    });
    
    map.on('click', function(e) {
        const pos = e.latlng;
        marker.setLatLng([pos.lat, pos.lng]);
        updateLocationUI(pos.lat, pos.lng);
    });
    
    L.control.scale({
        position: 'bottomright',
        metric: true,
        imperial: false
    }).addTo(map);
    
    function updateLocationUI(lat, lng) {
        document.getElementById('latitude').value = lat.toFixed(6);
        document.getElementById('longitude').value = lng.toFixed(6);
        marker.setLatLng([lat, lng]);
        map.setView([lat, lng], 14);
        updatePreview();
        
        if (lat >= 30.13 && lat <= 30.25 && lng >= 66.92 && lng <= 67.05) {
            document.getElementById('locationStatus').className = 'location-status success';
            document.getElementById('locationStatus').innerHTML = '<i class="fas fa-check-circle me-2"></i> ✅ Location within Quetta';
        } else {
            document.getElementById('locationStatus').className = 'location-status error';
            document.getElementById('locationStatus').innerHTML = '<i class="fas fa-exclamation-circle me-2"></i> ⚠️ Location outside Quetta bounds';
        }
    }
    
    function updatePreview() {
        const foodType = document.getElementById('food_type').value || 'Food';
        const quantity = document.getElementById('quantity').value || 0;
        const deadline = document.getElementById('deadline').value;
        const vegetarian = document.getElementById('vegetarian').checked;
        const emergency = document.getElementById('emergency').checked;
        const lat = parseFloat(document.getElementById('latitude').value) || 0;
        const lng = parseFloat(document.getElementById('longitude').value) || 0;
        
        document.getElementById('previewTitle').textContent = `${foodType} — ${quantity} plates`;
        document.getElementById('previewVegetarian').textContent = vegetarian ? 'Yes' : 'No';
        document.getElementById('previewEmergency').textContent = emergency ? 'Yes' : 'No';
        
        if (deadline) {
            const date = new Date(deadline);
            document.getElementById('previewDeadline').innerHTML = `<i class="fas fa-clock text-primary me-1"></i> Pickup by ${date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })}`;
        }
        
        const centerLat = 30.1820;
        const centerLng = 66.9780;
        const distance = calculateDistance(centerLat, centerLng, lat, lng);
        document.getElementById('previewLocation').innerHTML = `<i class="fas fa-map-marker-alt text-primary me-1"></i> ${distance.toFixed(1)} km from Quetta center`;
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
    
    document.getElementById('geoButton').addEventListener('click', function() {
        const status = document.getElementById('locationStatus');
        status.className = 'location-status info';
        status.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Getting your location...';
        
        if (!navigator.geolocation) {
            status.className = 'location-status error';
            status.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i> GPS not supported';
            return;
        }
        
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                updateLocationUI(lat, lng);
            },
            function(error) {
                status.className = 'location-status error';
                status.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i> Error: ' + error.message;
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });
    
    document.querySelectorAll('.preset-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const lat = parseFloat(this.dataset.lat);
            const lng = parseFloat(this.dataset.lng);
            updateLocationUI(lat, lng);
            
            document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });
    
    document.getElementById('decreaseQty').addEventListener('click', function() {
        const input = document.getElementById('quantity');
        let val = parseInt(input.value) || 1;
        if (val > 1) input.value = val - 1;
        updatePreview();
    });
    
    document.getElementById('increaseQty').addEventListener('click', function() {
        const input = document.getElementById('quantity');
        let val = parseInt(input.value) || 1;
        if (val < 1000) input.value = val + 1;
        updatePreview();
    });
    
    document.getElementById('food_type').addEventListener('input', updatePreview);
    document.getElementById('quantity').addEventListener('input', updatePreview);
    document.getElementById('deadline').addEventListener('input', updatePreview);
    document.getElementById('vegetarian').addEventListener('change', updatePreview);
    document.getElementById('emergency').addEventListener('change', updatePreview);
    
    document.getElementById('description').addEventListener('input', function() {
        const count = this.value.length;
        const el = document.getElementById('charCount');
        el.textContent = count;
        el.className = 'count';
        if (count > 400) el.classList.add('warning');
        if (count > 480) el.classList.add('danger');
    });
    
    document.getElementById('broadcastForm').addEventListener('submit', function() {
        document.getElementById('submitButton').disabled = true;
        document.getElementById('submitButton').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Publishing...';
    });
    
    updateLocationUI(lat, lng);
    updatePreview();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>