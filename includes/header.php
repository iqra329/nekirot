<?php
include_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2b6cb0">
    <link rel="manifest" href="<?= BASE_URL ?>manifest.json">
    <title>NekiRot - Quetta Food Rescue</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="<?= BASE_URL ?>assets/css/nekirot-theme.css" rel="stylesheet">
    <?php if (isset($includeMap) && $includeMap): ?>
        <link href="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css" rel="stylesheet">
    <?php endif; ?>
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-nekirot">
    <div class="container">
        <a class="navbar-brand" href="<?= BASE_URL ?>">
            <i class="fas fa-leaf" style="color: #2b6cb0;"></i>
            NekiRot <span>Quetta</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>">
                        <i class="fas fa-home"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'map.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>map.php">
                        <i class="fas fa-map"></i> Live Map
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'leaderboard.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>leaderboard.php">
                        <i class="fas fa-trophy"></i> Leaderboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'about.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>about.php">
                        <i class="fas fa-circle-info"></i> About
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'contact.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>contact.php">
                        <i class="fas fa-envelope"></i> Contact
                    </a>
                </li>
                <?php if ($user['id']): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle"></i> <?= htmlspecialchars($user['name'] ?? 'User') ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                            <li>
                                <a class="dropdown-item" href="<?= BASE_URL ?><?= htmlspecialchars($user['type']) ?>/dashboard.php">
                                    <i class="fas fa-dashboard"></i> Dashboard
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="<?= BASE_URL ?>logout.php">
                                    <i class="fas fa-sign-out-alt"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>login.php">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-light text-primary fw-bold px-4 py-2 rounded-pill" href="<?= BASE_URL ?>register.php">
                            <i class="fas fa-user-plus"></i> Register
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$excludeBack = ['index.php', 'login.php', 'register.php'];
$showBackButton = !in_array($currentPage, $excludeBack) && strpos($currentPage, 'dashboard.php') === false;
if ($showBackButton):
?>
<div class="container mt-3">
    <a href="javascript:history.back()" class="btn-back">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>
<?php endif; ?>

<main class="main-content">
<div class="container mt-4">
