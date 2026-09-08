<!-- JAVASCRIPTS -->
<script src="assets/libs/tiny-slider/min/tiny-slider.js"></script>
<script src="assets/libs/choices.js/public/assets/scripts/choices.min.js"></script>
<script src="assets/js/plugins.init.js"></script>
<script src="assets/js/app.js"></script>
<!-- JAVASCRIPTS -->

<?php if (basename($_SERVER['PHP_SELF']) === 'contact.php'): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <script src="assets/js/contact.js"></script>
<?php endif; ?>

<?php if (basename($_SERVER['PHP_SELF']) === 'subscribe.php'): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <script src="assets/js/subscribe.js"></script>
<?php endif; ?>

<?php if (basename($_SERVER['PHP_SELF']) === 'partners.php'): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <script src="assets/js/partner.js"></script>
<?php endif; ?>

<?php if (basename($_SERVER['PHP_SELF']) === 'index.php' || basename($_SERVER['PHP_SELF']) === 'job-grid.php'): ?>
    <script src="assets/js/job-seacher.js"></script>
<?php endif; ?>