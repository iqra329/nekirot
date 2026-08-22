<?php
include_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $type = $_POST['type'] ?? '';
    $phone = trim($_POST['phone'] ?? '');

    if (!$name || !$email || !$password || !$type || !$phone) {
        $error = 'All fields are required.';
    } else {
        $db = get_db_connection();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = 'A user with that email already exists.';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, user_type, phone) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('sssss', $name, $email, $password_hash, $type, $phone);
            if ($stmt->execute()) {
                $user_id = $db->insert_id;
                login_user(['id' => $user_id, 'name' => $name, 'user_type' => $type]);
                header('Location: ' . BASE_URL . 'index.php');
                exit;
            } else {
                $error = 'Could not create account. Please try again.';
            }
        }
    }
}
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .password-wrapper {
        position: relative;
    }
    .password-wrapper .form-control {
        padding-right: 45px;
    }
    .toggle-password {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #6c757d;
        cursor: pointer;
        font-size: 1.1rem;
        padding: 0;
        z-index: 5;
    }
    .toggle-password:hover {
        color: #2b6cb0;
    }
</style>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="card-title mb-4">Register</h2>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= BASE_URL ?>register.php" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Enter your full name" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" placeholder="e.g., 03123456789" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="Enter your email address" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="passwordField" class="form-control" placeholder="Create a strong password" autocomplete="new-password" required>
                            <button type="button" class="toggle-password" onclick="togglePassword()">
                                <i class="fas fa-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Account Type</label>
                        <select name="type" class="form-select" autocomplete="off" required>
                            <option value="">Choose your account type...</option>
                            <option value="donor">Donor — I have food to share</option>
                            <option value="recipient">Recipient — I need food</option>
                            <option value="rider">Rider — I want to deliver</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Register</button>
                </form>
                <div class="text-center mt-3">
                    <small class="text-muted">Already have an account? <a href="<?= BASE_URL ?>login.php">Login here</a></small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function togglePassword() {
        const passwordField = document.getElementById('passwordField');
        const eyeIcon = document.getElementById('eyeIcon');
        
        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            eyeIcon.className = 'fas fa-eye-slash';
        } else {
            passwordField.type = 'password';
            eyeIcon.className = 'fas fa-eye';
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>