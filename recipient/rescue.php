<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_login();
$user = current_user();

$rescueId = intval($_GET['id'] ?? 0);
if (!$rescueId) {
    header('Location: ' . BASE_URL . 'recipient/dashboard.php');
    exit;
}

$db = get_db_connection();
$stmt = $db->prepare('SELECT * FROM rescues WHERE id = ? AND recipient_id = ? LIMIT 1');
$stmt->bind_param('ii', $rescueId, $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$rescue = $result->fetch_assoc();
if (!$rescue) {
    header('Location: ' . BASE_URL . 'recipient/dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="card-title">Rescue Request</h2>
        <dl class="row">
            <dt class="col-sm-3">Title</dt>
            <dd class="col-sm-9"><?= htmlspecialchars($rescue['title']) ?></dd>
            <dt class="col-sm-3">Description</dt>
            <dd class="col-sm-9"><?= nl2br(htmlspecialchars($rescue['description'])) ?></dd>
            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9"><?= htmlspecialchars($rescue['status']) ?></dd>
            <dt class="col-sm-3">Location</dt>
            <dd class="col-sm-9"><?= htmlspecialchars($rescue['latitude']) ?>, <?= htmlspecialchars($rescue['longitude']) ?></dd>
            <dt class="col-sm-3">Contact</dt>
            <dd class="col-sm-9"><?= htmlspecialchars($rescue['contact_phone']) ?></dd>
            <dt class="col-sm-3">Created</dt>
            <dd class="col-sm-9"><?= htmlspecialchars($rescue['created_at']) ?></dd>
        </dl>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php';
