<?php
include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$user = current_user();
if ($user['type'] !== 'donor') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $latitude = $_POST['latitude'] ?? null;
    $longitude = $_POST['longitude'] ?? null;

    if (!$title || !$description || !$contact_phone || !$latitude || !$longitude) {
        $error = 'All fields are required.';
    } elseif (!validate_quetta_bounds($latitude, $longitude)) {
        $error = 'Location must be within Quetta boundaries.';
    } else {
        $db = get_db_connection();
        $stmt = $db->prepare('INSERT INTO listings (donor_id, title, description, contact_phone, latitude, longitude, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $status = 'published';
        $stmt->bind_param('isssdds', $user['id'], $title, $description, $contact_phone, $latitude, $longitude, $status);
        if ($stmt->execute()) {
            header('Location: ' . BASE_URL . 'donor/dashboard.php');
            exit;
        }
        $error = 'Unable to create listing. Please try again.';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="card-title mb-4">Create Listing</h2>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= BASE_URL ?>donor/create_listing.php">
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact Phone</label>
                        <input type="text" name="contact_phone" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Latitude</label>
                        <input type="number" step="0.000001" name="latitude" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Longitude</label>
                        <input type="number" step="0.000001" name="longitude" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php';
