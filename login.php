<?php
include_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$email || !$password) {
        $error = 'Email and password are required.';
    } else {
        $db = get_db_connection();
        $stmt = $db->prepare('SELECT id, name, password_hash, user_type FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        if ($user && password_verify($password, $user['password_hash'])) {
            login_user($user);
            header('Location: ' . BASE_URL . 'index.php');
            exit;
        }
        $error = 'Invalid email or password.';
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
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="card-title mb-4">Login</h2>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= BASE_URL ?>login.php" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="Enter your email address" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="passwordField" class="form-control" placeholder="Enter your password" autocomplete="new-password" required>
                            <button type="button" class="toggle-password" id="togglePasswordBtn" onclick="togglePassword()">
                                <i class="fas fa-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Login</button>
                </form>
                <div class="text-center mt-3">
                    <small class="text-muted">Don't have an account? <a href="<?= BASE_URL ?>register.php">Register here</a></small>
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