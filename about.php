<?php
include_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* --- original theme colors kept: #1a3a6b #2b6cb0 #d4a84b #f3d37b --- */
    .page-hero {
        background: linear-gradient(135deg, #1a3a6b 0%, #2b6cb0 58%, #d4a84b 100%);
        border-radius: 0 0 32px 32px;
        color: white;
        margin: -24px -12px 40px;
        padding: 72px 24px 68px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 20px 35px -10px rgba(26, 58, 107, 0.3);
        animation: heroGlow 6s infinite alternate ease-in-out;
    }
    @keyframes heroGlow {
        0% { box-shadow: 0 18px 30px -10px rgba(43, 108, 176, 0.4); }
        100% { box-shadow: 0 25px 45px -5px rgba(212, 168, 75, 0.5); }
    }
    .page-hero::after {
        content: "";
        position: absolute;
        right: -30px;
        top: -30px;
        width: 180px;
        height: 180px;
        background: rgba(243, 211, 123, 0.15);
        border-radius: 50%;
        animation: floatBubble 10s infinite ease-in-out;
    }
    .page-hero::before {
        content: "";
        position: absolute;
        left: 10%;
        bottom: -40px;
        width: 120px;
        height: 120px;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 50%;
        animation: floatBubble 8s infinite reverse;
    }
    @keyframes floatBubble {
        0% { transform: translateY(0) scale(1); }
        50% { transform: translateY(-20px) scale(1.05); }
        100% { transform: translateY(0) scale(1); }
    }
    .page-hero .eyebrow {
        color: #f3d37b;
        font-size: .8rem;
        font-weight: 800;
        letter-spacing: .16em;
        text-transform: uppercase;
        animation: fadeInDown 0.8s ease-out;
    }
    .page-hero h1 {
        font-weight: 800;
        letter-spacing: -.03em;
        text-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: fadeInUp 0.8s ease-out 0.1s both;
    }
    .page-hero .lead {
        animation: fadeInUp 0.9s ease-out 0.25s both;
    }
    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-15px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .about-card {
        height: 100%;
        padding: 32px 28px;
        border: 1px solid rgba(43, 108, 176, .08);
        border-radius: 24px;
        background: white;
        box-shadow: 0 8px 18px rgba(0, 0, 0, 0.03);
        transition: all 0.4s cubic-bezier(0.2, 0.9, 0.3, 1.1);
        position: relative;
        overflow: hidden;
        backdrop-filter: blur(2px);
    }
    .about-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 22px 30px -12px rgba(43, 108, 176, 0.2);
        border-color: rgba(212, 168, 75, 0.5);
    }
    .about-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, #1a3a6b, #d4a84b, #f3d37b);
        opacity: 0;
        transition: opacity 0.3s;
    }
    .about-card:hover::before {
        opacity: 1;
    }
    .about-icon {
        width: 52px;
        height: 52px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 16px;
        color: #1a3a6b;
        background: rgba(43, 108, 176, 0.08);
        font-size: 1.4rem;
        margin-bottom: 20px;
        transition: all 0.35s;
        box-shadow: 0 4px 10px rgba(0,0,0,0.02);
    }
    .about-card:hover .about-icon {
        background: #2b6cb0;
        color: white;
        transform: rotate(-8deg) scale(1.05);
        box-shadow: 0 12px 18px -6px rgba(43, 108, 176, 0.4);
    }
    .about-card h2 {
        color: #1a3a6b;
        font-size: 1.5rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        transition: color 0.3s;
    }
    .about-card:hover h2 {
        color: #2b6cb0;
    }
    .btn-primary-nekirot {
        background: #1a3a6b;
        border: none;
        padding: 0.8rem 2rem;
        font-weight: 700;
        letter-spacing: 0.3px;
        border-radius: 40px;
        transition: all 0.3s;
        box-shadow: 0 8px 16px -6px rgba(26, 58, 107, 0.3);
    }
    .btn-primary-nekirot:hover {
        background: #2b6cb0;
        transform: scale(1.04);
        box-shadow: 0 14px 20px -8px #d4a84b;
    }
    .btn-primary-nekirot i {
        transition: transform 0.3s;
    }
    .btn-primary-nekirot:hover i {
        transform: translateX(5px);
    }
    /* decorative floating image */
    .about-image-float {
        background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><circle cx="100" cy="100" r="80" fill="%23f3d37b" opacity="0.15"/><path d="M40 120 Q70 70 130 90 Q160 100 170 140" stroke="%232b6cb0" fill="none" stroke-width="8" stroke-linecap="round" opacity="0.5"/><circle cx="70" cy="95" r="12" fill="%231a3a6b" opacity="0.7"/><circle cx="130" cy="105" r="10" fill="%23d4a84b" opacity="0.6"/></svg>');
        background-size: contain;
        background-repeat: no-repeat;
        background-position: center;
        position: absolute;
        top: 15px;
        right: 15px;
        width: 70px;
        height: 70px;
        opacity: 0.8;
        transition: transform 0.7s;
        pointer-events: none;
    }
    .about-card:hover .about-image-float {
        transform: scale(1.2) rotate(12deg);
    }
    .about-card .content-wrap {
        position: relative;
        z-index: 2;
    }
</style>

<section class="page-hero text-center fade-in">
    <div class="eyebrow">Food with a purpose</div>
    <h1 class="display-5 mt-2 mb-3">About NekiRot</h1>
    <p class="lead mb-0 mx-auto" style="max-width: 680px;">A Quetta-based community network turning surplus food into meaningful help for people who need it.</p>
</section>

<section class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="about-card">
            <div class="about-image-float"></div>
            <div class="content-wrap">
                <div class="about-icon"><i class="fas fa-seedling"></i></div>
                <h2>Our mission</h2>
                <p class="text-muted mb-0">NekiRot makes it easier for donors, recipients, and riders to work together. Donors share safe, available food; recipients request support; and riders help move each rescue where it needs to go.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="about-card">
            <div class="about-image-float" style="background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><circle cx="100" cy="100" r="70" fill="%23d4a84b" opacity="0.1"/><path d="M70 130 L100 60 L140 120 L90 150 Z" fill="%232b6cb0" opacity="0.15"/><circle cx="100" cy="105" r="8" fill="%23f3d37b"/></svg>');"></div>
            <div class="content-wrap">
                <div class="about-icon"><i class="fas fa-location-dot"></i></div>
                <h2>Made for Quetta</h2>
                <p class="text-muted mb-0">Our local focus keeps coordination practical, personal, and close to the communities we serve across Quetta.</p>
            </div>
        </div>
    </div>
</section>

<section class="about-card mb-4" style="border-radius: 28px; background: linear-gradient(145deg, #ffffff 0%, #f9fafc 100%);">
    <div class="row g-4 align-items-center">
        <div class="col-md-8">
            <div class="content-wrap">
                <h2>How it works</h2>
                <p class="text-muted mb-0">A listing starts the journey. Matching connects it to a rescue request, a rider carries it through pickup and transit, and tracking keeps everyone informed until delivery.</p>
            </div>
        </div>
        <div class="col-md-4 text-md-end">
            <a href="<?= BASE_URL ?>contact.php" class="btn btn-primary-nekirot"><i class="fas fa-paper-plane me-2"></i>Talk to our team</a>
        </div>
    </div>
    <!-- additional soft decoration -->
    <div style="position:absolute; bottom:-20px; right:10px; opacity:0.2; pointer-events:none;">
        <i class="fas fa-utensils" style="font-size:5rem; color:#2b6cb0;"></i>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>