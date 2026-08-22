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
$donorId = $user['id'];

// Get all listings for this donor
$stmt = $db->prepare('SELECT id, title, description, status, created_at, updated_at, latitude, longitude 
                      FROM listings 
                      WHERE donor_id = ? 
                      ORDER BY created_at DESC');
$stmt->bind_param('i', $donorId);
$stmt->execute();
$listings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$db->close();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .listings-container { padding: 20px 0; }
    .listing-card {
        background: white;
        border-radius: 12px;
        padding: 16px 20px;
        border: 1px solid rgba(26,58,107,0.06);
        transition: all 0.3s ease;
        margin-bottom: 12px;
    }
    .listing-card:hover {
        border-color: #2b6cb0;
        box-shadow: 0 4px 20px rgba(43,108,176,0.08);
        transform: translateY(-2px);
    }
    .status-badge {
        padding: 4px 14px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-badge.published { background: #d1fae5; color: #065f46; }
    .status-badge.matched { background: #fef3c7; color: #b7791f; }
    .status-badge.in_transit { background: #e0f2fe; color: #0369a1; }
    .status-badge.delivered { background: #e8e8f0; color: #4a5568; }
    .status-badge.cancelled { background: #fee2e2; color: #991b1b; }
    .status-badge.expired { background: #fef3c7; color: #b7791f; }
    .empty-state { text-align: center; padding: 60px 0; }
    .empty-state .icon { font-size: 4rem; color: #cbd5e1; margin-bottom: 16px; }
    .stat-count {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1a365d;
    }
    .stat-label {
        color: #718096;
        font-size: 0.85rem;
    }
    .stat-item {
        text-align: center;
        padding: 8px 12px;
        border-radius: 8px;
        background: #f7fafc;
    }
</style>

<div class="listings-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="fw-bold text-primary-dark">
                <i class="fas fa-list"></i> All Listings
            </h1>
            <p class="text-muted">View and manage all your food listings</p>
        </div>
        <a href="<?= BASE_URL ?>donor/broadcast.php" class="btn btn-primary-nekirot">
            <i class="fas fa-plus me-2"></i> New Listing
        </a>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-3">
            <div class="stat-item">
                <div class="stat-count"><?= count($listings) ?></div>
                <div class="stat-label">Total</div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-item">
                <div class="stat-count">
                    <?php 
                    $count = 0;
                    foreach ($listings as $l) {
                        if ($l['status'] === 'published') $count++;
                    }
                    echo $count;
                    ?>
                </div>
                <div class="stat-label">Available</div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-item">
                <div class="stat-count">
                    <?php 
                    $count = 0;
                    foreach ($listings as $l) {
                        if ($l['status'] === 'matched') $count++;
                    }
                    echo $count;
                    ?>
                </div>
                <div class="stat-label">Matched</div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-item">
                <div class="stat-count">
                    <?php 
                    $count = 0;
                    foreach ($listings as $l) {
                        if ($l['status'] === 'delivered' || $l['status'] === 'completed') $count++;
                    }
                    echo $count;
                    ?>
                </div>
                <div class="stat-label">Completed</div>
            </div>
        </div>
    </div>

    <!-- Listings -->
    <?php if (empty($listings)): ?>
        <div class="empty-state">
            <div class="icon">📋</div>
            <h4>No listings yet</h4>
            <p class="text-muted">Start broadcasting food to help the community.</p>
            <a href="<?= BASE_URL ?>donor/broadcast.php" class="btn btn-primary-nekirot">
                <i class="fas fa-plus me-2"></i> Create First Listing
            </a>
        </div>
    <?php else: ?>
        <div class="row">
            <div class="col-12">
                <?php foreach ($listings as $listing): ?>
                    <div class="listing-card">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <h5 class="fw-bold mb-1"><?= htmlspecialchars($listing['title']) ?></h5>
                                <p class="text-muted small mb-2">
                                    <?php 
                                    $desc = htmlspecialchars($listing['description'] ?? '');
                                    echo strlen($desc) > 150 ? substr($desc, 0, 150) . '...' : $desc;
                                    ?>
                                </p>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="status-badge <?= $listing['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $listing['status'])) ?>
                                    </span>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i> <?= timeAgo($listing['created_at']) ?>
                                    </small>
                                </div>
                            </div>
                            <div class="text-end" style="min-width: 100px;">
                                <a href="<?= BASE_URL ?>donor/listing.php?id=<?= $listing['id'] ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <?php if ($listing['status'] === 'published'): ?>
                                    <button class="btn btn-outline-danger btn-sm mt-1 d-block" onclick="cancelListing(<?= $listing['id'] ?>)">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function cancelListing(listingId) {
    if (!confirm('Are you sure you want to cancel this listing?')) {
        return;
    }
    
    fetch('<?= BASE_URL ?>api/listings/delete.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ listing_id: listingId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Listing cancelled successfully');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.error || 'Unable to cancel listing'));
        }
    })
    .catch(error => {
        alert('❌ Error cancelling listing');
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>