<?php
include_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
require_login();
$user = current_user();
$alerts = get_alerts_for_user($user['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alert_id = intval($_POST['alert_id'] ?? 0);
    if ($alert_id) {
        mark_alert_read($alert_id, $user['id']);
        header('Location: ' . BASE_URL . 'alerts.php');
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="card-title">Alerts</h2>
        <?php if (empty($alerts)): ?>
            <div class="alert alert-info">No alerts yet.</div>
        <?php else: ?>
            <ul class="list-group">
                <?php foreach ($alerts as $alert): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start <?= $alert['is_read'] ? '' : 'list-group-item-warning' ?>">
                        <div>
                            <div class="fw-bold"><?= htmlspecialchars($alert['type']) ?></div>
                            <p class="mb-1"><?= nl2br(htmlspecialchars($alert['message'])) ?></p>
                            <small class="text-muted"><?= htmlspecialchars($alert['created_at']) ?></small>
                        </div>
                        <?php if (!$alert['is_read']): ?>
                            <form method="post">
                                <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php';
