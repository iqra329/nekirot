<?php
// ============================================
// NEKIROT QUETTA - START TRANSIT PAGE
// Rider starts delivery from donor pickup point
// to recipient location with live GPS simulation
// Rider moves along route in exact ETA time
// ============================================

include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gps_simulation.php';

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

// ============================================
// FETCH RESCUE DETAILS
// ============================================
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

// Check rider assignment
$assignedRiderId = intval($rescue['assigned_rider_id'] ?? 0);
if ($assignedRiderId > 0 && $assignedRiderId !== intval($user['id'])) {
    $db->close();
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

$status = strtolower(trim($rescue['status'] ?? 'pending'));
$canStartTransit = ($status === 'picked_up');
$error = '';
$success = '';

// ============================================
// GET PICKUP LOCATION (DONOR'S LOCATION)
// ============================================
$pickupLat = floatval($rescue['donor_lat'] ?? 0);
$pickupLng = floatval($rescue['donor_lng'] ?? 0);

// Fallback: if donor coordinates missing, try rescue table
if ($pickupLat === 0 || $pickupLng === 0) {
    $pickupLat = floatval($rescue['latitude'] ?? 0);
    $pickupLng = floatval($rescue['longitude'] ?? 0);
}

// ============================================
// RIDER IS AT PICKUP POINT (FOOD COLLECTED)
// ============================================
$riderLat = $pickupLat;
$riderLng = $pickupLng;

// ============================================
// HANDLE POST REQUEST (START DELIVERY)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canStartTransit) {
        $error = 'This delivery cannot be started because it is not in picked_up status. Current status: ' . $status;
    } elseif ($pickupLat === 0 || $pickupLng === 0) {
        $error = 'Pickup location is missing. The donor coordinates are required before starting transit.';
    } else {
        $db->begin_transaction();
        try {
            $newStatus = 'in_transit';
            
            // Update rescue status
            $update = $db->prepare('UPDATE rescues SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?');
            $update->bind_param('sis', $newStatus, $rescueId, $status);
            $update->execute();

            if ($update->affected_rows !== 1) {
                throw new Exception('Unable to update rescue status.');
            }

            // Insert tracking record with rider at pickup location
            $tracking = $db->prepare(
                'INSERT INTO tracking (rescue_id, rider_id, latitude, longitude, tracked_at) VALUES (?, ?, ?, ?, NOW())'
            );
            $tracking->bind_param('iidd', $rescueId, $user['id'], $riderLat, $riderLng);
            $tracking->execute();

            $db->commit();
            $canStartTransit = false;
            $status = $newStatus;

            // ============================================
            // START SIMULATION FROM PICKUP TO RECIPIENT
            // ============================================
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['simulation_step_' . $rescueId] = 1;
            $_SESSION['simulation_active_' . $rescueId] = true;
            $_SESSION['simulation_phase_' . $rescueId] = 'to_recipient';
            
            if (function_exists('generateRiderPath')) {
                $stepsCreated = generateRiderPath($rescueId);
            }
            
            if (function_exists('startRiderSimulation')) {
                $started = startRiderSimulation($rescueId);
            }

            $success = '✅ Delivery started! Rider is now moving to recipient.';
            
        } catch (Exception $ex) {
            $db->rollback();
            $error = 'Unable to start transit. ' . $ex->getMessage();
        }
        $db->close();
    }
}

// ============================================
// GET RECIPIENT LOCATION
// ============================================
$recipientLat = floatval($rescue['recipient_lat'] ?? 0);
$recipientLng = floatval($rescue['recipient_lng'] ?? 0);

// ============================================
// DONOR AND RECIPIENT DETAILS
// ============================================
$donorName = $rescue['donor_name'] ?: 'Donor';
$donorPhone = $rescue['donor_phone'] ?: 'N/A';
$recipientName = $rescue['recipient_name'] ?: 'Recipient';
$recipientPhone = $rescue['recipient_phone'] ?: 'N/A';

// ============================================
// CALCULATE DISTANCE AND ETA
// ============================================
$distanceKm = 0;
$etaMinutes = 0;
if ($pickupLat && $pickupLng && $recipientLat && $recipientLng) {
    $distanceKm = calculate_distance_km($pickupLat, $pickupLng, $recipientLat, $recipientLng);
    $etaMinutes = round(($distanceKm / 20) * 60);
}

// ============================================
// INCLUDE HEADER
// ============================================
$includeMap = true;
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    /* ============================================
       MAP CONTAINER FIXES
       ============================================ */
    #transitMap {
        height: 420px;
        width: 100%;
        border-radius: 18px;
        border: 1px solid rgba(26,58,107,0.08);
        background: #e5e7eb;
        overflow: hidden;
        position: relative;
        z-index: 1;
    }
    #transitMap .leaflet-tile-pane {
        opacity: 1;
    }
    #transitMap .leaflet-layer {
        opacity: 1;
    }
    .leaflet-container {
        font-family: inherit;
    }
    .leaflet-popup-content-wrapper {
        border-radius: 12px;
    }
    .leaflet-popup-content {
        margin: 10px 14px;
        font-size: 0.9rem;
    }
    
    /* ============================================
       CARD STYLES
       ============================================ */
    .transit-card {
        border-radius: 18px;
        box-shadow: 0 18px 40px rgba(15,23,42,0.08);
        border: 1px solid rgba(26,58,107,0.08);
        background: white;
        overflow: hidden;
    }
    .transit-card .card-header {
        background: transparent;
        border-bottom: 1px solid rgba(226,232,240,0.9);
        font-weight: 700;
        color: #1a365d;
        padding: 16px 0;
        margin-bottom: 16px;
        font-size: 1.1rem;
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
        font-size: 0.85rem;
        color: #4a5568;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .info-box p {
        margin: 0;
        color: #1a202c;
        font-weight: 700;
        word-break: break-word;
        font-size: 0.95rem;
    }
    .btn-start {
        width: 100%;
        background: linear-gradient(135deg, #ed8936, #c05621);
        color: white;
        border: none;
        padding: 14px 24px;
        border-radius: 18px;
        font-weight: 700;
        font-size: 1.1rem;
        transition: all 0.3s ease;
    }
    .btn-start:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 26px rgba(237,137,54,0.3);
        color: white;
    }
    .btn-start:disabled {
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
        background: #e0f2fe;
        color: #0369a1;
        text-transform: capitalize;
    }
    .status-pill.in_transit {
        background: #d1fae5;
        color: #065f46;
    }
    .alert-success,
    .alert-danger,
    .alert-warning {
        border-radius: 16px;
    }
    
    /* ============================================
       PROGRESS BAR
       ============================================ */
    .progress-container {
        background: #f8fafc;
        padding: 16px;
        border-radius: 16px;
        border: 1px solid rgba(66,153,225,0.12);
        margin-top: 18px;
        display: none;
    }
    .progress-container.active {
        display: block;
    }
    .progress-label {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 0.9rem;
        color: #1a365d;
    }
    .progress-bar-bg {
        height: 8px;
        background: #e2e8f0;
        border-radius: 999px;
        overflow: hidden;
    }
    .progress-bar-fill {
        height: 100%;
        width: 0%;
        background: linear-gradient(135deg, #2b6cb0, #48bb78);
        border-radius: 999px;
        transition: width 0.1s linear;
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .info-grid {
            grid-template-columns: 1fr;
        }
        #transitMap {
            height: 300px;
        }
    }
</style>

<div class="container py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                <div>
                    <h1 class="h3 fw-bold">Start Delivery</h1>
                    <p class="text-muted mb-0">Begin the journey from pickup to recipient and track your ETA in real time.</p>
                </div>
                <span class="status-pill <?= htmlspecialchars($status === 'in_transit' ? 'in_transit' : '') ?>">
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
        <!-- Left Column: Details -->
        <div class="col-xl-7">
            <div class="transit-card p-4">
                <div class="card-header">Route Details</div>

                <div class="info-grid">
                    <div class="info-box">
                        <h6>📍 Pickup Location (Donor)</h6>
                        <p><?= $pickupLat && $pickupLng ? htmlspecialchars(number_format($pickupLat, 5) . ', ' . number_format($pickupLng, 5)) : 'Unknown' ?></p>
                    </div>
                    <div class="info-box">
                        <h6>🏠 Recipient Location</h6>
                        <p><?= $recipientLat && $recipientLng ? htmlspecialchars(number_format($recipientLat, 5) . ', ' . number_format($recipientLng, 5)) : 'Unknown' ?></p>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-box">
                        <h6>Donor Name</h6>
                        <p><?= htmlspecialchars($donorName) ?></p>
                    </div>
                    <div class="info-box">
                        <h6>Recipient Name</h6>
                        <p><?= htmlspecialchars($recipientName) ?></p>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-box">
                        <h6>Donor Phone</h6>
                        <p><?= htmlspecialchars($donorPhone) ?></p>
                    </div>
                    <div class="info-box">
                        <h6>Recipient Phone</h6>
                        <p><?= htmlspecialchars($recipientPhone) ?></p>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-box">
                        <h6>Distance to Recipient</h6>
                        <p><?= $distanceKm > 0 ? htmlspecialchars(number_format($distanceKm, 2) . ' km') : 'Unknown' ?></p>
                    </div>
                    <div class="info-box">
                        <h6>Estimated ETA</h6>
                        <p><?= $etaMinutes > 0 ? htmlspecialchars($etaMinutes . ' min') : 'Unknown' ?></p>
                    </div>
                </div>

                <!-- Progress Bar (appears when animation starts) -->
                <div class="progress-container" id="progressContainer">
                    <div class="progress-label">
                        <span>Delivery Progress</span>
                        <span id="progressPercent">0%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" id="progressBarFill"></div>
                    </div>
                    <div class="progress-label mt-2">
                        <span>Time Remaining</span>
                        <span id="timeRemaining"><?= $etaMinutes ?> min</span>
                    </div>
                </div>

                <?php if (!$success && $canStartTransit): ?>
                    <form method="post" class="mt-3">
                        <input type="hidden" name="id" value="<?= intval($rescueId) ?>">
                        <button type="submit" class="btn btn-start">
                            🚀 Start Delivery to Recipient
                        </button>
                    </form>
                <?php elseif (!$success && !$canStartTransit): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php if ($status === 'in_transit'): ?>
                            ✅ Delivery is already in transit! Tracking is live on the map.
                        <?php else: ?>
                            This delivery cannot be started. Current status: <?= htmlspecialchars($status) ?>
                        <?php endif; ?>
                    </div>
                    <a href="<?= BASE_URL ?>rider/dashboard.php" class="btn btn-primary mt-2">
                        <i class="fas fa-arrow-left me-2"></i> Go to Dashboard
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Column: Map -->
        <div class="col-xl-5">
            <div class="transit-card p-0">
                <div id="transitMap"></div>
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================
    // PASS PHP VALUES TO JAVASCRIPT
    // ============================================
    const pickupLat = <?= json_encode($pickupLat) ?>;
    const pickupLng = <?= json_encode($pickupLng) ?>;
    const recipientLat = <?= json_encode($recipientLat) ?>;
    const recipientLng = <?= json_encode($recipientLng) ?>;
    const donorName = <?= json_encode($donorName) ?>;
    const recipientName = <?= json_encode($recipientName) ?>;
    const rescueId = <?= json_encode($rescueId) ?>;
    const isInTransit = <?= json_encode($status === 'in_transit') ?>;
    const justStarted = <?= json_encode(!empty($success)) ?>;
    const baseUrl = <?= json_encode(BASE_URL) ?>;
    
    // ============================================
    // ETA IN MINUTES (from PHP calculation)
    // ============================================
    const etaMinutes = <?= json_encode($etaMinutes) ?>;
    
    // Convert ETA to milliseconds for animation duration
    const totalDurationMs = etaMinutes * 60 * 1000;

    document.addEventListener('DOMContentLoaded', function () {
        // ============================================
        // INITIALIZE LEAFLET MAP
        // ============================================
        const map = L.map('transitMap', { 
            scrollWheelZoom: false,
            zoomControl: true,
            attributionControl: true
        });
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        const bounds = L.latLngBounds();
        let riderMarker = null;
        let animationFrameId = null;

        // ============================================
        // CUSTOM MARKER ICONS (INLINE STYLES)
        // ============================================
        const pickupIcon = L.divIcon({ 
            className: '',
            html: '<div style="background:#48bb78;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 10px rgba(72,187,120,0.8);"></div>',
            iconSize: [16, 16], 
            iconAnchor: [8, 8] 
        });
        
        const recipientIcon = L.divIcon({ 
            className: '',
            html: '<div style="background:#ed8936;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 10px rgba(237,137,54,0.8);"></div>',
            iconSize: [16, 16], 
            iconAnchor: [8, 8] 
        });
        
        const riderIcon = L.divIcon({ 
            className: '',
            html: '<div style="display:flex;align-items:center;justify-content:center;width:26px;height:26px;font-size:18px;filter:drop-shadow(0 2px 6px rgba(0,0,0,0.25));">🏍️</div>',
            iconSize: [26, 26], 
            iconAnchor: [13, 13] 
        });

        // ============================================
        // ADD PICKUP MARKER (DONOR LOCATION)
        // ============================================
        if (pickupLat && pickupLng) {
            L.marker([pickupLat, pickupLng], { icon: pickupIcon })
                .addTo(map)
                .bindPopup('<strong>📍 Pickup Location</strong><br>' + escapeHtml(donorName));
            bounds.extend([pickupLat, pickupLng]);
            console.log('✅ Pickup marker at:', pickupLat, pickupLng);
        }

        // ============================================
        // ADD RECIPIENT MARKER
        // ============================================
        if (recipientLat && recipientLng) {
            L.marker([recipientLat, recipientLng], { icon: recipientIcon })
                .addTo(map)
                .bindPopup('<strong>🏠 Recipient</strong><br>' + escapeHtml(recipientName));
            bounds.extend([recipientLat, recipientLng]);
            console.log('✅ Recipient marker at:', recipientLat, recipientLng);
        }

        // ============================================
        // ADD RIDER MARKER AT PICKUP LOCATION
        // ============================================
        if (pickupLat && pickupLng) {
            riderMarker = L.marker([pickupLat, pickupLng], { icon: riderIcon })
                .addTo(map)
                .bindPopup('<strong>🏍️ Rider</strong><br>At pickup. Starting delivery...');
            bounds.extend([pickupLat, pickupLng]);
            console.log('✅ Rider marker at PICKUP:', pickupLat, pickupLng);
        }

        // ============================================
        // DRAW ROUTE LINE (PICKUP TO RECIPIENT)
        // ============================================
        if (pickupLat && pickupLng && recipientLat && recipientLng) {
            L.polyline([[pickupLat, pickupLng], [recipientLat, recipientLng]], {
                color: '#2b6cb0',
                weight: 4,
                opacity: 0.65,
                dashArray: '8, 6'
            }).addTo(map);
            console.log('✅ Route line drawn');
        }

        // ============================================
        // FIT MAP TO SHOW ALL MARKERS
        // ============================================
        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [50, 50], maxZoom: 15 });
        } else {
            map.setView([30.1798, 66.9750], 13);
        }

        // Force map resize after render
        setTimeout(function() {
            map.invalidateSize();
            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [50, 50], maxZoom: 15 });
            }
        }, 300);

        // ============================================
        // SMOOTH RIDER MOVEMENT ALONG THE ROUTE
        // Moves from pickup to recipient in exact ETA time
        // ============================================
        function startRiderAnimation() {
            if (!pickupLat || !pickupLng || !recipientLat || !recipientLng) {
                console.error('Cannot start animation: missing coordinates');
                return;
            }
            
            if (!riderMarker) {
                console.error('Cannot start animation: rider marker not created');
                return;
            }
            
            console.log('🚀 Starting rider animation...');
            console.log('   From: ' + pickupLat + ', ' + pickupLng);
            console.log('   To: ' + recipientLat + ', ' + recipientLng);
            console.log('   Duration: ' + etaMinutes + ' minutes (' + totalDurationMs + ' ms)');
            
            // Show progress bar
            const progressContainer = document.getElementById('progressContainer');
            if (progressContainer) {
                progressContainer.classList.add('active');
            }
            
            // Clear any existing animation
            if (animationFrameId) {
                cancelAnimationFrame(animationFrameId);
            }
            
            const animationStartTime = performance.now();
            
            function animateRider(currentTime) {
                // Calculate progress (0 to 1)
                const elapsed = currentTime - animationStartTime;
                const progress = Math.min(elapsed / totalDurationMs, 1);
                
                // Ease function for smooth start and end (easeInOutQuad)
                let easedProgress;
                if (progress < 0.5) {
                    easedProgress = 2 * progress * progress;
                } else {
                    easedProgress = 1 - Math.pow(-2 * progress + 2, 2) / 2;
                }
                
                // Calculate current position along the line
                const currentLat = pickupLat + (recipientLat - pickupLat) * easedProgress;
                const currentLng = pickupLng + (recipientLng - pickupLng) * easedProgress;
                
                // Update rider marker position
                riderMarker.setLatLng([currentLat, currentLng]);
                
                // Update popup with progress info
                const percentComplete = Math.round(easedProgress * 100);
                const remainingMinutes = Math.ceil(etaMinutes * (1 - easedProgress));
                riderMarker.setPopupContent(
                    '<strong>🏍️ Rider</strong><br>' +
                    'Progress: ' + percentComplete + '%<br>' +
                    'ETA: ' + remainingMinutes + ' min remaining'
                );
                
                // Update progress bar
                const progressBarFill = document.getElementById('progressBarFill');
                const progressPercent = document.getElementById('progressPercent');
                const timeRemaining = document.getElementById('timeRemaining');
                if (progressBarFill) {
                    progressBarFill.style.width = percentComplete + '%';
                }
                if (progressPercent) {
                    progressPercent.textContent = percentComplete + '%';
                }
                if (timeRemaining) {
                    timeRemaining.textContent = remainingMinutes + ' min';
                }
                
                // Keep rider in view during animation
                if (progress < 0.95) {
                    map.panTo([currentLat, currentLng], { animate: false });
                }
                
                // Continue animation or finish
                if (progress < 1) {
                    animationFrameId = requestAnimationFrame(animateRider);
                } else {
                    // Animation complete
                    console.log('✅ Rider reached recipient in ' + etaMinutes + ' minutes');
                    
                    // Update rider popup
                    riderMarker.setPopupContent(
                        '<strong>🏍️ Rider</strong><br>✅ Delivered to recipient!'
                    );
                    riderMarker.openPopup();
                    
                    // Update progress bar to 100%
                    if (progressBarFill) {
                        progressBarFill.style.width = '100%';
                    }
                    if (progressPercent) {
                        progressPercent.textContent = '100%';
                    }
                    if (timeRemaining) {
                        timeRemaining.textContent = '0 min';
                    }
                    
                    // Update database status to delivered
                    updateDeliveryStatus('delivered');
                    
                    // Redirect after short delay
                    setTimeout(function() {
                        window.location.href = baseUrl + 'rider/dashboard.php';
                    }, 2500);
                }
            }
            
            // Start the animation
            animationFrameId = requestAnimationFrame(animateRider);
        }
        
        // ============================================
        // UPDATE DELIVERY STATUS IN DATABASE
        // ============================================
        async function updateDeliveryStatus(newStatus) {
            try {
                const res = await fetch(baseUrl + 'rider/update_delivery_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        rescue_id: rescueId, 
                        status: newStatus 
                    })
                });
                const data = await res.json();
                console.log('📦 Status update response:', data);
                return data;
            } catch (e) {
                console.error('Status update error:', e);
                return null;
            }
        }
        
        // ============================================
        // START ANIMATION WHEN APPROPRIATE
        // ============================================
        if (justStarted || isInTransit) {
            // Small delay to let map render first
            setTimeout(function() {
                startRiderAnimation();
            }, 1000);
        }
    });

    // ============================================
    // HELPER: ESCAPE HTML
    // ============================================
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