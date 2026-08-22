<?php
include_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$db = get_db_connection();

// Top Donors
$sql = "SELECT u.id, u.name, u.user_type AS type, u.phone AS contact_phone,
            COUNT(l.id) AS total_listings,
            SUM(CASE WHEN l.status = 'completed' THEN 1 ELSE 0 END) AS total_completed,
            SUM(CASE WHEN l.status IN ('published', 'matched') THEN 1 ELSE 0 END) AS active_listings
            FROM users u
            LEFT JOIN listings l ON l.donor_id = u.id
            WHERE u.user_type = 'donor' AND u.is_active = 1
            GROUP BY u.id
            ORDER BY total_completed DESC, total_listings DESC
            LIMIT 20";
$result = $db->query($sql);
$donorRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Top Riders
$sql = "SELECT u.id, u.name, u.user_type AS type, u.phone AS contact_phone,
            COUNT(r.id) AS total_assignments,
            SUM(CASE WHEN r.status = 'delivered' THEN 1 ELSE 0 END) AS total_deliveries
            FROM users u
            LEFT JOIN rescues r ON r.assigned_rider_id = u.id
            WHERE u.user_type = 'rider' AND u.is_active = 1
            GROUP BY u.id
            ORDER BY total_deliveries DESC, total_assignments DESC
            LIMIT 20";
$result = $db->query($sql);
$riderRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$db->close();

// Medal colors
function getMedal($index) {
    $medals = [
        0 => ['icon' => '🥇', 'class' => 'bg-warning text-dark'],
        1 => ['icon' => '🥈', 'class' => 'bg-secondary text-white'],
        2 => ['icon' => '🥉', 'class' => 'bg-orange text-white'],
    ];
    return $medals[$index] ?? ['icon' => '#', 'class' => 'bg-light text-dark'];
}
?>

<style>
    .leaderboard-card {
        background: white;
        border-radius: 16px;
        border: 1px solid rgba(26,58,107,0.06);
        box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        overflow: hidden;
        transition: all 0.3s ease;
        height: 100%;
    }
    .leaderboard-card:hover {
        box-shadow: 0 8px 40px rgba(0,0,0,0.1);
        transform: translateY(-4px);
    }
    .leaderboard-header {
        padding: 20px 24px;
        background: linear-gradient(135deg, #1a365d, #2b6cb0);
        color: white;
    }
    .leaderboard-header h4 {
        margin: 0;
        font-weight: 700;
    }
    .leaderboard-header small {
        opacity: 0.8;
    }
    .leaderboard-body {
        padding: 20px;
    }
    .rank-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        font-weight: 700;
        font-size: 0.85rem;
    }
    .rank-gold {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: white;
    }
    .rank-silver {
        background: linear-gradient(135deg, #94a3b8, #64748b);
        color: white;
    }
    .rank-bronze {
        background: linear-gradient(135deg, #d97706, #b45309);
        color: white;
    }
    .rank-normal {
        background: #f1f5f9;
        color: #475569;
    }
    .leaderboard-table {
        width: 100%;
        border-collapse: collapse;
    }
    .leaderboard-table th {
        text-align: left;
        padding: 10px 12px;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #94a3b8;
        font-weight: 600;
        border-bottom: 2px solid #e8e8f0;
    }
    .leaderboard-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: middle;
    }
    .leaderboard-table tbody tr {
        transition: all 0.3s ease;
    }
    .leaderboard-table tbody tr:hover {
        background: #f8fafc;
    }
    .leaderboard-table tbody tr:last-child td {
        border-bottom: none;
    }
    .user-name {
        font-weight: 600;
        color: #1a365d;
    }
    .user-phone {
        font-size: 0.8rem;
        color: #94a3b8;
    }
    .stat-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .stat-badge-success {
        background: #d1fae5;
        color: #065f46;
    }
    .stat-badge-primary {
        background: #e0f2fe;
        color: #0369a1;
    }
    .stat-badge-warning {
        background: #fef3c7;
        color: #b7791f;
    }
    .bg-orange {
        background: #f97316;
        color: white;
    }
    .medal-icon {
        font-size: 1.2rem;
    }
    .top-rank-row {
        background: linear-gradient(90deg, rgba(245, 158, 11, 0.05), transparent);
    }
    .top-rank-row td:first-child {
        border-left: 3px solid #f59e0b;
    }
    .top-rank-row-2 {
        background: linear-gradient(90deg, rgba(148, 163, 184, 0.05), transparent);
    }
    .top-rank-row-2 td:first-child {
        border-left: 3px solid #94a3b8;
    }
    .top-rank-row-3 {
        background: linear-gradient(90deg, rgba(217, 119, 6, 0.05), transparent);
    }
    .top-rank-row-3 td:first-child {
        border-left: 3px solid #d97706;
    }
    .empty-state {
        text-align: center;
        padding: 30px 0;
        color: #94a3b8;
    }
    .empty-state .icon {
        font-size: 3rem;
        margin-bottom: 12px;
    }
    @media (max-width: 768px) {
        .leaderboard-header {
            padding: 16px 20px;
        }
        .leaderboard-body {
            padding: 16px;
        }
        .leaderboard-table td, .leaderboard-table th {
            padding: 8px 10px;
            font-size: 0.85rem;
        }
    }
</style>

<div class="container py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12 text-center">
            <h1 class="fw-bold text-primary-dark">
                <i class="fas fa-trophy text-gold me-2"></i> Leaderboard
            </h1>
            <p class="text-muted">Top donors and riders powering food rescue in Quetta.</p>
        </div>
    </div>

    <div class="row g-4">
        <!-- Top Donors -->
        <div class="col-lg-6">
            <div class="leaderboard-card">
                <div class="leaderboard-header">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h4><i class="fas fa-store me-2"></i> Top Donors</h4>
                            <small>Ranked by completed food donations</small>
                        </div>
                        <span class="badge bg-light text-dark rounded-pill px-3">🏆</span>
                    </div>
                </div>
                <div class="leaderboard-body">
                    <?php if (empty($donorRows)): ?>
                        <div class="empty-state">
                            <div class="icon">📋</div>
                            <p>No donors yet. Be the first to contribute!</p>
                        </div>
                    <?php else: ?>
                        <table class="leaderboard-table">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>Donor</th>
                                    <th style="text-align: center;">Completed</th>
                                    <th style="text-align: center;">Active</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($donorRows as $index => $donor): 
                                    $medal = getMedal($index);
                                    $rankClass = '';
                                    if ($index === 0) $rankClass = 'top-rank-row';
                                    elseif ($index === 1) $rankClass = 'top-rank-row-2';
                                    elseif ($index === 2) $rankClass = 'top-rank-row-3';
                                ?>
                                    <tr class="<?= $rankClass ?>">
                                        <td>
                                            <?php if ($index < 3): ?>
                                                <span class="medal-icon"><?= $medal['icon'] ?></span>
                                            <?php else: ?>
                                                <span class="rank-badge rank-normal"><?= $index + 1 ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="user-name"><?= htmlspecialchars($donor['name'] ?? 'Unknown') ?></div>
                                            <div class="user-phone"><?= htmlspecialchars($donor['contact_phone'] ?? '') ?></div>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="stat-badge stat-badge-success"><?= intval($donor['total_completed']) ?></span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="stat-badge stat-badge-primary"><?= intval($donor['active_listings']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Riders -->
        <div class="col-lg-6">
            <div class="leaderboard-card">
                <div class="leaderboard-header">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h4><i class="fas fa-motorcycle me-2"></i> Top Riders</h4>
                            <small>Ranked by successful deliveries</small>
                        </div>
                        <span class="badge bg-light text-dark rounded-pill px-3">🏍️</span>
                    </div>
                </div>
                <div class="leaderboard-body">
                    <?php if (empty($riderRows)): ?>
                        <div class="empty-state">
                            <div class="icon">🏍️</div>
                            <p>No riders yet. Join as a rider to start delivering!</p>
                        </div>
                    <?php else: ?>
                        <table class="leaderboard-table">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>Rider</th>
                                    <th style="text-align: center;">Delivered</th>
                                    <th style="text-align: center;">Assigned</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($riderRows as $index => $rider): 
                                    $medal = getMedal($index);
                                    $rankClass = '';
                                    if ($index === 0) $rankClass = 'top-rank-row';
                                    elseif ($index === 1) $rankClass = 'top-rank-row-2';
                                    elseif ($index === 2) $rankClass = 'top-rank-row-3';
                                ?>
                                    <tr class="<?= $rankClass ?>">
                                        <td>
                                            <?php if ($index < 3): ?>
                                                <span class="medal-icon"><?= $medal['icon'] ?></span>
                                            <?php else: ?>
                                                <span class="rank-badge rank-normal"><?= $index + 1 ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="user-name"><?= htmlspecialchars($rider['name'] ?? 'Unknown') ?></div>
                                            <div class="user-phone"><?= htmlspecialchars($rider['contact_phone'] ?? '') ?></div>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="stat-badge stat-badge-success"><?= intval($rider['total_deliveries']) ?></span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="stat-badge stat-badge-primary"><?= intval($rider['total_assignments']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>