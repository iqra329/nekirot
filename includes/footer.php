</div>
</main>

<!-- Floating WhatsApp Button -->
<a href="https://wa.me/923001234567?text=Hi!%20I%20want%20to%20know%20more%20about%20NekiRot%20Quetta"
   target="_blank"
   class="btn-whatsapp"
   style="position: fixed; bottom: 30px; right: 30px; z-index: 9999; width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; box-shadow: 0 4px 20px rgba(37, 211, 102, 0.4);">
    <i class="fab fa-whatsapp"></i>
</a>

<style>
    /* ============================================
       FOOTER - MATCHING HERO GRADIENT
       Blue to Gold Gradient
       ============================================ */
    .footer-nekirot {
        margin-top: 60px;
        padding: 50px 0 20px;
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, #1a3a6b 0%, #2b6cb0 45%, #d4a84b 100%);
        border-radius: 32px 32px 0 0;
        box-shadow: 0 -20px 60px rgba(26, 58, 107, 0.2);
        color: white;
    }

    .footer-nekirot .deco-circle {
        position: absolute;
        border-radius: 50%;
        pointer-events: none;
        opacity: 0.1;
    }
    .footer-nekirot .deco-one {
        width: 250px;
        height: 250px;
        background: white;
        top: -100px;
        right: -60px;
    }
    .footer-nekirot .deco-two {
        width: 180px;
        height: 180px;
        background: white;
        bottom: -60px;
        left: -50px;
    }

    .footer-nekirot::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #d4a84b, #f3d37b, #d4a84b);
        background-size: 200% 100%;
        animation: footer-gold-slide 4s linear infinite;
        z-index: 2;
    }

    @keyframes footer-gold-slide {
        0% { background-position: 0% 0%; }
        100% { background-position: 200% 0%; }
    }

    .footer-nekirot .container {
        position: relative;
        z-index: 2;
    }

    /* Brand */
    .footer-nekirot .brand-heading {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }
    .footer-nekirot .brand-heading h5 {
        margin: 0;
        font-weight: 800;
        color: white;
        font-size: 1.8rem;  /* BIGGER NAME */
        letter-spacing: -0.02em;
        text-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    .footer-nekirot .brand-heading i {
        color: #f3d37b;
        font-size: 1.8rem;  /* BIGGER ICON TOO */
    }
    .footer-nekirot .brand-desc {
        color: rgba(255,255,255,0.85);
        font-size: 0.95rem;
        line-height: 1.7;
        margin-bottom: 15px;
        font-weight: 500;
    }
    .footer-nekirot .location-text {
        font-size: 0.9rem;
        color: rgba(255,255,255,0.8);
        font-weight: 500;
    }
    .footer-nekirot .location-text i {
        color: #f3d37b;
    }

    /* Section Headings */
    .footer-nekirot .footer-title {
        font-size: 0.95rem;
        font-weight: 800;
        color: #f3d37b;
        margin-bottom: 15px;
        position: relative;
        padding-bottom: 8px;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }
    .footer-nekirot .footer-title::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 22px;
        height: 3px;
        background: #f3d37b;
        border-radius: 2px;
        transition: width 0.3s ease;
    }
    .footer-nekirot .footer-col:hover .footer-title::after {
        width: 40px;
    }

    /* Links */
    .footer-nekirot .footer-links {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .footer-nekirot .footer-links li {
        margin-bottom: 9px;
    }
    .footer-nekirot .footer-links li a {
        color: rgba(255,255,255,0.8);
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 500;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .footer-nekirot .footer-links li a i {
        font-size: 10px;
        color: #f3d37b;
        transition: transform 0.3s ease;
    }
    .footer-nekirot .footer-links li a:hover {
        color: #f3d37b;
        transform: translateX(5px);
        font-weight: 700;
    }

    /* Contact */
    .footer-nekirot .contact-info {
        font-size: 0.9rem;
        color: rgba(255,255,255,0.85);
        font-weight: 500;
        line-height: 2;
    }
    .footer-nekirot .contact-info i {
        color: #f3d37b;
        margin-right: 6px;
    }

    /* Social Icons */
    .footer-nekirot .social-row {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }
    .footer-nekirot .social-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        border: 1px solid rgba(255,255,255,0.3);
        background: rgba(255,255,255,0.1);
        backdrop-filter: blur(5px);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 0.9rem;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    .footer-nekirot .social-icon:hover {
        background: #f3d37b;
        color: #1a3a6b;
        transform: translateY(-4px);
        box-shadow: 0 8px 20px rgba(243, 211, 123, 0.35);
        border-color: transparent;
    }

    /* Divider */
    .footer-nekirot .footer-divider {
        border: none;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
        margin: 20px 0;
    }

    /* Bottom */
    .footer-nekirot .bottom-text {
        color: rgba(255,255,255,0.85);
        font-size: 0.9rem;
        text-align: center;
        font-weight: 600;
    }
    .footer-nekirot .bottom-text .heart {
        color: #fc8181;
        display: inline-block;
        animation: heart-beat 1.5s infinite;
    }
    @keyframes heart-beat {
        0%, 100% { transform: scale(1); }
        25% { transform: scale(1.25); }
        50% { transform: scale(1); }
        75% { transform: scale(1.15); }
    }
    .footer-nekirot .bottom-text small {
        color: rgba(255,255,255,0.7);
        font-weight: 500;
    }

    @media (max-width: 768px) {
        .footer-nekirot {
            margin-top: 40px;
            padding: 35px 0 15px;
            border-radius: 20px 20px 0 0;
        }
        .footer-nekirot .brand-heading h5 {
            font-size: 1.5rem;
        }
    }
</style>

<footer class="footer-nekirot">
    <div class="deco-circle deco-one"></div>
    <div class="deco-circle deco-two"></div>

    <div class="container">
        <div class="row">
            <!-- Brand -->
            <div class="col-md-4 mb-4 mb-md-0 footer-col">
                <div class="brand-heading">
                    <i class="fas fa-leaf"></i>
                    <h5>NekiRot Quetta</h5>
                </div>
                <p class="brand-desc">Connecting food donors to those in need in Quetta.</p>
                <p class="location-text">
                    <i class="fas fa-map-marker-alt"></i> Quetta, Pakistan
                </p>
            </div>

            <!-- Quick Links -->
            <div class="col-md-4 mb-4 mb-md-0 footer-col">
                <h6 class="footer-title">Quick Links</h6>
                <ul class="footer-links">
                    <li><a href="<?= BASE_URL ?>"><i class="fas fa-chevron-right"></i> Home</a></li>
                    <li><a href="<?= BASE_URL ?>map.php"><i class="fas fa-chevron-right"></i> Live Map</a></li>
                    <li><a href="<?= BASE_URL ?>leaderboard.php"><i class="fas fa-chevron-right"></i> Leaderboard</a></li>
                    <li><a href="<?= BASE_URL ?>about.php"><i class="fas fa-chevron-right"></i> About NekiRot</a></li>
                    <li><a href="<?= BASE_URL ?>contact.php"><i class="fas fa-chevron-right"></i> Contact Us</a></li>
                </ul>
            </div>

            <!-- Connect -->
            <div class="col-md-4 footer-col">
                <h6 class="footer-title">Connect With Us</h6>
                <p class="contact-info">
                    <i class="fas fa-phone"></i> 081-1234567<br>
                    <i class="fas fa-envelope"></i> info@nekirot.com
                </p>
                <div class="social-row">
                    <a href="#" class="social-icon"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="social-icon"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="social-icon"><i class="fab fa-instagram"></i></a>
                    <a href="https://wa.me/923001234567" target="_blank" class="social-icon"><i class="fab fa-whatsapp"></i></a>
                </div>
            </div>
        </div>

        <hr class="footer-divider">

        <div class="bottom-text">
            <span class="heart"><i class="fas fa-heart"></i></span> Made for Quetta. Built with ❤️
            <br>
            <small>© <?= date('Y') ?> NekiRot Quetta. All rights reserved.</small>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/app.js"></script>
<script>
    if (window.dashboardAutoRefreshInterval > 0 && !window.dashboardAutoRefreshInit) {
        window.dashboardAutoRefreshInit = true;
        setInterval(function() {
            window.location.reload();
        }, window.dashboardAutoRefreshInterval);
    }
</script>
<?php if (isset($includeMap) && $includeMap): ?>
<script src='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js'></script>
<link href='https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css' rel='stylesheet' />
<?php endif; ?>
</body>
</html>