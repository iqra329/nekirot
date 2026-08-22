<?php
// ============================================
// NEKIROT QUETTA - HOME PAGE
// ============================================

include_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$db = get_db_connection();

// ============================================
// GET MEALS SAVED (From delivered rescues)
// ============================================
$meals = 0;
$result = $db->query("SELECT COUNT(*) AS total FROM rescues WHERE status = 'delivered'");
if ($result) {
    $meals = intval($result->fetch_assoc()['total'] ?? 0);
}

// ============================================
// GET ACTIVE DONORS
// ============================================
$result = $db->query("SELECT COUNT(*) AS count FROM users WHERE user_type = 'donor' AND is_active = 1");
$donors = 0;
if ($result) {
    $donors = intval($result->fetch_assoc()['count'] ?? 0);
}

// ============================================
// GET ACTIVE RECIPIENTS
// ============================================
$result = $db->query("SELECT COUNT(*) AS count FROM users WHERE user_type = 'recipient' AND is_active = 1");
$recipients = 0;
if ($result) {
    $recipients = intval($result->fetch_assoc()['count'] ?? 0);
}

// ============================================
// GET ACTIVE RIDERS
// ============================================
$result = $db->query("SELECT COUNT(*) AS count FROM users WHERE user_type = 'rider' AND is_active = 1");
$riders = 0;
if ($result) {
    $riders = intval($result->fetch_assoc()['count'] ?? 0);
}

// ============================================
// GET RECENT RESCUES
// ============================================
$stmt = $db->prepare('SELECT r.id, r.title, r.status, r.created_at, u.name AS recipient_name FROM rescues r JOIN users u ON u.id = r.recipient_id ORDER BY r.created_at DESC LIMIT 5');
$stmt->execute();
$recentRescues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ============================================
// GET TODAY'S RESCUES COUNT
// ============================================
$result = $db->query("SELECT COUNT(*) AS total FROM rescues WHERE DATE(created_at) = CURDATE() AND status != 'cancelled'");
$todayRescues = $result ? intval($result->fetch_assoc()['total'] ?? 0) : 0;

$db->close();

// ============================================
// CALCULATE PERCENTAGE FOR PROGRESS BAR
// ============================================
$percentage = 20;
if ($meals > 0) {
    $percentage = min(100, max(20, $meals));
}
?>

<style>
/* ============================================
   HERO SECTION
   ============================================ */
.hero-section {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #1a3a6b 0%, #2b6cb0 45%, #d4a84b 100%);
    color: white;
    padding: 80px 0 100px;
    border-radius: 0 0 32px 32px;
    box-shadow: 0 20px 60px rgba(26, 58, 107, 0.2);
    margin-bottom: 40px;
    z-index: 1;
}

.hero-section::before,
.hero-section::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    background: rgba(255,255,255,0.12);
    animation: floatShape 10s ease-in-out infinite;
}

.hero-section::before {
    width: 320px;
    height: 320px;
    top: -100px;
    right: -120px;
}

.hero-section::after {
    width: 220px;
    height: 220px;
    bottom: -80px;
    left: -80px;
    animation-delay: 2s;
}

.hero-particles {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.hero-particle {
    position: absolute;
    font-size: 1.4rem;
    opacity: 0.8;
    animation: drift 8s linear infinite;
}

.hero-title {
    font-size: clamp(2.1rem, 4vw, 3.7rem);
    font-weight: 800;
    line-height: 1.1;
    letter-spacing: -0.03em;
}

.hero-subtitle {
    font-size: clamp(1.2rem, 2.4vw, 1.75rem);
    color: rgba(255,255,255,0.9);
    font-weight: 600;
    margin-top: 10px;
}

.hero-copy {
    font-size: 1.08rem;
    color: rgba(255,255,255,0.88);
    max-width: 720px;
    margin: 18px auto 0;
}

.hero-cta .btn {
    min-width: 190px;
    border-radius: 999px;
    padding: 13px 24px;
    font-weight: 700;
}

.hero-cta .btn-gold {
    background: linear-gradient(135deg, #d4a84b, #f3d37b);
    color: #1a3a6b;
    border: none;
    box-shadow: 0 10px 30px rgba(212, 168, 75, 0.28);
}

.hero-cta .btn-outline-light {
    border: 2px solid rgba(255,255,255,0.8);
    color: white;
    background: rgba(255,255,255,0.08);
}

.hero-card {
    background: rgba(255,255,255,0.16);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,0.22);
    border-radius: 24px;
    padding: 24px;
    box-shadow: 0 18px 48px rgba(0,0,0,0.16);
}

.section-card {
    background: white;
    border-radius: 24px;
    border: 1px solid rgba(26,58,107,0.08);
    box-shadow: 0 16px 40px rgba(26,58,107,0.08);
    transition: transform .3s ease, box-shadow .3s ease;
}

.section-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 24px 60px rgba(26,58,107,0.12);
}

.stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 999px;
    background: rgba(255,255,255,0.12);
    color: white;
    font-weight: 700;
}

.stat-card-modern {
    border-radius: 24px;
    padding: 24px;
    background: linear-gradient(145deg, #ffffff, #f4f8ff);
    border: 1px solid rgba(26,58,107,0.08);
    min-height: 180px;
    transition: all .3s ease;
}

.stat-card-modern:hover {
    transform: translateY(-6px);
    box-shadow: 0 20px 44px rgba(26,58,107,0.12);
}

.stat-number {
    font-size: 2rem;
    font-weight: 800;
    color: #1a3a6b;
}

.step-card {
    position: relative;
    padding: 30px 24px;
    border-radius: 24px;
    background: white;
    border: 1px solid rgba(26,58,107,0.08);
    min-height: 240px;
    transition: all .3s ease;
}

.step-card:hover {
    transform: translateY(-6px) scale(1.01);
    box-shadow: 0 20px 44px rgba(26,58,107,0.12);
}

.step-connector {
    position: absolute;
    top: 50%;
    right: -20px;
    width: 40px;
    height: 2px;
    background: linear-gradient(90deg, #1a3a6b, #d4a84b);
    transform: translateY(-50%);
}

.feature-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: white;
    background: linear-gradient(135deg, #1a3a6b, #2b6cb0);
    margin-bottom: 16px;
}

.testimonial-card {
    border-radius: 24px;
    padding: 24px;
    background: linear-gradient(145deg, #ffffff, #f8fbff);
    border: 1px solid rgba(26,58,107,0.08);
    box-shadow: 0 16px 40px rgba(26,58,107,0.08);
}

.avatar-circle {
    width: 54px;
    height: 54px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: white;
    background: linear-gradient(135deg, #1a3a6b, #d4a84b);
}

.cta-section {
    position: relative;
    overflow: hidden;
    border-radius: 32px;
    padding: 70px 24px;
    background: linear-gradient(135deg, #1a3a6b 0%, #0d9e6a 100%);
    color: white;
}

.cta-shape {
    position: absolute;
    border-radius: 50%;
    background: rgba(255,255,255,0.12);
    animation: floatShape 8s ease-in-out infinite;
}

.cta-shape.one { width: 180px; height: 180px; top: -40px; right: -40px; }
.cta-shape.two { width: 130px; height: 130px; bottom: -20px; left: -20px; animation-delay: 2s; }

@keyframes floatShape {
    0%, 100% { transform: translateY(0) scale(1); }
    50% { transform: translateY(-12px) scale(1.04); }
}

@keyframes drift {
    0% { transform: translateY(0) translateX(0); opacity: 0; }
    10% { opacity: .8; }
    100% { transform: translateY(-120px) translateX(40px); opacity: 0; }
}

@media (max-width: 991px) {
    .step-connector { display: none; }
    .hero-section { padding: 60px 0 80px; margin-bottom: 30px; }
}

@media (max-width: 768px) {
    .hero-section { padding: 40px 0 60px; margin-bottom: 20px; }
}
</style>

<!-- ============================================
    HERO SECTION
    ============================================ -->
<div class="hero-section position-relative">
    <div class="hero-particles" aria-hidden="true">
        <span class="hero-particle" style="left:8%; top:22%; animation-delay:0s;">🍽️</span>
        <span class="hero-particle" style="left:16%; top:68%; animation-delay:1.2s;">🥘</span>
        <span class="hero-particle" style="left:84%; top:24%; animation-delay:2.5s;">🍚</span>
        <span class="hero-particle" style="left:74%; top:72%; animation-delay:3.4s;">🍲</span>
    </div>
    <div class="container py-4 position-relative">
        <div class="row align-items-center gy-4">
            <div class="col-lg-7 text-center text-lg-start">
                <div class="stat-pill mb-3"><i class="fas fa-bolt"></i> Live Quetta network</div>
                <h1 class="hero-title" id="typedHeadline">Quetta's Food Rescue Network</h1>
                <div class="hero-subtitle">نیکی روٹ - کوئٹہ</div>
                <p class="hero-copy">Connecting generous donors, volunteer riders, and families in need across Quetta to reduce waste and share meals with dignity.</p>
                <div class="hero-cta mt-4 d-flex flex-wrap justify-content-center justify-content-lg-start gap-3">
                    <a href="<?= BASE_URL ?>login.php" class="btn btn-gold"><i class="fas fa-utensils me-2"></i> I Have Food</a>
                    <a href="<?= BASE_URL ?>register.php" class="btn btn-outline-light"><i class="fas fa-hand-holding-heart me-2"></i> I Need Food</a>
                </div>
                <div class="mt-4 d-flex flex-wrap justify-content-center justify-content-lg-start gap-3">
                    <span class="stat-pill"><i class="fas fa-hands-helping"></i> <span data-counter="<?= $meals ?>">0</span> meals saved</span>
                    <span class="stat-pill"><i class="fas fa-users"></i> <span data-counter="<?= $donors ?>">0</span> donors</span>
                    <span class="stat-pill"><i class="fas fa-user-check"></i> <span data-counter="<?= $recipients ?>">0</span> recipients</span>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="hero-card">
                    <h4 class="fw-bold mb-3">Why this matters</h4>
                    <p class="mb-3 text-white-50">Every rescued meal is a step toward stronger community care in Quetta.</p>
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="section-card p-3 h-100">
                                <div class="display-6 mb-2">📍</div>
                                <div class="fw-bold">Live map</div>
                                <small class="text-muted">Track rescue activity</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="section-card p-3 h-100">
                                <div class="display-6 mb-2">🚴</div>
                                <div class="fw-bold">Volunteer riders</div>
                                <small class="text-muted">Fast delivery network</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="section-card p-3 h-100">
                                <div class="display-6 mb-2">🌿</div>
                                <div class="fw-bold">Zero waste</div>
                                <small class="text-muted">Food reaches those who need it</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="section-card p-3 h-100">
                                <div class="display-6 mb-2">💬</div>
                                <div class="fw-bold">Quick updates</div>
                                <small class="text-muted">WhatsApp ready sharing</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
    STATS SECTION
    ============================================ -->
<div class="container py-4">
    <section class="mb-5">
        <div class="row g-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card-modern text-center">
                    <div class="display-6 mb-2">🍽️</div>
                    <div class="stat-number" data-counter="<?= $meals ?>">0</div>
                    <div class="text-muted">Meals Saved</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card-modern text-center">
                    <div class="display-6 mb-2">🤝</div>
                    <div class="stat-number" data-counter="<?= $donors ?>">0</div>
                    <div class="text-muted">Donors</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card-modern text-center">
                    <div class="display-6 mb-2">🏍️</div>
                    <div class="stat-number" data-counter="<?= $riders ?>">0</div>
                    <div class="text-muted">Riders</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card-modern text-center">
                    <div class="display-6 mb-2">🏠</div>
                    <div class="stat-number" data-counter="<?= $recipients ?>">0</div>
                    <div class="text-muted">Recipients</div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================
    HOW IT WORKS SECTION
    ============================================ -->
    <section class="mb-5">
        <div class="text-center mb-4">
            <h2 class="fw-bold text-primary-dark">How it works</h2>
            <p class="text-muted mb-0">A simple flow that turns surplus food into shared impact.</p>
        </div>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="step-card text-center">
                    <div class="step-connector d-none d-lg-block"></div>
                    <div class="display-4 mb-3">🍽️</div>
                    <h4 class="fw-bold">Restaurants Broadcast</h4>
                    <p class="text-muted mb-0">Wedding halls, restaurants, and supermarkets share surplus food in seconds.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="step-card text-center">
                    <div class="step-connector d-none d-lg-block"></div>
                    <div class="display-4 mb-3">🏍️</div>
                    <h4 class="fw-bold">Riders Deliver</h4>
                    <p class="text-muted mb-0">Volunteers pick up and deliver food quickly and safely across Quetta.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="step-card text-center">
                    <div class="display-4 mb-3">🏠</div>
                    <h4 class="fw-bold">Orphanages Receive</h4>
                    <p class="text-muted mb-0">Families and shelters receive fresh meals with the dignity they deserve.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================
    FEATURES SECTION
    ============================================ -->
    <section class="mb-5">
        <div class="text-center mb-4">
            <h2 class="fw-bold text-primary-dark">Built for impact</h2>
            <p class="text-muted mb-0">Technology and compassion working together for Quetta.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="section-card p-4 h-100">
                    <div class="feature-icon"><i class="fas fa-map-marked-alt"></i></div>
                    <h5 class="fw-bold">Real-time GPS</h5>
                    <p class="text-muted mb-0">Track pickups and deliveries in real time with live location visibility.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="section-card p-4 h-100">
                    <div class="feature-icon"><i class="fas fa-brain"></i></div>
                    <h5 class="fw-bold">Smart Matching</h5>
                    <p class="text-muted mb-0">Connect food availability with nearby recipients through an intelligent flow.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="section-card p-4 h-100">
                    <div class="feature-icon"><i class="fas fa-recycle"></i></div>
                    <h5 class="fw-bold">Zero Waste</h5>
                    <p class="text-muted mb-0">Stop surplus food from going unused by redirecting it to people who need it.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="section-card p-4 h-100">
                    <div class="feature-icon"><i class="fas fa-heart"></i></div>
                    <h5 class="fw-bold">Community Driven</h5>
                    <p class="text-muted mb-0">Built by people who care, for people who care, right here in Quetta.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================
    LIVE IMPACT SECTION
    ============================================ -->
    <section class="mb-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-7">
                <div class="section-card p-4 p-lg-5">
                    <h3 class="fw-bold text-primary-dark mb-3">Live impact</h3>
                    <p class="text-muted">Every rescue request and delivery is visible through the live impact map and feed.</p>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Today's Rescues</span>
                            <span class="fw-bold text-primary-dark"><?= $todayRescues ?></span>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Daily rescue activity</span>
                            <span><?= $percentage ?>%</span>
                        </div>
                        <div class="progress" style="height: 12px; border-radius: 999px;">
                            <div class="progress-bar" style="width: <?= $percentage ?>%; background: linear-gradient(90deg, #1a3a6b, #d4a84b);"></div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <?php if (!empty($recentRescues)): ?>
                            <?php foreach ($recentRescues as $rescue): ?>
                                <div class="d-flex justify-content-between align-items-center border-top py-3">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($rescue['title']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($rescue['recipient_name']) ?></small>
                                    </div>
                                    <span class="badge bg-primary-subtle text-primary"><?= htmlspecialchars(ucfirst($rescue['status'])) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted py-3">No rescues yet. The first one is just around the corner.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="section-card p-4 p-lg-5">
                    <h3 class="fw-bold text-primary-dark mb-3">What people say</h3>
                    <div class="testimonial-card mb-3">
                        <div class="d-flex align-items-center mb-3">
                            <div class="avatar-circle me-3">A</div>
                            <div>
                                <div class="fw-semibold">Ayesha</div>
                                <small class="text-muted">Donor</small>
                            </div>
                        </div>
                        <div class="text-warning mb-2"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i></div>
                        <p class="text-muted mb-0">"It feels incredible to share food that would otherwise go to waste. NekiRot made it so simple."</p>
                    </div>
                    <div class="testimonial-card">
                        <div class="d-flex align-items-center mb-3">
                            <div class="avatar-circle me-3">R</div>
                            <div>
                                <div class="fw-semibold">Rashid</div>
                                <small class="text-muted">Rider</small>
                            </div>
                        </div>
                        <div class="text-warning mb-2"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i></div>
                        <p class="text-muted mb-0">"The experience is smooth, fast, and rewarding. I love being part of this community."</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================
    CALL TO ACTION
    ============================================ -->
    <section class="cta-section text-center">
        <div class="cta-shape one"></div>
        <div class="cta-shape two"></div>
        <div class="position-relative">
            <h2 class="fw-bold mb-3">Join NekiRot Quetta today</h2>
            <p class="mx-auto mb-4" style="max-width: 720px; color: rgba(255,255,255,0.9);">Whether you have food to share or need support, you can help build a more caring Quetta.</p>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <a href="<?= BASE_URL ?>register.php" class="btn btn-gold"><i class="fas fa-user-plus me-2"></i> Register Now</a>
                <a href="<?= BASE_URL ?>map.php" class="btn btn-outline-light"><i class="fas fa-map me-2"></i> Learn More</a>
            </div>
        </div>
    </section>
</div>

<script>
// ============================================
// COUNTER ANIMATION
// ============================================
const counters = document.querySelectorAll('[data-counter]');
const animateCounter = (element) => {
    const target = parseInt(element.getAttribute('data-counter'), 10) || 0;
    const duration = 1200;
    const startTime = performance.now();
    const step = (now) => {
        const progress = Math.min(1, (now - startTime) / duration);
        const value = Math.floor(progress * target);
        element.textContent = value.toLocaleString();
        if (progress < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
        if (entry.isIntersecting) {
            animateCounter(entry.target);
            observer.unobserve(entry.target);
        }
    });
}, { threshold: 0.6 });

counters.forEach((counter) => observer.observe(counter));

// ============================================
// AUTO-REFRESH EVERY 30 SECONDS
// ============================================
setInterval(function() {
    location.reload();
}, 30000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>