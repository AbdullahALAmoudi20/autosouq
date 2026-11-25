<?php
/**
 * Shared page foot. Closes <main>, prints the site footer and loads the
 * scripts. Scripts are at the bottom so the page paints before they run.
 *
 * @author  Ahmed Muaidi (Front-End and Accessibility)
 * @file    app/includes/footer.php
 * @task    Task 2, Task 16
 */
declare(strict_types=1);
?>
</main><!-- /#main-content -->

<footer class="site-footer">
    <div class="container footer-grid">

        <section class="footer-block">
            <h2 class="footer-heading"><?= e(SITE_NAME) ?></h2>
            <p><?= e(SITE_TAGLINE) ?></p>
            <p class="footer-note">
                Authorised agent for Toyota, Ford, Chevrolet, Nissan, Hyundai and BMW.
            </p>
        </section>

        <section class="footer-block">
            <h2 class="footer-heading">Visit the showroom</h2>
            <address>
                <?= e(SHOP_ADDRESS) ?><br>
                <a href="tel:<?= e(str_replace(' ', '', SHOP_PHONE)) ?>"><?= e(SHOP_PHONE) ?></a><br>
                <a href="mailto:<?= e(SHOP_EMAIL) ?>"><?= e(SHOP_EMAIL) ?></a>
            </address>
        </section>

        <section class="footer-block">
            <h2 class="footer-heading">Opening hours</h2>
            <dl class="hours">
                <dt>Saturday to Thursday</dt><dd>09:00 - 21:00</dd>
                <dt>Friday</dt><dd>16:00 - 21:00</dd>
            </dl>
        </section>

        <nav class="footer-block" aria-label="Footer navigation">
            <h2 class="footer-heading">Pages</h2>
            <ul class="footer-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="products.php">Showroom</a></li>
                <li><a href="checkout.php">Your cart</a></li>
                <li><a href="contact.php">Contact us</a></li>
                <li><a href="privacy.php">Privacy policy</a></li>
                <li><a href="admin_login.php">Manager sign in</a></li>
            </ul>
        </nav>

    </div>

    <p class="copyright">
        CIS209 Web-Based Systems student project. Car photos are from Wikimedia Commons
        under Creative Commons licences and are credited on each car page. Brand logos
        are trademarks of their manufacturers. Recall data from NHTSA.
    </p>
</footer>

<?php if (!is_admin_page()): ?>
    <!-- AI assistant (RAG). js/assistant.js builds the chat window inside this
         element and posts questions to assistant_api.php with the CSRF token. -->
    <div id="chat-root" class="chat-root"
         data-endpoint="assistant_api.php"
         data-csrf="<?= e(csrf_token()) ?>"></div>
<?php endif; ?>

<script src="<?= e(asset('js/validation.js')) ?>" defer></script>
<script src="<?= e(asset('js/site.js')) ?>" defer></script>
<?php if (!is_admin_page()): ?>
    <script src="<?= e(asset('js/assistant.js')) ?>" defer></script>
    <!-- "Compare" buttons on every car card, and the compare tray -->
    <script src="<?= e(asset('js/compare.js')) ?>" defer></script>
<?php endif; ?>
<?php if (is_page('product_details.php')): ?>
    <!-- Monthly installment estimate on the car page -->
    <script src="<?= e(asset('js/finance.js')) ?>" defer></script>
<?php endif; ?>
<?php if (is_page('product_details.php')): ?>
    <!-- Task 14: the help pop-up only exists on the product details page -->
    <script src="<?= e(asset('js/help.js')) ?>" defer></script>
    <!-- Photo thumbnails and the full-screen viewer -->
    <script src="<?= e(asset('js/gallery.js')) ?>" defer></script>
<?php endif; ?>
<?php if (is_page('admin_manage.php')): ?>
    <!-- Live preview of the picture framing and the gallery uploads -->
    <script src="<?= e(asset('js/admin-images.js')) ?>" defer></script>
<?php endif; ?>
<?php if (is_page('admin_dashboard.php')): ?>
    <!-- Chart.js 4.4.1, stored locally so the dashboard works offline -->
    <script src="<?= e(asset('js/vendor/chart.umd.min.js')) ?>" defer></script>
    <script src="<?= e(asset('js/dashboard-charts.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
