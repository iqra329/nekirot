<?php
include_once __DIR__ . '/config/config.php';

$contactSuccess = null;
$contactError = null;
$formValues = [
    'name' => trim($_POST['name'] ?? ''),
    'email' => trim($_POST['email'] ?? ''),
    'subject' => trim($_POST['subject'] ?? ''),
    'message' => trim($_POST['message'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $honeypot = trim($_POST['website'] ?? '');
    if ($honeypot !== '') {
        $contactError = 'Unable to send your message. Please try again.';
    } elseif (!$formValues['name'] || !$formValues['email'] || !$formValues['message']) {
        $contactError = 'Please complete your name, email address, and message.';
    } elseif (!filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
        $contactError = 'Please enter a valid email address.';
    } elseif (strlen($formValues['name']) > 100 || strlen($formValues['subject']) > 150 || strlen($formValues['message']) > 5000) {
        $contactError = 'Please keep your message within the allowed length.';
    } else {
        // For local development - save to file
        $mailSubject = $formValues['subject'] ?: 'New NekiRot contact message';
        $mailBody = "===================================\n";
        $mailBody .= "New Contact Message\n";
        $mailBody .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $mailBody .= "===================================\n";
        $mailBody .= "Name: {$formValues['name']}\n";
        $mailBody .= "Email: {$formValues['email']}\n";
        $mailBody .= "Subject: {$mailSubject}\n";
        $mailBody .= "-----------------------------------\n";
        $mailBody .= "Message:\n{$formValues['message']}\n";
        $mailBody .= "===================================\n\n";
        
        // Create logs directory if it doesn't exist
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Save contact message to log file
        $logFile = $logDir . '/contact_messages.txt';
        if (file_put_contents($logFile, $mailBody, FILE_APPEND | LOCK_EX)) {
            $contactSuccess = 'Thanks for reaching out! Your message has been received. We\'ll get back to you soon.';
            $formValues = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
        } else {
            $contactError = 'We could not process your message right now. Please try again later.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Contact Page Styles */
    .contact-wrapper {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 50%, #f0f4f8 100%);
        margin: -24px -12px;
        padding: 40px 24px;
        border-radius: 24px;
    }
    
    .contact-hero {
        background: linear-gradient(135deg, #1a3a6b 0%, #2b6cb0 58%, #d4a84b 100%);
        border-radius: 20px;
        color: white;
        padding: 40px;
        margin-bottom: 40px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 15px 35px -10px rgba(26, 58, 107, 0.3);
    }
    
    .contact-hero::before {
        content: "";
        position: absolute;
        right: -50px;
        top: -50px;
        width: 200px;
        height: 200px;
        background: rgba(243, 211, 123, 0.15);
        border-radius: 50%;
        animation: floatBubble 8s infinite ease-in-out;
    }
    
    .contact-hero::after {
        content: "";
        position: absolute;
        left: -30px;
        bottom: -30px;
        width: 150px;
        height: 150px;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 50%;
        animation: floatBubble 10s infinite reverse;
    }
    
    @keyframes floatBubble {
        0% { transform: translateY(0) scale(1); }
        50% { transform: translateY(-20px) scale(1.1); }
        100% { transform: translateY(0) scale(1); }
    }
    
    .contact-hero h1 {
        font-weight: 800;
        letter-spacing: -0.03em;
        position: relative;
        z-index: 2;
        animation: fadeInUp 0.8s ease-out;
    }
    
    .contact-hero p {
        position: relative;
        z-index: 2;
        animation: fadeInUp 0.9s ease-out 0.2s both;
    }
    
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .contact-panel {
        padding: 35px;
        border-radius: 20px;
        background: white;
        border: 1px solid rgba(43, 108, 176, 0.1);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
        transition: all 0.4s cubic-bezier(0.2, 0.9, 0.3, 1.1);
        position: relative;
        overflow: hidden;
    }
    
    .contact-panel:hover {
        box-shadow: 0 20px 40px -10px rgba(43, 108, 176, 0.15);
        transform: translateY(-5px);
    }
    
    .contact-panel::before {
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
    
    .contact-panel:hover::before {
        opacity: 1;
    }
    
    .contact-detail {
        display: flex;
        gap: 18px;
        align-items: flex-start;
        margin-bottom: 28px;
        padding: 15px;
        border-radius: 12px;
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .contact-detail:hover {
        background: rgba(43, 108, 176, 0.05);
        transform: translateX(5px);
    }
    
    .contact-icon {
        width: 45px;
        height: 45px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        background: rgba(212, 168, 75, 0.15);
        color: #d4a84b;
        font-size: 1.2rem;
        transition: all 0.3s;
    }
    
    .contact-detail:hover .contact-icon {
        background: #d4a84b;
        color: white;
        transform: rotate(-8deg) scale(1.1);
    }
    
    .contact-detail strong {
        color: #1a3a6b;
        display: block;
        font-size: 1.1rem;
        margin-bottom: 4px;
    }
    
    .contact-detail span {
        color: #6c757d;
        font-size: 0.92rem;
    }
    
    .form-control {
        border-radius: 12px;
        border: 2px solid #e9ecef;
        padding: 14px 16px;
        transition: all 0.3s;
        font-size: 0.95rem;
    }
    
    .form-control:focus {
        border-color: #2b6cb0;
        box-shadow: 0 0 0 0.2rem rgba(43, 108, 176, 0.12);
        transform: translateY(-2px);
    }
    
    .form-label {
        font-weight: 600;
        color: #1a3a6b;
        margin-bottom: 8px;
    }
    
    .btn-send {
        background: linear-gradient(135deg, #1a3a6b 0%, #2b6cb0 100%);
        color: white;
        padding: 14px 35px;
        border: none;
        border-radius: 50px;
        font-weight: 700;
        letter-spacing: 0.5px;
        transition: all 0.3s;
        box-shadow: 0 8px 20px -5px rgba(26, 58, 107, 0.4);
        position: relative;
        overflow: hidden;
    }
    
    .btn-send:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 30px -8px rgba(43, 108, 176, 0.5);
        background: linear-gradient(135deg, #2b6cb0 0%, #1a3a6b 100%);
    }
    
    .btn-send:active {
        transform: translateY(-1px);
    }
    
    .btn-send i {
        transition: transform 0.3s;
    }
    
    .btn-send:hover i {
        transform: translateX(5px);
    }
    
    .alert-success {
        background: #d4edda;
        border: 2px solid #c3e6cb;
        color: #155724;
        border-radius: 12px;
        padding: 15px 20px;
        animation: slideDown 0.5s ease-out;
    }
    
    .alert-danger {
        background: #f8d7da;
        border: 2px solid #f5c6cb;
        color: #721c24;
        border-radius: 12px;
        padding: 15px 20px;
        animation: slideDown 0.5s ease-out;
    }
    
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .website-field {
        position: absolute;
        left: -9999px;
    }
    
    .quick-links {
        display: flex;
        gap: 15px;
        margin-top: 25px;
        flex-wrap: wrap;
    }
    
    .quick-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        border-radius: 25px;
        background: rgba(43, 108, 176, 0.08);
        color: #2b6cb0;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.3s;
    }
    
    .quick-link:hover {
        background: #2b6cb0;
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 8px 15px -5px rgba(43, 108, 176, 0.3);
    }
    
    /* Decorative elements */
    .decorative-circle {
        position: absolute;
        border-radius: 50%;
        pointer-events: none;
    }
    
    .circle-1 {
        width: 100px;
        height: 100px;
        background: rgba(212, 168, 75, 0.08);
        top: -30px;
        right: -30px;
        animation: floatBubble 7s infinite;
    }
    
    .circle-2 {
        width: 70px;
        height: 70px;
        background: rgba(43, 108, 176, 0.06);
        bottom: 20px;
        left: -20px;
        animation: floatBubble 9s infinite reverse;
    }
</style>

<div class="contact-wrapper">
    <section class="contact-hero text-center fade-in">
        <div class="eyebrow" style="color: #f3d37b; font-size: 0.8rem; font-weight: 800; letter-spacing: 0.16em; text-transform: uppercase; margin-bottom: 10px;">
            <i class="fas fa-envelope-open-text me-2"></i>Get in Touch
        </div>
        <h1 class="display-5 mb-3">Let's Connect & Help Together</h1>
        <p class="lead mb-0 mx-auto" style="max-width: 600px; opacity: 0.95;">
            Have questions about food rescue, partnerships, or volunteering? We'd love to hear from you!
        </p>
    </section>

    <div class="row g-4 align-items-stretch">
        <!-- Left Column - Contact Info -->
        <div class="col-lg-5">
            <div class="contact-panel h-100 position-relative">
                <div class="decorative-circle circle-1"></div>
                <div class="decorative-circle circle-2"></div>
                
                <h2 class="h4 fw-bold mb-4" style="color: #1a3a6b; position: relative; z-index: 2;">
                    <i class="fas fa-info-circle me-2" style="color: #d4a84b;"></i>Contact Information
                </h2>
                
                <div class="contact-detail">
                    <div class="contact-icon"><i class="fas fa-location-dot"></i></div>
                    <div>
                        <strong>Visit Our Community</strong>
                        <span>Main Branch: Jinnah Road, Quetta<br>Pakistan</span>
                    </div>
                </div>
                
                <div class="contact-detail">
                    <div class="contact-icon"><i class="fas fa-phone-alt"></i></div>
                    <div>
                        <strong>Call Us</strong>
                        <span>+92 81 1234567<br>Mon-Sat: 9:00 AM - 6:00 PM</span>
                    </div>
                </div>
                
                <div class="contact-detail">
                    <div class="contact-icon"><i class="fas fa-envelope"></i></div>
                    <div>
                        <strong>Email Us</strong>
                        <span>info@nekirot.com<br>support@nekirot.com</span>
                    </div>
                </div>
                
                <div class="quick-links" style="position: relative; z-index: 2;">
                    <a href="#" class="quick-link"><i class="fab fa-whatsapp"></i>WhatsApp</a>
                    <a href="#" class="quick-link"><i class="fab fa-facebook"></i>Facebook</a>
                    <a href="#" class="quick-link"><i class="fab fa-instagram"></i>Instagram</a>
                </div>
            </div>
        </div>
        
        <!-- Right Column - Contact Form -->
        <div class="col-lg-7">
            <div class="contact-panel position-relative">
                <div class="decorative-circle circle-1" style="top: auto; bottom: -40px; right: -40px;"></div>
                
                <h2 class="h4 fw-bold mb-4" style="color: #1a3a6b;">
                    <i class="fas fa-paper-plane me-2" style="color: #d4a84b;"></i>Send Us a Message
                </h2>
                
                <?php if ($contactSuccess): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($contactSuccess) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($contactError): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($contactError) ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" action="<?= BASE_URL ?>contact.php" novalidate>
                    <!-- Honeypot field to catch bots -->
                    <div class="website-field" aria-hidden="true">
                        <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="contact-name">
                                <i class="fas fa-user me-1"></i>Your Name
                            </label>
                            <input id="contact-name" name="name" type="text" class="form-control" 
                                   value="<?= htmlspecialchars($formValues['name']) ?>" maxlength="100" 
                                   placeholder="Enter your full name" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label" for="contact-email">
                                <i class="fas fa-envelope me-1"></i>Email Address
                            </label>
                            <input id="contact-email" name="email" type="email" class="form-control" 
                                   value="<?= htmlspecialchars($formValues['email']) ?>" maxlength="190" 
                                   placeholder="your@email.com" required>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label" for="contact-subject">
                                <i class="fas fa-tag me-1"></i>Subject <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <input id="contact-subject" name="subject" type="text" class="form-control" 
                                   value="<?= htmlspecialchars($formValues['subject']) ?>" maxlength="150" 
                                   placeholder="What's this about?">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label" for="contact-message">
                                <i class="fas fa-comment-dots me-1"></i>Your Message
                            </label>
                            <textarea id="contact-message" name="message" class="form-control" 
                                      maxlength="5000" placeholder="Write your message here..." required><?= htmlspecialchars($formValues['message']) ?></textarea>
                        </div>
                        
                        <div class="col-12 mt-4">
                            <button type="submit" class="btn btn-send btn-lg">
                                <i class="fas fa-paper-plane me-2"></i>Send Message
                            </button>
                            <span class="ms-3 text-muted small">
                                <i class="fas fa-lock me-1"></i>Your information is safe with us
                            </span>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bottom Info Bar -->
    <div class="text-center mt-5 pt-4">
        <p class="text-muted mb-0">
            <i class="fas fa-hand-holding-heart me-2" style="color: #d4a84b;"></i>
            Thank you for supporting NekiRot's mission to reduce food waste and help those in need
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>