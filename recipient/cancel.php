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
$recipientId = $user['id'];

// Get rescue ID from URL
$rescueId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($rescueId <= 0) {
    $_SESSION['error'] = 'Invalid rescue ID.';
    header('Location: ' . BASE_URL . 'recipient/dashboard.php');
    exit;
}

// Check if this rescue belongs to the recipient
$stmt = $db->prepare('
    SELECT r.id, r.status, r.recipient_id, r.assigned_rider_id, r.listing_id,
           u.name AS rider_name
    FROM rescues r
    LEFT JOIN users u ON u.id = r.assigned_rider_id
    WHERE r.id = ? AND r.recipient_id = ?
');
$stmt->bind_param('ii', $rescueId, $recipientId);
$stmt->execute();
$result = $stmt->get_result();
$rescue = $result->fetch_assoc();

if (!$rescue) {
    $_SESSION['error'] = 'Rescue not found or you don\'t have permission.';
    header('Location: ' . BASE_URL . 'recipient/dashboard.php');
    exit;
}

// ============================================
// CHECK IF CANCELLATION IS ALLOWED
// ============================================
$allowedStatuses = ['pending', 'accepted'];

if (!in_array($rescue['status'], $allowedStatuses)) {
    $messages = [
        'picked_up' => 'Cannot cancel. Rider has already picked up the food.',
        'in_transit' => 'Cannot cancel. Food is already on the way to you.',
        'delivered' => 'Cannot cancel. Food has already been delivered.',
        'cancelled' => 'This rescue is already cancelled.'
    ];
    
    $errorMsg = $messages[$rescue['status']] ?? 'Rescue cannot be cancelled at this stage. Current status: ' . $rescue['status'];
    
    $_SESSION['error'] = $errorMsg;
    header('Location: ' . BASE_URL . 'recipient/dashboard.php');
    exit;
}

// ============================================
// PROCESS CANCELLATION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    $db->begin_transaction();
    
    try {
        // Update rescue status to cancelled and free rider
        $stmt = $db->prepare('
            UPDATE rescues 
            SET status = "cancelled",
                assigned_rider_id = NULL
            WHERE id = ? AND recipient_id = ?
        ');
        $stmt->bind_param('ii', $rescueId, $recipientId);
        $stmt->execute();
        
        // Update the listing status back to published
        $stmt = $db->prepare('
            UPDATE listings 
            SET status = "published" 
            WHERE id = ?
        ');
        $stmt->bind_param('i', $rescue['listing_id']);
        $stmt->execute();
        
        $db->commit();
        
        $_SESSION['success'] = 'Rescue cancelled successfully.';
        header('Location: ' . BASE_URL . 'recipient/dashboard.php');
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = 'Failed to cancel rescue. Please try again.';
        header('Location: ' . BASE_URL . 'recipient/dashboard.php');
        exit;
    }
}

$db->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow border-0">
                <div class="card-header bg-white py-3">
                    <h4 class="mb-0 text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i> Cancel Rescue
                    </h4>
                </div>
                <div class="card-body">
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']) ?></div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>
                    
                    <div class="text-center mb-4">
                        <i class="fas fa-times-circle text-danger" style="font-size: 4rem;"></i>
                        <h5 class="mt-3">Are you sure you want to cancel this rescue?</h5>
                        
                        <div class="mt-3">
                            <p><strong>Rescue ID:</strong> #<?= $rescueId ?></p>
                            <p><strong>Status:</strong> <?= ucfirst($rescue['status']) ?></p>
                            <?php if ($rescue['assigned_rider_id']): ?>
                                <p class="text-warning">
                                    <i class="fas fa-motorcycle"></i> 
                                    Rider: <?= htmlspecialchars($rescue['rider_name']) ?> is assigned
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <p class="text-warning mt-3">
                            <i class="fas fa-info-circle"></i> 
                            This action cannot be undone.
                        </p>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="confirm" value="1">
                        <div class="d-flex gap-3 justify-content-center">
                            <a href="<?= BASE_URL ?>recipient/dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Go Back
                            </a>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-check me-1"></i> Yes, Cancel Rescue
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>