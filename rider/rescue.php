<?php
include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_login();
$user = current_user();
if ($user['type'] !== 'rider') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$rescueId = intval($_GET['id'] ?? 0);
if (!$rescueId) {
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

$db = get_db_connection();
$stmt = $db->prepare('SELECT * FROM rescues WHERE id = ? AND assigned_rider_id = ? LIMIT 1');
$stmt->bind_param('ii', $rescueId, $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$rescue = $result->fetch_assoc();
if (!$rescue) {
    header('Location: ' . BASE_URL . 'rider/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = $_POST['status'] ?? $rescue['status'];
    if (in_array($status, ['pending', 'accepted', 'in_transit', 'delivered'], true)) {
        $update = $db->prepare('UPDATE rescues SET status = ?, updated_at = NOW() WHERE id = ?');
        $update->bind_param('si', $status, $rescueId);
        $update->execute();
        header('Location: ' . BASE_URL . 'rider/rescue.php?id=' . $rescueId);
        exit;
    }
}

$statusOptions = ['pending' => 'Pending', 'accepted' => 'Accepted', 'in_transit' => 'In Transit', 'delivered' => 'Delivered'];
?>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="card-title">Assigned Rescue</h2>
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
        <form method="post" action="<?= BASE_URL ?>rider/rescue.php?id=<?= $rescue['id'] ?>">
            <div class="mb-3">
                <label class="form-label">Update Status</label>
                <select name="status" class="form-select">
                    <?php foreach ($statusOptions as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $key === $rescue['status'] ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Save Status</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php';
